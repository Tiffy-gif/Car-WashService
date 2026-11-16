const http = require('http');

function makeRequest(headers, body) {
    return new Promise((resolve, reject) => {
        const postData = JSON.stringify(body);
        const req = http.request({
            hostname: '127.0.0.1',
            port: 80,
            path: '/Car-WashService/api/pos/index.php',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(postData),
                ...headers
            }
        }, (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => {
                resolve({ status: res.statusCode, data });
            });
        });
        req.on('error', reject);
        req.write(postData);
        req.end();
    });
}

(async () => {
    console.log('--- Test 1: With the deleted device key (pos_sec_9e48c62ea374bbfb8edb1b5d79925e6c) ---');
    const res1 = await makeRequest({
        'X-Device-Key': 'pos_sec_9e48c62ea374bbfb8edb1b5d79925e6c'
    }, { action: 'sync_catalog' });
    console.log('Res 1 Status:', res1.status, res1.data);

    console.log('\n--- Test 2: With an unknown/bogus key ---');
    const res2 = await makeRequest({
        'X-Device-Key': 'pos_sec_invalid_key_1234567890'
    }, { action: 'sync_catalog' });
    console.log('Res 2 Status:', res2.status, res2.data);

    console.log('\n--- Test 3: Without any device key ---');
    const res3 = await makeRequest({}, { action: 'sync_catalog' });
    console.log('Res 3 Status:', res3.status, res3.data);
})();
