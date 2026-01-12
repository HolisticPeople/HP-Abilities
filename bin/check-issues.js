/**
 * Check remaining edge issues between mask and original
 */
const sharp = require('sharp');

async function check() {
    const maskPath = process.argv[2] || 'temp/DH515-front-mask.png';
    const origPath = process.argv[3] || 'temp/DH515-front-input.png';
    
    const { data: maskData } = await sharp(maskPath).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
    const { data: origData, info } = await sharp(origPath).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
    const { width } = info;
    
    let issueCount = 0;
    let issues = [];
    
    for (let y = 150; y <= 600; y++) {
        let maskEdge = -1, origEdge = -1;
        
        for (let x = 0; x < width; x++) {
            if (maskData[(y * width + x) * 4 + 3] > 0) { maskEdge = x; break; }
        }
        for (let x = 200; x < 400; x++) {
            const idx = (y * width + x) * 4;
            const lum = (origData[idx] + origData[idx + 1] + origData[idx + 2]) / 3;
            if (lum < 180) { origEdge = x; break; }
        }
        
        const diff = maskEdge - origEdge;
        if (diff < -3) {
            issueCount++;
            issues.push({ y, maskEdge, origEdge, diff });
        }
    }
    
    console.log('=== EDGE ISSUE CHECK ===');
    console.log('Remaining deep cut issues (>3px):', issueCount);
    
    if (issueCount === 0) {
        console.log('✓ LEFT EDGE IS CLEAN!');
    } else {
        console.log('\nProblem rows:');
        issues.slice(0, 15).forEach(i => {
            console.log(`  Row ${i.y}: mask=${i.maskEdge}, orig=${i.origEdge}, diff=${i.diff}`);
        });
        if (issues.length > 15) console.log(`  ... and ${issues.length - 15} more`);
    }
}

check().catch(e => console.error(e));
