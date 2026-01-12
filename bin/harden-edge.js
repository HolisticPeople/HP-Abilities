/**
 * Harden mask edges by eliminating semi-transparency
 * Makes edge pixels either fully opaque (255) or fully transparent (0)
 * 
 * Usage: node bin/harden-edge.js <mask> <original> [--threshold 180]
 * 
 * The threshold determines where to cut: pixels in original with luminosity
 * significantly different from background get alpha=255, others get alpha=0
 */
const sharp = require('sharp');
const fs = require('fs');

async function hardenEdge() {
    const args = process.argv.slice(2);
    const maskPath = args[0];
    const origPath = args[1];
    const threshold = parseInt(args.find(a => a.startsWith('--threshold'))?.split('=')[1] || '15', 10);
    
    if (!maskPath || !origPath) {
        console.error('Usage: node bin/harden-edge.js <mask> <original> [--threshold=15]');
        process.exit(1);
    }
    
    const { data: maskData, info } = await sharp(maskPath).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
    const { data: origData } = await sharp(origPath).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
    const { width, height } = info;
    
    // Detect background luminosity from corners
    const bgSamples = [];
    for (let y = 0; y < 20; y++) {
        for (let x = 0; x < 20; x++) {
            const idx = (y * width + x) * 4;
            bgSamples.push((origData[idx] + origData[idx + 1] + origData[idx + 2]) / 3);
        }
    }
    const bgLum = Math.round(bgSamples.reduce((a, b) => a + b) / bgSamples.length);
    
    console.error('Background luminosity:', bgLum);
    console.error('Threshold: ±' + threshold + ' from background');
    
    let pixelsHardened = 0;
    let pixelsCleared = 0;
    
    // Process each row
    for (let y = 0; y < height; y++) {
        // Find where product actually starts in original
        let productStart = -1;
        for (let x = 0; x < width; x++) {
            const idx = (y * width + x) * 4;
            const lum = (origData[idx] + origData[idx + 1] + origData[idx + 2]) / 3;
            
            // Product if significantly different from background
            // Dark body: lum < bgLum - 80
            // White label: lum > bgLum + threshold
            if (lum < bgLum - 80 || lum > bgLum + threshold) {
                productStart = x;
                break;
            }
        }
        
        // Harden the mask based on product start
        for (let x = 0; x < width; x++) {
            const idx = (y * width + x) * 4;
            const currentAlpha = maskData[idx + 3];
            
            if (currentAlpha === 0 || currentAlpha === 255) continue; // Already hard
            
            if (productStart >= 0 && x >= productStart) {
                // This is product - make opaque
                // Use original colors
                maskData[idx] = origData[idx];
                maskData[idx + 1] = origData[idx + 1];
                maskData[idx + 2] = origData[idx + 2];
                maskData[idx + 3] = 255;
                pixelsHardened++;
            } else {
                // This is background - make transparent
                maskData[idx + 3] = 0;
                pixelsCleared++;
            }
        }
    }
    
    // Save
    await sharp(maskData, { raw: { width, height, channels: 4 } })
        .png()
        .toFile(maskPath);
    
    console.log(JSON.stringify({
        success: true,
        mask: maskPath,
        pixels_hardened: pixelsHardened,
        pixels_cleared: pixelsCleared,
        bg_luminosity: bgLum,
        threshold
    }));
}

hardenEdge().catch(e => console.error(e));
