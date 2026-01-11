/**
 * Analyze mask edges for remaining issues
 */
const sharp = require('sharp');

async function analyze() {
    const maskPath = process.argv[2] || 'temp/DH515-front-mask.png';
    
    const { data, info } = await sharp(maskPath).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
    const { width, height } = info;
    
    const leftEdges = [];
    const rightEdges = [];
    
    for (let y = 0; y < height; y++) {
        let left = -1;
        let right = -1;
        
        for (let x = 0; x < width; x++) {
            if (data[(y * width + x) * 4 + 3] > 0) {
                left = x;
                break;
            }
        }
        
        for (let x = width - 1; x >= 0; x--) {
            if (data[(y * width + x) * 4 + 3] > 0) {
                right = x;
                break;
            }
        }
        
        if (left >= 0) {
            leftEdges.push({ y, x: left });
            rightEdges.push({ y, x: right });
        }
    }
    
    // Analyze body region
    const bodyRows = leftEdges.filter(e => e.y >= 200 && e.y <= 600);
    if (bodyRows.length === 0) {
        console.log('No content in body region');
        return;
    }
    
    const minX = Math.min(...bodyRows.map(e => e.x));
    const problems = bodyRows.filter(e => e.x > minX + 2);
    
    console.log('=== MASK ANALYSIS ===');
    console.log('Dimensions:', width, 'x', height);
    console.log('Content rows:', leftEdges.length);
    console.log('');
    console.log('Left edge analysis (body rows 200-600):');
    console.log('  Expected edge (min):', minX);
    console.log('  Rows with issues (>2px off):', problems.length);
    
    if (problems.length === 0) {
        console.log('  ✓ Left edge is CLEAN!');
    } else {
        console.log('  Problem range: y=' + problems[0].y + ' to y=' + problems[problems.length-1].y);
        console.log('  Max deviation:', Math.max(...problems.map(p => p.x)) - minX, 'px');
    }
}

analyze().catch(e => console.error(e));
