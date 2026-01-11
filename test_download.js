const axios = require('axios');
const fs = require('fs');

async function test() {
    const url = 'https://www.dragonherbs.com/media/catalog/product/cache/bf00bc04d6e30f9130b13d5779134f80/5/1/515-prodshot-0325.png';
    const response = await axios({
        url,
        method: 'GET',
        responseType: 'arraybuffer'
    });
    fs.writeFileSync('test.png', Buffer.from(response.data));
    console.log('Saved test.png');
}

test();
