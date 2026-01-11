const sharp = require('sharp');
async function test() {
    await sharp('C:/DEV/hp-abilities/temp/DH515-front.png')
        .resize(100, 100)
        .toFile('C:/DEV/hp-abilities/temp/DH515-small.png');
}
test();
