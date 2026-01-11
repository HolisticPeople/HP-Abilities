/**
 * Image Preparation Tool for HP Abilities
 * 
 * Performs:
 * 1. AI-powered background removal
 * 2. Alpha channel thresholding based on aggressiveness setting
 * 3. Professional resizing and centering on transparent canvas
 * 4. Optional: Upload to WordPress and set as product image
 * 
 * Usage:
 *   node bin/image-prep.js --file source.png --sku "DH515" --angle "front" [--sync] [--upload --product-id 123]
 *   node bin/image-prep.js --file source.png --sku "DH515" --mask-only  (stops after creating mask)
 *   node bin/image-prep.js --sku "DH515" --use-mask mask.png --upload --product-id 123  (uses pre-edited mask)
 * 
 * Flags:
 *   --sync       Fetch settings from WordPress before processing
 *   --mask-only  Generate mask and stop (for agent inspection)
 *   --use-mask   Use a pre-edited mask instead of generating new one
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
        const naming = getOption('hp_abilities_image_naming');
        const correction_prompt = getOption('hp_abilities_image_correction_prompt');
        
        return {
            success: true,
            aggressiveness: aggressiveness || DEFAULTS.aggressiveness,
            target_size: target_size || DEFAULTS.target_size,
            padding: isNaN(padding) ? DEFAULTS.padding : padding,
            naming: naming || DEFAULTS.naming,
            correction_prompt: correction_prompt || ''
        };
    } catch (e) {
        console.error('Failed to fetch settings via SSH:', e.message);
        return null;
    }
}

/**
 * Apply aggressiveness threshold to alpha channel.
 * Simple approach from v0.11.0 - threshold on cutout directly.
 * 
 * Aggressiveness controls the cutoff:
 * - 1 = keep pixels with >5% opacity (very permissive)
 * - 50 = keep pixels with >50% opacity (balanced)
 * - 100 = keep only pixels with >95% opacity (strict)
 */
async function applyAggressiveness(imageBuffer, aggressiveness) {
    const threshold = (aggressiveness / 100) * 0.9 + 0.05;
    const thresholdValue = Math.round(threshold * 255);
    
    console.error(`  Threshold: ${thresholdValue}/255 (keeping pixels with >${Math.round(threshold * 100)}% opacity)`);

    const { data, info } = await sharp(imageBuffer)
        .ensureAlpha()
        .raw()
        .toBuffer({ resolveWithObject: true });

    // Apply threshold to alpha channel (every 4th byte starting at index 3)
    for (let i = 3; i < data.length; i += 4) {
        if (data[i] < thresholdValue) {
            data[i] = 0; // Fully transparent
        }
        // Keep pixels above threshold as-is
    }

    return sharp(data, {
        raw: {
            width: info.width,
            height: info.height,
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

    const { url, file, sku, angle = 'front', sync, upload, product_id, thumbnail, mask_only, use_mask } = params;

    // Validate inputs
    if (!use_mask && !url && !file) {
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

        // Fetch settings from WordPress if --sync is provided OR if --upload is used OR --mask-only
        let settings = { ...DEFAULTS, correction_prompt: '' };
        
        if (sync || upload || mask_only) {
            const wpSettings = fetchSettingsFromWP();
            if (wpSettings && wpSettings.success) {
                settings = {
                    target_size: wpSettings.target_size,
                    padding: wpSettings.padding,
                    aggressiveness: wpSettings.aggressiveness,
                    naming: wpSettings.naming,
                    correction_prompt: wpSettings.correction_prompt || ''
                };
                console.error(`✓ Settings from WP: aggressiveness=${settings.aggressiveness}, size=${settings.target_size}, padding=${settings.padding}`);
            } else {
                console.error('Failed to sync settings, using defaults');
            }
        }

        // Allow CLI overrides
        if (params.target_size) settings.target_size = parseInt(params.target_size, 10);
        if (params.padding) settings.padding = parseFloat(params.padding);
        if (params.aggressiveness) settings.aggressiveness = parseInt(params.aggressiveness, 10);
        if (params.naming) settings.naming = params.naming;

        let cutoutBuffer;
        const maskPath = path.join(TEMP_DIR, `${sku}-${angle}-mask.png`);

        if (use_mask) {
            // Use pre-edited mask provided by agent
            console.error(`Using pre-edited mask: ${use_mask}`);
            cutoutBuffer = fs.readFileSync(use_mask);
        } else {
            // Generate mask from source
            let inputSource;
            
            if (url) {
                console.error(`Processing URL: ${url}`);
                inputSource = url;
            } else {
                console.error(`Processing File: ${file}`);
                inputSource = path.resolve(file);
            }

            // 1. Get cutout from AI
            console.error('Getting cutout from AI...');
            const cutoutPath = path.join(TEMP_DIR, `${sku}-${angle}-cutout.png`);
            
            try {
                execSync(`node "${path.join(__dirname, 'bg-remove-helper.js')}" "${inputSource}" "${cutoutPath}"`, { stdio: 'inherit' });
            } catch (e) {
                throw new Error('Background removal failed');
            }

            const cutoutFromAI = fs.readFileSync(cutoutPath);

            // 2. Apply aggressiveness threshold
            console.error(`Applying aggressiveness threshold: ${settings.aggressiveness}/100`);
            cutoutBuffer = await applyAggressiveness(cutoutFromAI, settings.aggressiveness);

            // Save mask for agent inspection
            fs.writeFileSync(maskPath, cutoutBuffer);
            console.error(`Mask saved to: ${maskPath}`);

            // Clean up temp cutout
            try { fs.unlinkSync(cutoutPath); } catch (e) { /* ignore */ }

            // If --mask-only, stop here and display correction prompt
            if (mask_only) {
                // Display the correction prompt to guide the agent
                if (settings.correction_prompt) {
                    console.error('\n=== MASK CORRECTION INSTRUCTIONS ===');
                    console.error(settings.correction_prompt);
                    console.error('=====================================\n');
                }
                
                console.log(JSON.stringify({
                    success: true,
                    mode: 'mask-only',
                    sku,
                    angle,
                    mask: maskPath,
                    original: inputSource,
                    message: 'Mask generated. Agent should inspect mask and original, apply corrections using edit-mask.js, then run with --use-mask',
                    next_steps: [
                        `1. View mask: ${maskPath}`,
                        `2. View original: ${inputSource}`,
                        '3. Identify problem areas (light on light, edge issues)',
                        '4. Apply corrections: node bin/edit-mask.js --mask "' + maskPath + '" --left-edge X --from-row Y --to-row Z',
                        `5. Complete: node bin/image-prep.js --sku ${sku} --use-mask "${maskPath}" --upload --product-id PRODUCT_ID`
                    ]
                }));
                return;
            }
        }

        // 3. Trim and resize
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

        // 4. Composite on canvas
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

        const result = {
            success: true,
            sku,
            angle,
            output: outputPath,
            width: settings.target_size,
            height: settings.target_size,
            format: 'png',
            settings: {
                target_size: settings.target_size,
                padding: settings.padding,
                aggressiveness: settings.aggressiveness,
                naming: settings.naming
            }
        };

        // 5. Upload to WordPress if --upload flag is set
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
