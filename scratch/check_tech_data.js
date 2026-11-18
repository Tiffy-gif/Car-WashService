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
            res.on('end', () => resolve(JSON.parse(data)));
        });
        req.write(payload);
        req.end();
    });
}

function getTechJobs(token, userId) {
    return new Promise((resolve) => {
        const req = http.request({
            hostname: 'localhost',
            port: 80,
            path: `/Car-WashService/api/pos/index.php?action=get_technical_jobs&user_id=${userId}`,
            method: 'GET',
            headers: {
                'X-Device-UID': DEVICE_UID,
                'X-Device-Key': DEVICE_KEY,
                'X-Staff-Token': token
            }
        }, res => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(JSON.parse(data)));
        });
        req.end();
    });
}

(async () => {
    const donaLogin = await loginUser('dona', '123456');
    console.log('Dona user:', donaLogin.user);
    const techData = await getTechJobs(donaLogin.session_token, donaLogin.user.id);
    console.log('Tech data full:', techData);
})();
