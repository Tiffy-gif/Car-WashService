const http = require('http');

const DEVICE_KEY = 'my_test_device_key_unique9';
const DEVICE_UID = 'POS-CAASIER';

function loginUser(username, pin) {
    return new Promise((resolve) => {
        const payload = JSON.stringify({ username, pin });
        const req = http.request({
            hostname: 'localhost',
            port: 80,
            path: '/Car-WashService/api/pos/index.php?action=staff_login',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Device-UID': DEVICE_UID,
                'X-Device-Key': DEVICE_KEY,
                'Content-Length': Buffer.byteLength(payload)
            }
        }, res => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => {
                try {
                    resolve(JSON.parse(data));
                } catch(e) {
                    resolve({ error: data });
                }
            });
        });
        req.write(payload);
        req.end();
    });
}

(async () => {
    const resVisal = await loginUser('visal', '123456');
    console.log('visal permissions:', resVisal.user?.permissions);
    const resOwner = await loginUser('owner', '123456');
    console.log('owner permissions:', resOwner.user?.permissions);
    const resDona = await loginUser('dona', '123456');
    console.log('dona (mechanic) permissions:', resDona.user?.permissions);
})();
