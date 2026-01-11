/**
 * Image Preparation Tool for HP Abilities
 * 
 * Performs:
 * 1. AI-powered background removal
 * 2. Professional resizing and centering on 1100x1100 transparent canvas
 * 3. Output as optimized PNG
 * 
 * Usage: node bin/image-prep.js --url "http://..." --sku "DH515" --angle "front"
 */

const fs = require('fs');
const path = require('path');
const axios = require('axios');
const sharp = require('sharp');
const { removeBackground } = require('@imgly/background-removal-node');

// Configuration
const TARGET_SIZE = 1100;
const PADDING_PERCENT = 0.05; // 5% padding
const TEMP_DIR = path.join(process.cwd(), 'temp');

async function downloadImage(url) {
    const response = await axios({
        url,
        method: 'GET',
        responseType: 'arraybuffer'
    });
    return Buffer.from(response.data);
}

async function prepareImage() {
    const args = process.argv.slice(2);
    const params = {};
    for (let i = 0; i < args.length; i += 2) {
        params[args[i].replace('--', '')] = args[i + 1];
    }

    const { url, file, sku, angle = 'front' } = params;

    if (!url && !file) {
        console.error(JSON.stringify({ success: false, error: 'Missing --url or --file parameter' }));
        process.exit(1);
    }

    if (!sku) {
        console.error(JSON.stringify({ success: false, error: 'Missing --sku parameter' }));
        process.exit(1);
    }

    try {
        if (!fs.existsSync(TEMP_DIR)) {
            fs.mkdirSync(TEMP_DIR, { recursive: true });
        }

        let inputBuffer;
        if (url) {
            inputBuffer = await downloadImage(url);
        } else {
            inputBuffer = fs.readFileSync(file);
        }

        // 1. Remove Background
        // Note: This downloads the model on first run (~150MB)
        const blob = await removeBackground(inputBuffer);
        const cutoutBuffer = Buffer.from(await blob.arrayBuffer());

        // 2. Process with Sharp
        // First, trim the transparent edges of the cutout
        const trimmed = await sharp(cutoutBuffer).trim().toBuffer({ resolveWithObject: true });
        
        const { width, height } = trimmed.info;
        
        // Calculate scale to fit in TARGET_SIZE with padding
        const maxDim = TARGET_SIZE * (1 - PADDING_PERCENT * 2);
        const scale = Math.min(maxDim / width, maxDim / height);
        
        const newWidth = Math.round(width * scale);
        const newHeight = Math.round(height * scale);

        // Resize the trimmed cutout
        const resizedCutout = await sharp(trimmed.data)
            .resize(newWidth, newHeight)
            .toBuffer();

        // Create transparent background and composite the cutout in the center
        const outputPath = path.join(TEMP_DIR, `${sku}-${angle}.png`);
        
        await sharp({
            create: {
                width: TARGET_SIZE,
                height: TARGET_SIZE,
                channels: 4,
                background: { r: 0, g: 0, b: 0, alpha: 0 }
            }
        })
        .composite([{ input: resizedCutout, gravity: 'center' }])
        .png()
        .toFile(outputPath);

        console.log(JSON.stringify({
            success: true,
            sku,
            angle,
            original: url || file,
            output: outputPath,
            width: TARGET_SIZE,
            height: TARGET_SIZE,
            format: 'png'
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

prepareImage();
