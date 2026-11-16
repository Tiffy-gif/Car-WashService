const http = require('http');

http.get('http://127.0.0.1/Car-WashService/api/sync_catalog.php', (res) => {
    let data = '';
    res.on('data', c => data += c);
    res.on('end', () => {
        try {
            const json = JSON.parse(data);
            console.log('Categories:', json.categories);
        } catch (e) {
            console.log('Raw output:', data.slice(0, 300));
        }
    });
}).on('error', e => console.error(e.message));
