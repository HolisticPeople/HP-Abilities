/**
 * Mirror Edge Tool for HP Abilities
 * 
 * Mirrors one side of a product mask to the other side based on a center point.
 * This ensures geometric symmetry for items like bottles.
 * 
 * ADAPTIVE SYMMETRY: The agent should analyze BOTH edges and determine which
 * is "smoother" vs "broken" for each vertical region. Different regions may
 * need different mirror directions.
 * 
 * Usage:
 *   Full height:
 *     node bin/mirror-edge.js --mask mask.png --original source.png --center 390 --source-side right
 * 
 *   Specific region only:
 *     node bin/mirror-edge.js --mask mask.png --original source.png --center 390 --source-side right --from-row 100 --to-row 400
 * 
 * Parameters:
 *   --mask         Path to the mask image (will be modified in place)
 *   --original     Path to the original source image (for sampling colors)
 *   --center       Horizontal center X coordinate of the product
 *   --source-side  Which side to use as the template: 'right' or 'left'
 *   --target-side  (Optional) Which side to fix, defaults to opposite of source
 *   --from-row     (Optional) Start row for regional mirroring (0 = top)
 *   --to-row       (Optional) End row for regional mirroring (exclusive)
 * 
 * Examples:
 *   # Mirror right edge to left for entire image
 *   node bin/mirror-edge.js --mask temp/mask.png --original temp/input.png --center 390 --source-side right
 * 
 *   # Mirror right edge to left for rows 200-500 only (label area)
 *   node bin/mirror-edge.js --mask temp/mask.png --original temp/input.png --center 390 --source-side right --from-row 200 --to-row 500
 * 
 *   # Mirror left edge to right for base region
 *   node bin/mirror-edge.js --mask temp/mask.png --original temp/input.png --center 390 --source-side left --from-row 600 --to-row 750
 */

const fs = require('fs');
const sharp = require('sharp');

async function mirrorEdge() {
    const args = process.argv.slice(2);
    const params = {};
    for (let i = 0; i < args.length; i++) {
        if (args[i].startsWith('--')) {
            const key = args[i].replace('--', '').replace(/-/g, '_');
            params[key] = args[i + 1];
            i++;
        }
    }

    const { mask, original, center, source_side = 'right', from_row, to_row } = params;
    // Target side defaults to opposite of source
    const target_side = params.target_side || (source_side === 'right' ? 'left' : 'right');
    const centerX = parseInt(center, 10);

    if (!mask || !original || !center) {
        console.error('Usage: node bin/mirror-edge.js --mask <path> --original <path> --center <x> [--source-side right] [--from-row Y] [--to-row Z]');
        process.exit(1);
    }

    try {
        const { data: maskData, info } = await sharp(mask).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
        const { data: origData } = await sharp(original).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
        const { width, height } = info;

        // Parse row range, defaulting to full height
        const startRow = from_row ? parseInt(from_row, 10) : 0;
        const endRow = to_row ? parseInt(to_row, 10) : height;

        console.error(`Mirroring ${source_side} edge to ${target_side} around x=${centerX}`);
        console.error(`Row range: ${startRow} to ${endRow} (of ${height})`);

        let pixelsChanged = 0;

        for (let y = startRow; y < endRow && y < height; y++) {
            let sourceEdgeX = -1;
            
            if (source_side === 'right') {
                // Find right edge (scanning from right to center)
                for (let x = width - 1; x >= centerX; x--) {
                    if (maskData[(y * width + x) * 4 + 3] > 128) {
                        sourceEdgeX = x;
                        break;
                    }
                }
            } else {
                // Find left edge (scanning from left to center)
                for (let x = 0; x <= centerX; x++) {
                    if (maskData[(y * width + x) * 4 + 3] > 128) {
                        sourceEdgeX = x;
                        break;
                    }
                }
            }

            if (sourceEdgeX !== -1) {
                const distFromCenter = Math.abs(sourceEdgeX - centerX);
                const targetEdgeX = target_side === 'left' ? centerX - distFromCenter : centerX + distFromCenter;

                if (target_side === 'left') {
                    // Mirror to left: fill from targetEdgeX towards center
                    for (let x = targetEdgeX; x < centerX; x++) {
                        const idx = (y * width + x) * 4;
                        if (x >= 0 && x < width) {
                            maskData[idx] = origData[idx];
                            maskData[idx + 1] = origData[idx + 1];
                            maskData[idx + 2] = origData[idx + 2];
                            maskData[idx + 3] = 255;
                            pixelsChanged++;
                        }
                    }
                    // Clear anything to the left of the new target edge
                    for (let x = 0; x < targetEdgeX; x++) {
                        const idx = (y * width + x) * 4;
                        if (maskData[idx + 3] > 0) {
                            maskData[idx + 3] = 0;
                            pixelsChanged++;
                        }
                    }
                } else {
                    // Mirror to right: fill from center towards targetEdgeX
                    for (let x = centerX; x <= targetEdgeX; x++) {
                        const idx = (y * width + x) * 4;
                        if (x >= 0 && x < width) {
                            maskData[idx] = origData[idx];
                            maskData[idx + 1] = origData[idx + 1];
                            maskData[idx + 2] = origData[idx + 2];
                            maskData[idx + 3] = 255;
                            pixelsChanged++;
                        }
                    }
                    // Clear anything to the right of the new target edge
                    for (let x = targetEdgeX + 1; x < width; x++) {
                        const idx = (y * width + x) * 4;
                        if (maskData[idx + 3] > 0) {
                            maskData[idx + 3] = 0;
                            pixelsChanged++;
                        }
                    }
                }
            }
        }

        await sharp(maskData, { raw: { width, height, channels: 4 } }).png().toFile(mask);
        console.log(JSON.stringify({ 
            success: true, 
            pixels_changed: pixelsChanged,
            row_range: { from: startRow, to: endRow },
            source_side,
            target_side,
            center: centerX
        }));

    } catch (error) {
        console.error(JSON.stringify({ success: false, error: error.message }));
        process.exit(1);
    }
}

mirrorEdge();
