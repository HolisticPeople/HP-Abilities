const fs = require('fs');
const path = require('path');

async function upload() {
    const filePath = 'C:/DEV/hp-abilities/temp/DH515-front.png';
    const content = fs.readFileSync(filePath, { encoding: 'base64' });
    console.log(content);
}

upload();
