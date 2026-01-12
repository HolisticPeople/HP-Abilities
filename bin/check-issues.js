/**
 * Check remaining edge issues between mask and original
 * Properly detects BOTH dark body AND white label edges
 */
const sharp = require('sharp');

async function check() {
    const maskPath = process.argv[2] || 'temp/DH515-front-mask.png';
    const origPath = process.argv[3] || 'temp/DH515-front-input.png';
    
    const { data: maskData } = await sharp(maskPath).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
    const { data: origData, info } = await sharp(origPath).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
    const { width, height } = info;
    
    // Detect background color from corners
    const bgSamples = [];
    for (let y = 0; y < 20; y++) {
        for (let x = 0; x < 20; x++) {
            const idx = (y * width + x) * 4;
            bgSamples.push((origData[idx] + origData[idx + 1] + origData[idx + 2]) / 3);
        }
    }
    const bgLum = Math.round(bgSamples.reduce((a, b) => a + b) / bgSamples.length);
    
    console.log('=== EDGE ISSUE CHECK ===');
    console.log('Background luminosity:', bgLum);
    console.log('');
    
    let issueCount = 0;
    let issues = [];
    
    for (let y = 100; y < height - 100; y++) {
        // Find AI mask edge
        let maskEdge = -1;
        for (let x = 0; x < width; x++) {
            if (maskData[(y * width + x) * 4 + 3] > 0) {
                maskEdge = x;
                break;
            }
        }
        
        // Find TRUE product edge - first pixel significantly different from background
        // Could be DARK body (lum < 100) OR WHITE label (lum > bgLum + 20)
        let origEdge = -1;
        for (let x = 50; x < 400; x++) {
            const idx = (y * width + x) * 4;
            const r = origData[idx], g = origData[idx + 1], b = origData[idx + 2];
            const lum = (r + g + b) / 3;
            
            // Significantly different from background
            if (lum < bgLum - 80 || lum > bgLum + 20) {
                origEdge = x;
                break;
            }
        }
        
        if (maskEdge >= 0 && origEdge >= 0) {
            const diff = maskEdge - origEdge;
            if (diff > 3) { // AI edge is MORE THAN 3px to the right of actual edge
                issueCount++;
                issues.push({ y, maskEdge, origEdge, diff });
            }
        }
    }
    
    console.log('Remaining issues (AI missed >3px of product):', issueCount);
    
    if (issueCount === 0) {
        console.log('✓ LEFT EDGE IS CLEAN!');
    } else {
        console.log('\nProblem rows (AI edge too far right):');
        
        // Group into ranges
        let ranges = [];
        let start = issues[0].y, end = issues[0].y;
        let avgTarget = issues[0].origEdge;
        
        for (let i = 1; i < issues.length; i++) {
            if (issues[i].y <= end + 3) {
                end = issues[i].y;
            } else {
                const rangeIssues = issues.filter(x => x.y >= start && x.y <= end);
                const minTarget = Math.min(...rangeIssues.map(x => x.origEdge));
                ranges.push({ from: start, to: end, target: minTarget });
                start = issues[i].y;
                end = issues[i].y;
            }
        }
        // Add last range
        const rangeIssues = issues.filter(x => x.y >= start && x.y <= end);
        const minTarget = Math.min(...rangeIssues.map(x => x.origEdge));
        ranges.push({ from: start, to: end, target: minTarget });
        
        console.log('\nRecommended corrections:');
        ranges.slice(0, 10).forEach(r => {
            console.log(`  node bin/edit-mask.js --mask ${maskPath} --original ${origPath} --left-edge ${r.target} --from-row ${r.from} --to-row ${r.to} --blend-zone 10`);
        });
        if (ranges.length > 10) {
            console.log(`  ... and ${ranges.length - 10} more ranges`);
        }
    }
}

check().catch(e => console.error(e));
