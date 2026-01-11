/**
 * Image Preparation Tool for HP Abilities
 * 
 * Performs:
 * 1. AI-powered background removal
 * 2. Alpha channel thresholding based on aggressiveness setting
 * 3. Professional resizing and centering on transparent canvas
 * 4. Output as optimized PNG
 * 
 * Usage: node bin/image-prep.js --url "http://..." --sku "DH515" --angle "front" [--sync]
 * 
 * With --sync: Fetches settings from WordPress via MCP before processing
 * Without --sync: Uses local defaults or command-line overrides
 */

const fs = require('fs');
const path = require('path');
const axios = require('axios');
const sharp = require('sharp');
const { execSync, spawn } = require('child_process');

// Default configuration (can be overridden via --sync or CLI args)
const DEFAULTS = {
    target_size: 1100,
    padding: 0.05,
    aggressiveness: 50,
    naming: '{sku}-{angle}'
};

const TEMP_DIR = path.join(process.cwd(), 'temp');

/**
 * Fetch settings from WordPress via MCP bridge
 */
async function fetchSettingsFromWP() {
    return new Promise((resolve) => {
        try {
            // Read mcp.json to get bridge config
            const mcpConfigPaths = [
                path.join(process.env.APPDATA || '', 'Cursor', 'User', 'globalStorage', 'cursor.mcp', 'mcp.json'),
                path.join(process.env.USERPROFILE || '', '.cursor', 'mcp.json'),
                'C:\\Users\\user\\AppData\\Roaming\\Cursor\\User\\globalStorage\\cursor.mcp\\mcp.json'
            ];
            
            let mcpConfig = null;
            for (const p of mcpConfigPaths) {
                if (fs.existsSync(p)) {
                    mcpConfig = JSON.parse(fs.readFileSync(p, 'utf8'));
                    break;
                }
            }

            if (!mcpConfig || !mcpConfig.mcpServers || !mcpConfig.mcpServers.hp_products_stg) {
                console.error('Could not find MCP config, using defaults');
                return resolve(null);
            }

            const serverConfig = mcpConfig.mcpServers.hp_products_stg;
            const bridgePath = serverConfig.args[0];
            const apiUrl = serverConfig.args[1];
            const apiKey = serverConfig.args[2];

            // Make direct HTTP request to WordPress MCP endpoint
            const url = new URL(apiUrl);
            const https = require('https');
            
            const payload = JSON.stringify({
                jsonrpc: '2.0',
                method: 'tools/call',
                params: {
                    name: 'hp-abilities/image-settings',
                    arguments: { action: 'get' }
                },
                id: 1
            });

            const options = {
                hostname: url.hostname,
                port: 443,
                path: url.pathname + url.search,
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-MCP-API-Key': apiKey,
                    'Content-Length': Buffer.byteLength(payload)
                }
            };

            const req = https.request(options, (res) => {
                let data = '';
                res.on('data', chunk => data += chunk);
                res.on('end', () => {
                    try {
                        const cleanData = data.replace(/^[^{]*/, '').trim();
                        const parsed = JSON.parse(cleanData);
                        if (parsed.result && parsed.result.content) {
                            const content = parsed.result.content[0];
                            if (content && content.text) {
                                const settings = JSON.parse(content.text);
                                if (settings.success) {
                                    resolve(settings);
                                    return;
                                }
                            }
                        }
                        resolve(null);
                    } catch (e) {
                        console.error('Failed to parse WP settings response:', e.message);
                        resolve(null);
                    }
                });
            });

            req.on('error', (e) => {
                console.error('Failed to fetch WP settings:', e.message);
                resolve(null);
            });

            req.setTimeout(10000, () => {
                req.destroy();
                console.error('WP settings request timed out');
                resolve(null);
            });

            req.write(payload);
            req.end();

        } catch (e) {
            console.error('Error fetching settings:', e.message);
            resolve(null);
        }
    });
}

/**
 * Apply aggressiveness threshold to alpha channel
 * Lower aggressiveness = keep more semi-transparent pixels
 * Higher aggressiveness = harder cutoff
 */
async function applyAggressiveness(imageBuffer, aggressiveness) {
    // Map 1-100 to threshold (inverted: low aggr = low threshold = keep more)
    // aggressiveness 1 -> threshold ~0.1 (keep pixels with >10% opacity)
    // aggressiveness 50 -> threshold ~0.5 (keep pixels with >50% opacity) 
    // aggressiveness 100 -> threshold ~0.95 (keep only very opaque pixels)
    const threshold = (aggressiveness / 100) * 0.9 + 0.05; // Maps to 0.05-0.95
    const thresholdValue = Math.round(threshold * 255);

    const { data, info } = await sharp(imageBuffer)
        .ensureAlpha()
        .raw()
        .toBuffer({ resolveWithObject: true });

    // Apply threshold to alpha channel (every 4th byte starting at index 3)
    for (let i = 3; i < data.length; i += 4) {
        if (data[i] < thresholdValue) {
            data[i] = 0; // Fully transparent
        }
        // Keep pixels above threshold as-is (preserves smooth edges when aggressiveness is low)
    }

    // Reconstruct image from raw data
    return sharp(data, {
        raw: {
            width: info.width,
            height: info.height,
            channels: 4
        }
    }).png().toBuffer();
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

async function prepareImage() {
    const args = process.argv.slice(2);
    const params = {};
    
    // Parse arguments (handle both --key value and --flag formats)
    for (let i = 0; i < args.length; i++) {
        const arg = args[i];
        if (arg.startsWith('--')) {
            const key = arg.replace('--', '');
            // Check if next arg is a value or another flag
            if (args[i + 1] && !args[i + 1].startsWith('--')) {
                params[key] = args[i + 1];
                i++; // Skip the value
            } else {
                params[key] = true; // It's a flag
            }
        }
    }

    const { url, file, sku, angle = 'front', sync } = params;

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

        // Fetch settings from WordPress if --sync is provided
        let settings = { ...DEFAULTS };
        
        if (sync) {
            console.error('Syncing settings from WordPress...');
            const wpSettings = await fetchSettingsFromWP();
            if (wpSettings) {
                settings = {
                    target_size: wpSettings.target_size || DEFAULTS.target_size,
                    padding: wpSettings.padding || DEFAULTS.padding,
                    aggressiveness: wpSettings.aggressiveness || DEFAULTS.aggressiveness,
                    naming: wpSettings.naming || DEFAULTS.naming
                };
                console.error(`Settings loaded: size=${settings.target_size}, padding=${settings.padding}, aggressiveness=${settings.aggressiveness}`);
            } else {
                console.error('Failed to sync settings, using defaults');
            }
        }

        // Allow CLI overrides
        if (params.target_size) settings.target_size = parseInt(params.target_size, 10);
        if (params.padding) settings.padding = parseFloat(params.padding);
        if (params.aggressiveness) settings.aggressiveness = parseInt(params.aggressiveness, 10);
        if (params.naming) settings.naming = params.naming;

        let inputSource;
        if (url) {
            console.error(`Processing URL: ${url}`);
            inputSource = url;
        } else {
            console.error(`Processing File: ${file}`);
            inputSource = path.resolve(file);
        }

        // 1. Remove Background (via helper process to avoid native conflicts)
        console.error('Removing background...');
        const cutoutTempPath = path.join(TEMP_DIR, `${sku}-${angle}-cutout.png`);
        
        try {
            execSync(`node "${path.join(__dirname, 'bg-remove-helper.js')}" "${inputSource}" "${cutoutTempPath}"`, { stdio: 'inherit' });
        } catch (e) {
            throw new Error('Background removal failed');
        }

        let cutoutBuffer = fs.readFileSync(cutoutTempPath);

        // 2. Apply aggressiveness threshold to alpha channel
        console.error(`Applying aggressiveness threshold: ${settings.aggressiveness}/100`);
        cutoutBuffer = await applyAggressiveness(cutoutBuffer, settings.aggressiveness);

        // 3. Process with Sharp - trim transparent edges
        const trimmed = await sharp(cutoutBuffer).trim().toBuffer({ resolveWithObject: true });
        
        const { width, height } = trimmed.info;
        
        // Calculate scale to fit in TARGET_SIZE with padding
        const maxDim = settings.target_size * (1 - settings.padding * 2);
        const scale = Math.min(maxDim / width, maxDim / height);
        
        const newWidth = Math.round(width * scale);
        const newHeight = Math.round(height * scale);

        // Resize the trimmed cutout
        const resizedCutout = await sharp(trimmed.data)
            .resize(newWidth, newHeight)
            .toBuffer();

        // Generate output filename
        const outputFilename = generateFilename(settings.naming, sku, angle);
        const outputPath = path.join(TEMP_DIR, outputFilename);
        
        // Create transparent background and composite the cutout in the center
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

        // Clean up temp cutout
        try { fs.unlinkSync(cutoutTempPath); } catch (e) { /* ignore */ }

        console.log(JSON.stringify({
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
                naming: settings.naming
            }
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
