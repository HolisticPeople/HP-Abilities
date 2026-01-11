/**
 * Helper script to isolate @imgly/background-removal-node from sharp
 */
const { removeBackground } = require('@imgly/background-removal-node');
const fs = require('fs');

async function run() {
    const source = process.argv[2];
    const outputPath = process.argv[3];
    
    try {
        const blob = await removeBackground(source);
        const buffer = Buffer.from(await blob.arrayBuffer());
        fs.writeFileSync(outputPath, buffer);
        process.exit(0);
    } catch (e) {
        console.error(e);
        process.exit(1);
    }
}

run();
