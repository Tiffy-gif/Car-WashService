const http = require('http');

const DEVICE_KEY = 'my_test_device_key_unique9';
const DEVICE_UID = 'POS-CAASIER';

function loginUser(username, pin) {
    return new Promise((resolve, reject) => {
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
            res.on('end', () => resolve(JSON.parse(data)));
        });
        req.on('error', reject);
        req.write(payload);
        req.end();
    });
}

(async () => {
    const donaLogin = await loginUser('dona', '123456');
    console.log('Dona Login:', donaLogin.user.full_name, 'Token:', donaLogin.session_token);

    const payload = JSON.stringify({
        order_id: 95
    });

    const req = http.request({
        hostname: 'localhost',
        port: 80,
        path: '/Car-WashService/api/pos/index.php?action=receive_from_wash',
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Device-UID': DEVICE_UID,
            'X-Device-Key': DEVICE_KEY,
            'X-Staff-Token': donaLogin.session_token,
            'Content-Length': Buffer.byteLength(payload)
        }
    }, res => {
        let data = '';
        res.on('data', chunk => data += chunk);
        res.on('end', () => {
            console.log('Status code:', res.statusCode);
            console.log('Response:', data);
        });
    });
    req.write(payload);
    req.end();
})();
