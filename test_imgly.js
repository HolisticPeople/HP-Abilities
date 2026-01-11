const { removeBackground } = require('@imgly/background-removal-node');
const fs = require('fs');

async function test() {
    try {
        console.log('Starting...');
        const result = await removeBackground('https://www.dragonherbs.com/media/catalog/product/cache/bf00bc04d6e30f9130b13d5779134f80/5/1/515-prodshot-0325.png');
        console.log('Success!', result);
    } catch (e) {
        console.error('Error:', e);
    }
}

test();
