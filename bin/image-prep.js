/**
 * Image Preparation Tool for HP Abilities
 * 
 * Performs:
 * 1. AI-powered background removal (isnet model for accuracy)
 * 2. Gets raw alpha mask and applies our aggressiveness threshold
 * 3. Uses mask to cut original image (preserves edge pixels)
 * 4. Professional resizing and centering on transparent canvas
 * 5. Optional: Upload to WordPress and set as product image
 * 
 * Usage:
 *   node bin/image-prep.js --url "http://..." --sku "DH515" --angle "front" [--sync] [--upload --product-id 123]
 * 
 * Flags:
 *   --sync       Fetch settings from WordPress before processing
 *   --upload     Upload to WordPress after processing (requires --product-id)
 *   --thumbnail  Set as featured image (default: true for 'front' angle)
 */

const fs = require('fs');
const path = require('path');
const axios = require('axios');
const sharp = require('sharp');
const { execSync } = require('child_process');

// Default configuration (can be overridden via --sync or CLI args)
const DEFAULTS = {
    target_size: 1100,
    padding: 0.05,
    aggressiveness: 50,
    smoothing: 0,
    naming: '{sku}-{angle}'
};

// Staging server configuration
const SSH_CONFIG = {
    host: '35.236.219.140',
    port: 12872,
    user: 'holisticpeoplecom',
    key: 'C:\\Users\\user\\.ssh\\kinsta_staging_key',
    remotePath: '/tmp'
};

const TEMP_DIR = path.join(process.cwd(), 'temp');

/**
 * Fetch settings from WordPress via SSH (most reliable method)
 */
function fetchSettingsFromWP() {
    try {
        console.error('Fetching settings from WordPress via SSH...');
        
        const getOption = (name) => {
            const cmd = `ssh -i "${SSH_CONFIG.key}" -p ${SSH_CONFIG.port} ${SSH_CONFIG.user}@${SSH_CONFIG.host} "cd public && wp option get ${name} 2>/dev/null || echo ''"`;
            try {
                return execSync(cmd, { encoding: 'utf8' }).trim().split('\n').pop();
            } catch (e) {
                return '';
            }
        };
        
        const aggressiveness = parseInt(getOption('hp_abilities_image_aggressiveness'), 10);
        const target_size = parseInt(getOption('hp_abilities_image_target_size'), 10);
        const padding = parseFloat(getOption('hp_abilities_image_padding'));
        const smoothing = parseInt(getOption('hp_abilities_image_smoothing'), 10);
        const naming = getOption('hp_abilities_image_naming');
        
        return {
            success: true,
            aggressiveness: aggressiveness || DEFAULTS.aggressiveness,
            target_size: target_size || DEFAULTS.target_size,
            padding: isNaN(padding) ? DEFAULTS.padding : padding,
            smoothing: isNaN(smoothing) ? DEFAULTS.smoothing : smoothing,
            naming: naming || DEFAULTS.naming
        };
    } catch (e) {
        console.error('Failed to fetch settings via SSH:', e.message);
        return null;
    }
}

/**
 * Smooth a binary alpha mask using morphological dilation.
 * This expands the mask to fill small gaps where the AI incorrectly
 * removed edge pixels. Ideal for recovering clipped bottle edges.
 * 
 * Smoothing controls expansion radius:
 * - 0 = no expansion (original edges)
 * - 1-5 = subtle expansion (fills tiny gaps)
 * - 6-10 = moderate expansion (recovers clipped edges)
 * - 11-20 = aggressive expansion (may include background)
 * 
 * @param {Buffer} imageBuffer - RGBA image buffer
 * @param {number} smoothingRadius - Pixels of expansion (0-20)
 * @returns {Buffer} - Image with expanded alpha channel
 */
async function smoothMask(imageBuffer, smoothingRadius) {
    if (!smoothingRadius || smoothingRadius <= 0) {
        return imageBuffer;
    }
    
    // Get image as raw RGBA
    const { data, info } = await sharp(imageBuffer)
        .ensureAlpha()
        .raw()
        .toBuffer({ resolveWithObject: true });
    
    const { width, height } = info;
    
    // Extract just the alpha channel
    const alphaMask = Buffer.alloc(width * height);
    for (let i = 0; i < width * height; i++) {
        alphaMask[i] = data[i * 4 + 3];
    }
    
    // Morphological dilation: expand opaque pixels into neighbors
    // This fills gaps where the AI incorrectly marked edge pixels as transparent
    const dilatedMask = Buffer.alloc(width * height);
    const radius = smoothingRadius;
    
    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const idx = y * width + x;
            let maxVal = alphaMask[idx];
            
            // Check circular neighborhood for any opaque pixel
            for (let dy = -radius; dy <= radius; dy++) {
                for (let dx = -radius; dx <= radius; dx++) {
                    const nx = x + dx;
                    const ny = y + dy;
                    if (nx >= 0 && nx < width && ny >= 0 && ny < height) {
                        const dist = Math.sqrt(dx * dx + dy * dy);
                        if (dist <= radius) {
                            const nIdx = ny * width + nx;
                            if (alphaMask[nIdx] > maxVal) {
                                maxVal = alphaMask[nIdx];
                            }
                        }
                    }
                }
            }
            dilatedMask[idx] = maxVal;
        }
    }
    
    // Apply dilated mask back to image
    const outputData = Buffer.alloc(width * height * 4);
    for (let i = 0; i < width * height; i++) {
        outputData[i * 4] = data[i * 4];         // R
        outputData[i * 4 + 1] = data[i * 4 + 1]; // G
        outputData[i * 4 + 2] = data[i * 4 + 2]; // B
        outputData[i * 4 + 3] = dilatedMask[i];
    }
    
    return sharp(outputData, { raw: { width, height, channels: 4 } })
        .png()
        .toBuffer();
}

/**
 * Extract alpha channel from cutout and apply aggressiveness threshold.
 * Then apply the thresholded alpha to the original image.
 * 
 * Aggressiveness controls the cutoff:
 * - 1 = keep pixels with >5% confidence (very permissive)
 * - 50 = keep pixels with >50% confidence (balanced)
 * - 100 = keep only pixels with >95% confidence (strict)
 */
async function applyThresholdedMaskToOriginal(originalBuffer, cutoutBuffer, aggressiveness) {
    // Map aggressiveness 1-100 to threshold 5%-95%
    const threshold = (aggressiveness / 100) * 0.9 + 0.05;
    const thresholdValue = Math.round(threshold * 255);
    
    console.error(`  Threshold: ${thresholdValue}/255 (keeping pixels with >${Math.round(threshold * 100)}% confidence)`);

    // Get original image as RGBA
    const { data: origData, info: origInfo } = await sharp(originalBuffer)
        .ensureAlpha()
        .raw()
        .toBuffer({ resolveWithObject: true });
    
    // Get cutout (which has the AI-generated alpha channel)
    const { data: cutoutData, info: cutoutInfo } = await sharp(cutoutBuffer)
        .ensureAlpha()
        .raw()
        .toBuffer({ resolveWithObject: true });
    
    // Ensure dimensions match
    if (origInfo.width !== cutoutInfo.width || origInfo.height !== cutoutInfo.height) {
        throw new Error('Original and cutout dimensions do not match');
    }
    
    // Create output buffer
    const outputData = Buffer.alloc(origInfo.width * origInfo.height * 4);
    
    // For each pixel: use original RGB, apply thresholded alpha from cutout
    for (let i = 0; i < origInfo.width * origInfo.height; i++) {
        const origAlpha = cutoutData[i * 4 + 3];  // Alpha from AI cutout
        
        // Apply threshold
        let newAlpha;
        if (origAlpha < thresholdValue) {
            newAlpha = 0;  // Below threshold = fully transparent
        } else {
            newAlpha = 255;  // Above threshold = fully opaque (preserve original colors)
        }
        
        outputData[i * 4] = origData[i * 4];       // R from original
        outputData[i * 4 + 1] = origData[i * 4 + 1]; // G from original
        outputData[i * 4 + 2] = origData[i * 4 + 2]; // B from original
        outputData[i * 4 + 3] = newAlpha;            // Thresholded alpha
    }
    
    return sharp(outputData, {
        raw: {
            width: origInfo.width,
            height: origInfo.height,
            channels: 4
        }
    }).png().toBuffer();
}

/**
 * Download image from URL
 */
async function downloadImage(url) {
    const response = await axios({
        url,
        method: 'GET',
        responseType: 'arraybuffer'
    });
    return Buffer.from(response.data);
}

/**
 * Generate output filename from naming pattern
 */
function generateFilename(pattern, sku, angle) {
    return pattern
        .replace('{sku}', sku)
        .replace('{angle}', angle)
        .replace('{timestamp}', Date.now().toString())
        + '.png';
}

/**
 * Upload image to WordPress via SCP + WP-CLI
 */
async function uploadToWordPress(localPath, productId, isThumbnail, sku, angle) {
    const filename = path.basename(localPath);
    const remoteTempPath = `${SSH_CONFIG.remotePath}/${filename}`;
    
    console.error('Uploading to staging server...');
    
    // SCP upload
    const scpCmd = `scp -P ${SSH_CONFIG.port} -i "${SSH_CONFIG.key}" "${localPath}" ${SSH_CONFIG.user}@${SSH_CONFIG.host}:${remoteTempPath}`;
    try {
        execSync(scpCmd, { stdio: 'pipe' });
    } catch (e) {
        throw new Error(`SCP failed: ${e.message}`);
    }
    
    console.error('Importing to WordPress Media Library...');
    
    // WP-CLI import
    const title = `${sku} ${angle}`;
    const alt = `${sku} product image - ${angle} view`;
    const importCmd = `ssh -i "${SSH_CONFIG.key}" -p ${SSH_CONFIG.port} ${SSH_CONFIG.user}@${SSH_CONFIG.host} "cd public && wp media import ${remoteTempPath} --title='${title}' --alt='${alt}' --porcelain 2>/dev/null"`;
    
    let attachmentId;
    try {
        const result = execSync(importCmd, { encoding: 'utf8' });
        attachmentId = parseInt(result.trim().split('\n').pop(), 10);
    } catch (e) {
        throw new Error(`WP media import failed: ${e.message}`);
    }
    
    if (!attachmentId || isNaN(attachmentId)) {
        throw new Error('Failed to get attachment ID from import');
    }
    
    console.error(`Attachment ID: ${attachmentId}`);
    
    // Set as featured image or add to gallery
    if (productId) {
        if (isThumbnail) {
            console.error('Setting as featured image...');
            const thumbCmd = `ssh -i "${SSH_CONFIG.key}" -p ${SSH_CONFIG.port} ${SSH_CONFIG.user}@${SSH_CONFIG.host} "cd public && wp post meta update ${productId} _thumbnail_id ${attachmentId} 2>/dev/null"`;
            execSync(thumbCmd, { stdio: 'pipe' });
        } else {
            console.error('Adding to product gallery...');
            const getGalleryCmd = `ssh -i "${SSH_CONFIG.key}" -p ${SSH_CONFIG.port} ${SSH_CONFIG.user}@${SSH_CONFIG.host} "cd public && wp post meta get ${productId} _product_image_gallery 2>/dev/null || echo ''"`;
            let gallery = '';
            try {
                gallery = execSync(getGalleryCmd, { encoding: 'utf8' }).trim();
            } catch (e) { /* no gallery yet */ }
            
            const newGallery = gallery ? `${gallery},${attachmentId}` : `${attachmentId}`;
            const setGalleryCmd = `ssh -i "${SSH_CONFIG.key}" -p ${SSH_CONFIG.port} ${SSH_CONFIG.user}@${SSH_CONFIG.host} "cd public && wp post meta update ${productId} _product_image_gallery '${newGallery}' 2>/dev/null"`;
            execSync(setGalleryCmd, { stdio: 'pipe' });
        }
    }
    
    // Cleanup remote temp file
    const cleanupCmd = `ssh -i "${SSH_CONFIG.key}" -p ${SSH_CONFIG.port} ${SSH_CONFIG.user}@${SSH_CONFIG.host} "rm ${remoteTempPath} 2>/dev/null || true"`;
    try { execSync(cleanupCmd, { stdio: 'pipe' }); } catch (e) { /* ignore */ }
    
    // Get the final URL
    const urlCmd = `ssh -i "${SSH_CONFIG.key}" -p ${SSH_CONFIG.port} ${SSH_CONFIG.user}@${SSH_CONFIG.host} "cd public && wp post get ${attachmentId} --field=guid 2>/dev/null"`;
    let imageUrl = '';
    try {
        imageUrl = execSync(urlCmd, { encoding: 'utf8' }).trim();
    } catch (e) { /* ignore */ }
    
    return {
        attachment_id: attachmentId,
        url: imageUrl,
        product_id: productId,
        is_thumbnail: isThumbnail
    };
}

async function prepareImage() {
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

    const { url, file, sku, angle = 'front', sync, upload, product_id, thumbnail } = params;

    if (!url && !file) {
        console.error(JSON.stringify({ success: false, error: 'Missing --url or --file parameter' }));
        process.exit(1);
    }

    if (!sku) {
        console.error(JSON.stringify({ success: false, error: 'Missing --sku parameter' }));
        process.exit(1);
    }

    if (upload && !product_id) {
        console.error(JSON.stringify({ success: false, error: '--upload requires --product-id parameter' }));
        process.exit(1);
    }

    try {
        if (!fs.existsSync(TEMP_DIR)) {
            fs.mkdirSync(TEMP_DIR, { recursive: true });
        }

        // Fetch settings from WordPress if --sync is provided OR if --upload is used
        let settings = { ...DEFAULTS };
        
        if (sync || upload) {
            const wpSettings = fetchSettingsFromWP();
            if (wpSettings && wpSettings.success) {
                settings = {
                    target_size: wpSettings.target_size,
                    padding: wpSettings.padding,
                    aggressiveness: wpSettings.aggressiveness,
                    smoothing: wpSettings.smoothing,
                    naming: wpSettings.naming
                };
                console.error(`✓ Settings from WP: aggressiveness=${settings.aggressiveness}, smoothing=${settings.smoothing}, size=${settings.target_size}, padding=${settings.padding}`);
            } else {
                console.error('Failed to sync settings, using defaults');
            }
        }

        // Allow CLI overrides
        if (params.target_size) settings.target_size = parseInt(params.target_size, 10);
        if (params.padding) settings.padding = parseFloat(params.padding);
        if (params.aggressiveness) settings.aggressiveness = parseInt(params.aggressiveness, 10);
        if (params.smoothing) settings.smoothing = parseInt(params.smoothing, 10);
        if (params.naming) settings.naming = params.naming;

        let inputSource;
        let originalBuffer;
        
        if (url) {
            console.error(`Processing URL: ${url}`);
            inputSource = url;
            originalBuffer = await downloadImage(url);
        } else {
            console.error(`Processing File: ${file}`);
            inputSource = path.resolve(file);
            originalBuffer = fs.readFileSync(inputSource);
        }
        
        // Save original for later use (we'll apply the mask to it)
        const originalPath = path.join(TEMP_DIR, `${sku}-${angle}-original.png`);
        fs.writeFileSync(originalPath, originalBuffer);

        // 1. Get cutout from AI (preserves raw alpha probabilities)
        console.error('Getting cutout from AI...');
        const cutoutPath = path.join(TEMP_DIR, `${sku}-${angle}-cutout.png`);
        
        try {
            execSync(`node "${path.join(__dirname, 'bg-remove-helper.js')}" "${inputSource}" "${cutoutPath}"`, { stdio: 'inherit' });
        } catch (e) {
            throw new Error('Background removal failed');
        }

        const cutoutFromAI = fs.readFileSync(cutoutPath);

        // 2. Apply our aggressiveness threshold to the alpha channel
        // and use original image colors (not cutout colors which may be degraded)
        console.error(`Applying aggressiveness threshold: ${settings.aggressiveness}/100`);
        let cutoutBuffer = await applyThresholdedMaskToOriginal(originalBuffer, cutoutFromAI, settings.aggressiveness);

        // 3. Apply edge smoothing if enabled (fixes jagged bottle edges)
        if (settings.smoothing > 0) {
            console.error(`Applying edge smoothing: ${settings.smoothing}px radius`);
            cutoutBuffer = await smoothMask(cutoutBuffer, settings.smoothing);
        }

        // 4. Trim and resize
        console.error('Trimming and resizing...');
        const trimmed = await sharp(cutoutBuffer).trim().toBuffer({ resolveWithObject: true });
        const { width, height } = trimmed.info;
        
        const maxDim = settings.target_size * (1 - settings.padding * 2);
        const scale = Math.min(maxDim / width, maxDim / height);
        
        const newWidth = Math.round(width * scale);
        const newHeight = Math.round(height * scale);

        const resizedCutout = await sharp(trimmed.data)
            .resize(newWidth, newHeight)
            .toBuffer();

        // 5. Composite on canvas
        const outputFilename = generateFilename(settings.naming, sku, angle);
        const outputPath = path.join(TEMP_DIR, outputFilename);
        
        await sharp({
            create: {
                width: settings.target_size,
                height: settings.target_size,
                channels: 4,
                background: { r: 0, g: 0, b: 0, alpha: 0 }
            }
        })
        .composite([{ input: resizedCutout, gravity: 'center' }])
        .png()
        .toFile(outputPath);

        // Clean up temp files
        try { fs.unlinkSync(cutoutPath); } catch (e) { /* ignore */ }
        try { fs.unlinkSync(originalPath); } catch (e) { /* ignore */ }

        const result = {
            success: true,
            sku,
            angle,
            original: url || file,
            output: outputPath,
            width: settings.target_size,
            height: settings.target_size,
            format: 'png',
            settings: {
                target_size: settings.target_size,
                padding: settings.padding,
                aggressiveness: settings.aggressiveness,
                smoothing: settings.smoothing,
                naming: settings.naming
            }
        };

        // 6. Upload to WordPress if --upload flag is set
        if (upload) {
            const isThumbnail = thumbnail !== 'false' && (thumbnail === true || thumbnail === 'true' || angle === 'front');
            const uploadResult = await uploadToWordPress(outputPath, parseInt(product_id, 10), isThumbnail, sku, angle);
            result.upload = uploadResult;
            console.error(`✓ Uploaded and ${isThumbnail ? 'set as featured image' : 'added to gallery'}`);
        }

        console.log(JSON.stringify(result));

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
