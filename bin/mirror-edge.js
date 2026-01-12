/**
 * Mirror Edge Tool for HP Abilities
 * 
 * Mirrows one side of a product mask to the other side based on a center point.
 * This ensures geometric symmetry for items like bottles.
 * 
 * Usage:
 *   node bin/mirror-edge.js --mask mask.png --original source.png --center 394 --source-side right --target-side left
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

    const { mask, original, center, source_side = 'right', target_side = 'left' } = params;
    const centerX = parseInt(center, 10);

    if (!mask || !original || !center) {
        console.error('Usage: node bin/mirror-edge.js --mask <path> --original <path> --center <x> [--source-side right] [--target-side left]');
        process.exit(1);
    }

    try {
        const { data: maskData, info } = await sharp(mask).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
        const { data: origData } = await sharp(original).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
        const { width, height } = info;

        console.error(`Mirroring ${source_side} edge to ${target_side} around x=${centerX}`);

        let pixelsChanged = 0;

        for (let y = 0; y < height; y++) {
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
        console.log(JSON.stringify({ success: true, pixels_changed: pixelsChanged }));

    } catch (error) {
        console.error(JSON.stringify({ success: false, error: error.message }));
        process.exit(1);
    }
}

mirrorEdge();
