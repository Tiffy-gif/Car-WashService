/**
 * Counter Thermal Printer Microservice (Node.js - Port 1818)
 * Runs locally on the counter computer.
 * Usage: node counter-printer-service.js
 */
const http = require('http');
const net = require('net');

const PORT = 1818;
const HOST = '127.0.0.1';

function isShopNetworkIp(ip) {
    const s = String(ip).trim();
    if (s === 'localhost' || s === '127.0.0.1') return true;
    const parts = s.split('.').map(Number);
    if (parts.length !== 4 || parts.some(isNaN)) return false;
    if (parts[0] === 127) return true;
    if (parts[0] === 10) return true;
    if (parts[0] === 172 && parts[1] >= 16 && parts[1] <= 31) return true;
    if (parts[0] === 192 && parts[1] === 168) return true;
    return false;
}

const server = http.createServer((req, res) => {
    // Only allow local origin
    const origin = req.headers.origin || '';
    if (origin) {
        try {
            const u = new URL(origin);
            if (['localhost', '127.0.0.1'].includes(u.hostname)) {
                res.setHeader('Access-Control-Allow-Origin', origin);
            }
        } catch (e) {}
    }
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Accept');

    if (req.method === 'OPTIONS') {
        res.writeHead(200);
        res.end();
        return;
    }

    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
        let input = {};
        try {
            if (body) input = JSON.parse(body);
        } catch (e) {}

        const action = input.action || 'print';
        const ip = (input.ip || '192.168.1.200').trim();
        const port = parseInt(input.port) || 9100;

        if (port !== 9100) {
            res.writeHead(400, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ success: false, error: 'Only printer port 9100 is allowed' }));
            return;
        }

        if (!isShopNetworkIp(ip)) {
            res.writeHead(400, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ success: false, error: 'Only shop network printer IPs are allowed' }));
            return;
        }

        if (action === 'test_connection') {
            const socket = new net.Socket();
            socket.setTimeout(2500);

            socket.connect(port, ip, () => {
                socket.destroy();
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: true, message: `Printer online at ${ip}:${port}` }));
            });

            socket.on('error', (err) => {
                socket.destroy();
                res.writeHead(502, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: false, error: `Cannot reach ${ip}:${port}: ${err.message}` }));
            });

            socket.on('timeout', () => {
                socket.destroy();
                res.writeHead(502, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: false, error: `Connection to ${ip}:${port} timed out (2.5s)` }));
            });
        } else if (action === 'print') {
            const base64 = input.data || '';
            const buffer = Buffer.from(base64, 'base64');
            if (!buffer || buffer.length === 0) {
                res.writeHead(400, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: false, error: 'Empty print payload' }));
                return;
            }

            const socket = new net.Socket();
            socket.setTimeout(3500);

            socket.connect(port, ip, () => {
                socket.write(buffer, () => {
                    setTimeout(() => {
                        socket.end();
                        res.writeHead(200, { 'Content-Type': 'application/json' });
                        res.end(JSON.stringify({ success: true, bytes_sent: buffer.length }));
                    }, 50);
                });
            });

            socket.on('error', (err) => {
                socket.destroy();
                res.writeHead(502, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: false, error: `Cannot reach printer at ${ip}:${port}: ${err.message}` }));
            });

            socket.on('timeout', () => {
                socket.destroy();
                res.writeHead(502, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: false, error: `Print timeout to ${ip}:${port}` }));
            });
        } else {
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ success: true, service: 'Counter Thermal Printer Bridge 1.0 (Node.js)' }));
        }
    });
});

server.listen(PORT, HOST, () => {
    console.log(`[+] Counter Thermal Printer Microservice running at http://${HOST}:${PORT}`);
    console.log(`[+] Ready for direct ESC/POS network printing.`);
});
