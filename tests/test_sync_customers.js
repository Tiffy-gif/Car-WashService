const http = require('http');

const req = http.request({
  hostname: 'localhost',
  port: 80,
  path: '/Car-WashService/api/pos/index.php?action=sync_customers',
  method: 'GET'
}, (res) => {
  let data = '';
  res.on('data', chunk => data += chunk);
  res.on('end', () => {
    console.log('Status:', res.statusCode);
    try {
      const parsed = JSON.parse(data);
      console.log('Success:', parsed.success);
      console.log('Customers count:', parsed.customers?.length);
      console.log('Vehicles count:', parsed.vehicles?.length);
      if (parsed.customers?.length) {
        console.log('Sample customer:', parsed.customers[0]);
      }
    } catch (e) {
      console.log('Response not json:', data.substring(0, 300));
    }
  });
});
req.on('error', (e) => console.error(e));
req.end();
