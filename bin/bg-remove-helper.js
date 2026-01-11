/**
 * Helper script to isolate @imgly/background-removal-node from sharp
 * 
 * Gets raw alpha mask from AI, converts to grayscale PNG for processing.
 */
const { removeBackground } = require('@imgly/background-removal-node');
const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');

async function run() {
    const source = process.argv[2];
    const outputPath = process.argv[3];
    
    try {
        let input;
        
        // Check if it's a URL or a local file path
        if (source.startsWith('http://') || source.startsWith('https://')) {
            input = source;
        } else {
            // Convert local path to file:// URL for @imgly
            const absolutePath = path.resolve(source);
            input = pathToFileURL(absolutePath).href;
        }
        
        console.error('Generating alpha mask with medium model...');
        
        // First, get the regular cutout to determine dimensions
        const cutoutBlob = await removeBackground(input, {
            model: 'medium'
        });
        const cutoutBuffer = Buffer.from(await cutoutBlob.arrayBuffer());
        
        // The cutout has RGBA - extract just the alpha channel
        // We need to use sharp here, but it conflicts with @imgly
        // So we'll save the cutout and let image-prep.js extract the alpha
        
        fs.writeFileSync(outputPath, cutoutBuffer);
        console.error('Cutout with alpha channel generated successfully');
        process.exit(0);
    } catch (e) {
        console.error(e);
        process.exit(1);
    }
}

run();
