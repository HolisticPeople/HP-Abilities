/**
 * View on Black Background Tool for HP Abilities
 * 
 * Composites an image onto a black background for quality inspection.
 * This is CRITICAL for mask correction - white/transparent backgrounds
 * hide edge issues that are clearly visible on black.
 * 
 * Usage:
 *   node bin/view-on-black.js <image-path>
 * 
 * Example:
 *   node bin/view-on-black.js temp/DH515-front-mask.png
 *   # Creates: temp/DH515-front-mask-on-black.png
 * 
 * Output:
 *   Creates a new file with '-on-black.png' suffix in the same directory.
 *   Prints the output path and dimensions to stdout.
 * 
 * Why Black Background:
 *   - Reveals semi-transparent edge pixels (appear as faint glow)
 *   - Shows jagged/irregular edges clearly
 *   - Matches the display context for many product pages
 *   - Essential for the Adaptive Symmetry Workflow
 */
const sharp = require('sharp');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

async function viewOnBlack() {
    const inputPath = process.argv[2];
    
    if (!inputPath || !fs.existsSync(inputPath)) {
        console.error('Usage: node bin/view-on-black.js <image-path>');
        process.exit(1);
    }

    const outputPath = inputPath.replace(/\.png$/, '-on-black.png');
    
    // Get image dimensions
    const metadata = await sharp(inputPath).metadata();
    const { width, height } = metadata;

    // Create black background and composite the image on top
    await sharp({
        create: {
            width,
            height,
            channels: 4,
            background: { r: 0, g: 0, b: 0, alpha: 1 }
        }
    })
    .composite([{ input: inputPath, blend: 'over' }])
    .png()
    .toFile(outputPath);

    console.log(`Created: ${outputPath}`);
    console.log(`Dimensions: ${width}x${height}`);
    
    return outputPath;
}

viewOnBlack().catch(e => console.error(e));
