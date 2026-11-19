const http = require('http');

const DEVICE_KEY = 'my_test_device_key_unique9';
const DEVICE_UID = 'POS-CAASIER';

function requestApi(action, payload, token) {
    return new Promise((resolve, reject) => {
        const body = JSON.stringify(payload);
        const headers = {
            'Content-Type': 'application/json',
            'X-Device-UID': DEVICE_UID,
            'X-Device-Key': DEVICE_KEY,
            'Content-Length': Buffer.byteLength(body)
        };
        if (token) headers['X-Staff-Token'] = token;

        const req = http.request({
            hostname: 'localhost',
            port: 80,
            path: `/Car-WashService/api/pos/index.php?action=${action}`,
            method: 'POST',
            headers
        }, res => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve({ status: res.statusCode, data: JSON.parse(data) }));
        });
        req.on('error', reject);
        req.write(body);
        req.end();
    });
}

(async () => {
    const loginRes = await requestApi('staff_login', { username: 'dona', pin: '123456' });
    const token = loginRes.data.session_token;

    console.log('1. Passing all inspection points...');
    const passRes = await requestApi('pass_all_inspection', { order_id: 95 }, token);
    console.log('Pass All Res:', passRes);

    console.log('2. Finishing technical job on hoist (Bay 4)...');
    const finishRes = await requestApi('finish_job', { bay_id: 4, order_id: 95 }, token);
    console.log('Finish Job Res:', finishRes);

    console.log('3. Checking queues after hoist finish...');
    const baysRes = await requestApi('get_bays_and_queue', {}, token);
    console.log('Wash queue count:', baysRes.data.wash_queue.length);
    if (baysRes.data.wash_queue.length > 0) {
        console.log('Next wash car:', baysRes.data.wash_queue[0].plate_number, 'Services:', baysRes.data.wash_queue[0].service_names);
    }
})();
