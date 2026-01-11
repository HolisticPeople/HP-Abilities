/**
 * Mask Editing Tool for HP Abilities
 * 
 * Allows the agent to apply targeted pixel edits to a mask image.
 * The agent inspects the mask, decides what needs fixing, then calls this tool.
 * 
 * Usage:
 *   node bin/edit-mask.js --mask temp/DH515-front-mask.png --left-edge 47 --from-row 200 --to-row 350
 *   node bin/edit-mask.js --mask temp/DH515-front-mask.png --right-edge 420 --from-row 100 --to-row 400
 *   node bin/edit-mask.js --mask temp/DH515-front-mask.png --fill-rect --x1 45 --y1 200 --x2 50 --y2 350
 * 
 * Operations:
 *   --left-edge X    Set left edge to X for rows from-row to to-row
 *   --right-edge X   Set right edge to X for rows from-row to to-row
 *   --fill-rect      Fill a rectangle with opaque pixels
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

    const { mask, left_edge, right_edge, from_row, to_row, fill_rect, clear_rect, x1, y1, x2, y2 } = params;

    if (!mask) {
        console.error(JSON.stringify({ success: false, error: 'Missing --mask parameter' }));
        process.exit(1);
    }

    if (!fs.existsSync(mask)) {
        console.error(JSON.stringify({ success: false, error: `Mask file not found: ${mask}` }));
        process.exit(1);
    }

    try {
        // Load mask as raw RGBA
        const { data, info } = await sharp(mask)
            .ensureAlpha()
            .raw()
            .toBuffer({ resolveWithObject: true });

        const { width, height } = info;
        let pixelsChanged = 0;

        console.error(`Mask: ${width}x${height}`);

        // Left edge operation
        if (left_edge !== undefined) {
            const targetX = parseInt(left_edge, 10);
            const startRow = parseInt(from_row, 10) || 0;
            const endRow = parseInt(to_row, 10) || height - 1;

            console.error(`Setting left edge to x=${targetX} for rows ${startRow}-${endRow}`);

            for (let y = startRow; y <= endRow && y < height; y++) {
                // Find current left edge
                let currentLeft = -1;
                for (let x = 0; x < width; x++) {
                    if (data[(y * width + x) * 4 + 3] > 0) {
                        currentLeft = x;
                        break;
                    }
                }

                // If current left is to the right of target, fill in the gap
                if (currentLeft > targetX) {
                    for (let x = targetX; x < currentLeft; x++) {
                        const idx = (y * width + x) * 4;
                        data[idx] = 255;     // R
                        data[idx + 1] = 255; // G
                        data[idx + 2] = 255; // B
                        data[idx + 3] = 255; // A (opaque)
                        pixelsChanged++;
                    }
                }
                // If current left is to the left of target, clear pixels
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

            console.error(`Setting right edge to x=${targetX} for rows ${startRow}-${endRow}`);

            for (let y = startRow; y <= endRow && y < height; y++) {
                // Find current right edge
                let currentRight = -1;
                for (let x = width - 1; x >= 0; x--) {
                    if (data[(y * width + x) * 4 + 3] > 0) {
                        currentRight = x;
                        break;
                    }
                }

                // If current right is to the left of target, fill in the gap
                if (currentRight >= 0 && currentRight < targetX) {
                    for (let x = currentRight + 1; x <= targetX && x < width; x++) {
                        const idx = (y * width + x) * 4;
                        data[idx] = 255;     // R
                        data[idx + 1] = 255; // G
                        data[idx + 2] = 255; // B
                        data[idx + 3] = 255; // A (opaque)
                        pixelsChanged++;
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

            console.error(`Filling rectangle (${rectX1},${rectY1}) to (${rectX2},${rectY2})`);

            for (let y = rectY1; y <= rectY2 && y < height; y++) {
                for (let x = rectX1; x <= rectX2 && x < width; x++) {
                    if (x >= 0 && y >= 0) {
                        const idx = (y * width + x) * 4;
                        data[idx] = 255;     // R
                        data[idx + 1] = 255; // G
                        data[idx + 2] = 255; // B
                        data[idx + 3] = 255; // A (opaque)
                        pixelsChanged++;
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

        console.log(JSON.stringify({
            success: true,
            mask,
            pixels_changed: pixelsChanged,
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
