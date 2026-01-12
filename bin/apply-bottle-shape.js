/**
 * Apply a clean bottle shape edge to the mask
 * Uses detected bottle shape from original, applies as hard edge
 * 
 * Usage: node bin/apply-bottle-shape.js <mask> <original>
 */
const sharp = require('sharp');

async function applyBottleShape() {
    const maskPath = process.argv[2] || 'temp/DH515-front-mask.png';
    const origPath = process.argv[3] || 'temp/DH515-front-input.png';
    
    const { data: maskData, info } = await sharp(maskPath).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
    const { data: origData } = await sharp(origPath).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
    const { width, height } = info;
    
    // Detect background
    const bgSamples = [];
    for (let y = 0; y < 20; y++) {
        for (let x = 0; x < 20; x++) {
            const idx = (y * width + x) * 4;
            bgSamples.push((origData[idx] + origData[idx + 1] + origData[idx + 2]) / 3);
        }
    }
    const bgLum = Math.round(bgSamples.reduce((a, b) => a + b) / bgSamples.length);
    
    console.error('Background luminosity:', bgLum);
    
    // Find bottle shape by region
    const getRegionMedian = (startY, endY, searchType) => {
        const edges = [];
        for (let y = startY; y < endY; y++) {
            for (let x = 100; x < 400; x++) {
                const idx = (y * width + x) * 4;
                const lum = (origData[idx] + origData[idx + 1] + origData[idx + 2]) / 3;
                
                let isProduct = false;
                if (searchType === 'dark') isProduct = lum < bgLum - 60;
                else if (searchType === 'white') isProduct = lum > bgLum + 15;
                else isProduct = lum < bgLum - 60 || lum > bgLum + 15;
                
                if (isProduct) {
                    edges.push(x);
                    break;
                }
            }
        }
        if (edges.length === 0) return -1;
        edges.sort((a, b) => a - b);
        // Use Q1 (25th percentile) to be conservative
        return edges[Math.floor(edges.length * 0.25)];
    };
    
    // Define bottle regions
    const regions = [
        { start: 100, end: 180, type: 'dark', name: 'cap' },
        { start: 180, end: 280, type: 'both', name: 'shoulder' },
        { start: 280, end: 600, type: 'white', name: 'label' },
        { start: 600, end: 700, type: 'dark', name: 'base' }
    ];
    
    // Calculate edge position for each region
    const regionEdges = {};
    for (const r of regions) {
        regionEdges[r.name] = getRegionMedian(r.start, r.end, r.type);
        console.error(`${r.name} (rows ${r.start}-${r.end}): edge at x=${regionEdges[r.name]}`);
    }
    
    // Interpolate edge position for each row
    const getEdgeForRow = (y) => {
        if (y < 180) return regionEdges.cap;
        if (y < 280) {
            // Smooth transition from cap to label
            const t = (y - 180) / 100;
            const capEdge = regionEdges.cap || 280;
            const labelEdge = regionEdges.label || 240;
            // Shoulder curves out then back
            const shoulderEdge = regionEdges.shoulder || (capEdge + labelEdge) / 2;
            if (t < 0.5) {
                // Cap to shoulder
                return Math.round(capEdge + (shoulderEdge - capEdge) * (t * 2));
            } else {
                // Shoulder to label
                return Math.round(shoulderEdge + (labelEdge - shoulderEdge) * ((t - 0.5) * 2));
            }
        }
        if (y < 600) return regionEdges.label;
        return regionEdges.base || regionEdges.label;
    };
    
    let pixelsModified = 0;
    
    // Apply clean edge
    for (let y = 0; y < height; y++) {
        const edgeX = getEdgeForRow(y);
        if (edgeX < 0) continue;
        
        for (let x = 0; x < width; x++) {
            const idx = (y * width + x) * 4;
            
            if (x < edgeX - 2) {
                // Left of edge - make transparent
                if (maskData[idx + 3] > 0) {
                    maskData[idx + 3] = 0;
                    pixelsModified++;
                }
            } else if (x >= edgeX) {
                // Right of edge - make opaque with original colors
                if (maskData[idx + 3] > 0 && maskData[idx + 3] < 255) {
                    maskData[idx] = origData[idx];
                    maskData[idx + 1] = origData[idx + 1];
                    maskData[idx + 2] = origData[idx + 2];
                    maskData[idx + 3] = 255;
                    pixelsModified++;
                }
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
        pixels_modified: pixelsModified,
        regions: regionEdges
    }));
}

applyBottleShape().catch(e => console.error(e));
