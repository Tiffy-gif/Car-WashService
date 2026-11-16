const https = require('https');
const fs = require('fs');

const url = 'https://srbthemes.kcubeinfotech.com/gotpos/html/ltr/assets/img-14-Bq_mg9xG.png';
https.get(url, (res) => {
    console.log('Status code:', res.statusCode);
    if (res.statusCode === 200) {
        const chunks = [];
        res.on('data', c => chunks.push(c));
        res.on('end', () => {
            const buf = Buffer.concat(chunks);
            console.log('Downloaded bytes:', buf.length);
        });
    }
}).on('error', e => console.error(e.message));
