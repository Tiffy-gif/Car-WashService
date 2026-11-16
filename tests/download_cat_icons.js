const https = require('https');
const fs = require('fs');
const path = require('path');

const icons = [
    { name: 'cat-all.png', file: 'img-14-Bq_mg9xG.png' },
    { name: 'cat-clothing.png', file: 'img-01-BBWp8t8E.png' },
    { name: 'cat-footwear.png', file: 'img-03-oTTY_McP.png' },
    { name: 'cat-watches.png', file: 'img-02-ClVfz9I5.png' },
    { name: 'cat-food.png', file: 'img-22-D2w1zjf6.png' },
    { name: 'cat-accessories.png', file: 'img-19-UVF-VI0Q.png' },
    { name: 'cat-electronics.png', file: 'img-24-Q6X8xUcA.png' },
    { name: 'cat-bags.png', file: 'img-08-BXmGY-HZ.png' },
    { name: 'cat-beauty.png', file: 'pr-37-CrN88d3y.png' },
    { name: 'cat-fitness.png', file: 'pr-40-pfTD79Wn.png' }
];

const baseUrl = 'https://srbthemes.kcubeinfotech.com/gotpos/html/ltr/assets/';
const destDir = path.join(__dirname, '..', 'pos', 'icons');

if (!fs.existsSync(destDir)) {
    fs.mkdirSync(destDir, { recursive: true });
}

function download(item) {
    return new Promise((resolve, reject) => {
        const destPath = path.join(destDir, item.name);
        const fileStream = fs.createWriteStream(destPath);
        https.get(baseUrl + item.file, (res) => {
            if (res.statusCode === 200) {
                res.pipe(fileStream);
                fileStream.on('finish', () => {
                    fileStream.close();
                    console.log('Saved:', item.name);
                    resolve();
                });
            } else {
                reject(new Error(`Failed ${item.file}: status ${res.statusCode}`));
            }
        }).on('error', (err) => {
            fs.unlink(destPath, () => {});
            reject(err);
        });
    });
}

(async () => {
    for (const item of icons) {
        try {
            await download(item);
        } catch (e) {
            console.error(e.message);
        }
    }
    console.log('All icons downloaded!');
})();
