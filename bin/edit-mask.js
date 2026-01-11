/**
 * Mask Editing Tool for HP Abilities
 * 
 * Allows the agent to apply targeted pixel edits to a mask image.
 * Uses colors from the ORIGINAL source image to preserve product colors.
 * Smart detection: only fills pixels where the original has PRODUCT (not background).
 * 
 * Usage:
 *   node bin/edit-mask.js --mask mask.png --original source.png --left-edge 222 --from-row 200 --to-row 350
 *   node bin/edit-mask.js --mask mask.png --original source.png --right-edge 420 --from-row 100 --to-row 400 --blend-zone 8
 *   node bin/edit-mask.js --mask mask.png --original source.png --fill-rect --x1 45 --y1 200 --x2 50 --y2 350
 * 
 * Parameters:
 *   --mask          The mask/cutout image to edit
 *   --original      The original source image (for color sampling and bg detection)
 *   --blend-zone    Extra pixels to overwrite beyond edge (default: 5)
 *   --bg-threshold  Color distance threshold for background detection (default: 50)
 * 
 * Operations:
 *   --left-edge X    Set left edge to X for rows from-row to to-row
 *   --right-edge X   Set right edge to X for rows from-row to to-row
 *   --fill-rect      Fill a rectangle with original colors
 *   --clear-rect     Clear a rectangle (make transparent)
 * 
 * The mask is edited in-place (overwritten).
 */

const fs = require('fs');
const path = require('path');
const sharp = require('sharp');

async function editMask() {
    const args = process.argv.slice(2);
    const params = {};
    
    for (let i = 0; i < args.length; i++) {
        const arg = args[i];
        if (arg.startsWith('--')) {
            const key = arg.replace('--', '').replace(/-/g, '_');
            if (args[i + 1] && !args[i + 1].startsWith('--')) {
                params[key] = args[i + 1];
                i++;
            } else {
                params[key] = true;
            }
        }
    }

    const { mask, original, left_edge, right_edge, from_row, to_row, fill_rect, clear_rect, x1, y1, x2, y2, blend_zone, bg_threshold } = params;
    const blendZone = parseInt(blend_zone, 10) || 5;
    const bgThreshold = parseInt(bg_threshold, 10) || 50;

    if (!mask) {
        console.error(JSON.stringify({ success: false, error: 'Missing --mask parameter' }));
        process.exit(1);
    }

    if (!fs.existsSync(mask)) {
        console.error(JSON.stringify({ success: false, error: `Mask file not found: ${mask}` }));
        process.exit(1);
    }

    // Original is required for color-preserving operations
    let origData = null;
    let hasOriginal = false;
    
    if (original && fs.existsSync(original)) {
        hasOriginal = true;
    } else if (original) {
        console.error(`Warning: Original file not found: ${original}, using white fill`);
    }

    try {
        // Load mask as raw RGBA
        const { data, info } = await sharp(mask)
            .ensureAlpha()
            .raw()
            .toBuffer({ resolveWithObject: true });

        const { width, height } = info;
        
        // Load original image for color sampling if available
        if (hasOriginal) {
            const origResult = await sharp(original)
                .ensureAlpha()
                .raw()
                .toBuffer({ resolveWithObject: true });
            
            // Verify dimensions match
            if (origResult.info.width !== width || origResult.info.height !== height) {
                console.error(`Warning: Original dimensions (${origResult.info.width}x${origResult.info.height}) don't match mask (${width}x${height})`);
                hasOriginal = false;
            } else {
                origData = origResult.data;
            }
        }

        let pixelsChanged = 0;
        let pixelsSkipped = 0;

        // Detect background color from corners of the original
        let bgColor = { r: 230, g: 230, b: 230 }; // Default light gray
        if (hasOriginal && origData) {
            // Sample corners (10x10 area from each corner)
            const samples = [];
            const sampleSize = 10;
            
            // Top-left
            for (let y = 0; y < sampleSize; y++) {
                for (let x = 0; x < sampleSize; x++) {
                    const idx = (y * width + x) * 4;
                    samples.push({ r: origData[idx], g: origData[idx + 1], b: origData[idx + 2] });
                }
            }
            // Top-right
            for (let y = 0; y < sampleSize; y++) {
                for (let x = width - sampleSize; x < width; x++) {
                    const idx = (y * width + x) * 4;
                    samples.push({ r: origData[idx], g: origData[idx + 1], b: origData[idx + 2] });
                }
            }
            
            // Average the samples
            const avgR = Math.round(samples.reduce((s, c) => s + c.r, 0) / samples.length);
            const avgG = Math.round(samples.reduce((s, c) => s + c.g, 0) / samples.length);
            const avgB = Math.round(samples.reduce((s, c) => s + c.b, 0) / samples.length);
            bgColor = { r: avgR, g: avgG, b: avgB };
        }

        console.error(`Mask: ${width}x${height}, Blend zone: ${blendZone}px, Original: ${hasOriginal ? 'yes' : 'no'}`);
        console.error(`Background color detected: RGB(${bgColor.r}, ${bgColor.g}, ${bgColor.b}), threshold: ${bgThreshold}`);

        /**
         * Calculate color distance between two RGB colors
         */
        function colorDistance(r1, g1, b1, r2, g2, b2) {
            return Math.sqrt(
                Math.pow(r1 - r2, 2) +
                Math.pow(g1 - g2, 2) +
                Math.pow(b1 - b2, 2)
            );
        }

        /**
         * Check if a pixel in the original is background (not product)
         */
        function isBackground(x, y) {
            if (!hasOriginal || !origData) return false;
            const idx = (y * width + x) * 4;
            const r = origData[idx];
            const g = origData[idx + 1];
            const b = origData[idx + 2];
            const dist = colorDistance(r, g, b, bgColor.r, bgColor.g, bgColor.b);
            return dist < bgThreshold;
        }

        /**
         * Fill a pixel with original colors or white fallback
         * Returns true if filled, false if skipped (was background)
         */
        function fillPixel(x, y) {
            // Skip if this is background in the original
            if (isBackground(x, y)) {
                return false;
            }

            const idx = (y * width + x) * 4;
            if (hasOriginal && origData) {
                // Use original image colors
                data[idx] = origData[idx];         // R from original
                data[idx + 1] = origData[idx + 1]; // G from original
                data[idx + 2] = origData[idx + 2]; // B from original
            } else {
                // Fallback to white
                data[idx] = 255;
                data[idx + 1] = 255;
                data[idx + 2] = 255;
            }
            data[idx + 3] = 255; // Fully opaque
            return true;
        }

        // Left edge operation
        if (left_edge !== undefined) {
            const targetX = parseInt(left_edge, 10);
            const startRow = parseInt(from_row, 10) || 0;
            const endRow = parseInt(to_row, 10) || height - 1;

            console.error(`Setting left edge to x=${targetX} for rows ${startRow}-${endRow} (with ${blendZone}px blend zone)`);

            for (let y = startRow; y <= endRow && y < height; y++) {
                // Find current left edge (first pixel with any alpha)
                let currentLeft = -1;
                for (let x = 0; x < width; x++) {
                    if (data[(y * width + x) * 4 + 3] > 0) {
                        currentLeft = x;
                        break;
                    }
                }

                // If current left is to the right of target, fill in the gap + blend zone
                if (currentLeft > targetX) {
                    // Fill from target to currentLeft + blendZone (overwrite blended edge pixels too)
                    const fillEnd = Math.min(currentLeft + blendZone, width - 1);
                    for (let x = targetX; x <= fillEnd; x++) {
                        if (fillPixel(x, y)) {
                            pixelsChanged++;
                        } else {
                            pixelsSkipped++;
                        }
                    }
                }
                // If current left is to the left of target, clear pixels up to target
                else if (currentLeft >= 0 && currentLeft < targetX) {
                    for (let x = currentLeft; x < targetX; x++) {
                        const idx = (y * width + x) * 4;
                        data[idx + 3] = 0; // Make transparent
                        pixelsChanged++;
                    }
                }
            }
        }

        // Right edge operation
        if (right_edge !== undefined) {
            const targetX = parseInt(right_edge, 10);
            const startRow = parseInt(from_row, 10) || 0;
            const endRow = parseInt(to_row, 10) || height - 1;

            console.error(`Setting right edge to x=${targetX} for rows ${startRow}-${endRow} (with ${blendZone}px blend zone)`);

            for (let y = startRow; y <= endRow && y < height; y++) {
                // Find current right edge
                let currentRight = -1;
                for (let x = width - 1; x >= 0; x--) {
                    if (data[(y * width + x) * 4 + 3] > 0) {
                        currentRight = x;
                        break;
                    }
                }

                // If current right is to the left of target, fill in the gap + blend zone
                if (currentRight >= 0 && currentRight < targetX) {
                    // Fill from currentRight - blendZone to target
                    const fillStart = Math.max(currentRight - blendZone, 0);
                    for (let x = fillStart; x <= targetX && x < width; x++) {
                        if (fillPixel(x, y)) {
                            pixelsChanged++;
                        } else {
                            pixelsSkipped++;
                        }
                    }
                }
                // If current right is to the right of target, clear pixels
                else if (currentRight > targetX) {
                    for (let x = targetX + 1; x <= currentRight; x++) {
                        const idx = (y * width + x) * 4;
                        data[idx + 3] = 0; // Make transparent
                        pixelsChanged++;
                    }
                }
            }
        }

        // Fill rectangle operation
        if (fill_rect) {
            const rectX1 = parseInt(x1, 10);
            const rectY1 = parseInt(y1, 10);
            const rectX2 = parseInt(x2, 10);
            const rectY2 = parseInt(y2, 10);

            console.error(`Filling rectangle (${rectX1},${rectY1}) to (${rectX2},${rectY2}) with original colors`);

            for (let y = rectY1; y <= rectY2 && y < height; y++) {
                for (let x = rectX1; x <= rectX2 && x < width; x++) {
                    if (x >= 0 && y >= 0) {
                        if (fillPixel(x, y)) {
                            pixelsChanged++;
                        } else {
                            pixelsSkipped++;
                        }
                    }
                }
            }
        }

        // Clear rectangle operation
        if (clear_rect) {
            const rectX1 = parseInt(x1, 10);
            const rectY1 = parseInt(y1, 10);
            const rectX2 = parseInt(x2, 10);
            const rectY2 = parseInt(y2, 10);

            console.error(`Clearing rectangle (${rectX1},${rectY1}) to (${rectX2},${rectY2})`);

            for (let y = rectY1; y <= rectY2 && y < height; y++) {
                for (let x = rectX1; x <= rectX2 && x < width; x++) {
                    if (x >= 0 && y >= 0) {
                        const idx = (y * width + x) * 4;
                        data[idx + 3] = 0; // Make transparent
                        pixelsChanged++;
                    }
                }
            }
        }

        // Save the edited mask
        await sharp(data, {
            raw: { width, height, channels: 4 }
        }).png().toFile(mask);

        console.error(`Pixels changed: ${pixelsChanged}, Pixels skipped (background): ${pixelsSkipped}`);

        console.log(JSON.stringify({
            success: true,
            mask,
            original: original || null,
            pixels_changed: pixelsChanged,
            pixels_skipped_background: pixelsSkipped,
            blend_zone: blendZone,
            bg_threshold: bgThreshold,
            bg_color: bgColor,
            dimensions: { width, height }
        }));

    } catch (error) {
        console.error(JSON.stringify({
            success: false,
            error: error.message,
            stack: error.stack
        }));
        process.exit(1);
    }
}

editMask();
