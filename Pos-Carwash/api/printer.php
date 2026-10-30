<?php
declare(strict_types=1);

/**
 * POS Local Thermal Printer Bridge (ESC/POS 80mm)
 * Connects directly to thermal receipt printers via raw TCP socket (port 9100)
 * Security Hardened:
 * - Only accessible from the local counter computer itself (not other computers)
 * - Blocked from other external websites (CORS & Sec-Fetch-Site validation)
 * - Only allowed to connect to printer addresses within the shop local network (RFC 1918 / Loopback)
 * - Only allowed on the standard thermal printer port (9100)
 */

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// 1. IP Security: Only allow requests originating from the counter computer itself
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$serverIp = $_SERVER['SERVER_ADDR'] ?? '';
$isLocalComputer = in_array($remoteIp, ['127.0.0.1', '::1'], true) || ($serverIp !== '' && $remoteIp === $serverIp);

if (!$isLocalComputer) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'FORBIDDEN_REMOTE_CLIENT',
        'message' => 'Printer bridge is only accessible from the local counter computer itself.'
    ]);
    exit;
}

// 2. Cross-Site Security: Disallow calls from external/other websites (No open wildcard CORS)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin)) {
    $originHost = strtolower(parse_url($origin, PHP_URL_HOST) ?? '');
    $serverHost = strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST) ?? '');
    $isAllowedOrigin = in_array($originHost, ['localhost', '127.0.0.1', $serverHost], true);
    
    if (!$isAllowedOrigin) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'FORBIDDEN_EXTERNAL_ORIGIN',
            'message' => 'Requests from external websites are strictly prohibited.'
        ]);
        exit;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
}

// Check Sec-Fetch-Site if provided by modern browsers
$fetchSite = strtolower($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
if ($fetchSite === 'cross-site') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'FORBIDDEN_CROSS_SITE',
        'message' => 'Cross-site requests are strictly prohibited.'
    ]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
if (!empty($_POST)) {
    $input = array_merge($input, $_POST);
}

$action = $input['action'] ?? $_GET['action'] ?? 'print';
$ip = trim((string)($input['ip'] ?? '192.168.1.200'));
$port = (int)($input['port'] ?? 9100);

// 3. Port Restriction: Only allow the standard thermal printer port (9100)
if ($port !== 9100) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'INVALID_PRINTER_PORT',
        'message' => 'Only the thermal printer port (9100) is allowed.'
    ]);
    exit;
}

// 4. IP Validation: Only allow printer addresses inside the shop local network (RFC 1918 & Loopback)
function isShopNetworkIp(string $ip): bool {
    $normalized = trim($ip);
    if ($normalized === 'localhost' || $normalized === '127.0.0.1') {
        return true;
    }
    if (!filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    $parts = array_map('intval', explode('.', $normalized));
    if (count($parts) !== 4) {
        return false;
    }
    // 127.0.0.0/8 (Loopback / Local printer bridge)
    if ($parts[0] === 127) {
        return true;
    }
    // 10.0.0.0/8 (Class A private shop network)
    if ($parts[0] === 10) {
        return true;
    }
    // 172.16.0.0/12 (Class B private shop network)
    if ($parts[0] === 172 && $parts[1] >= 16 && $parts[1] <= 31) {
        return true;
    }
    // 192.168.0.0/16 (Class C private shop network)
    if ($parts[0] === 192 && $parts[1] === 168) {
        return true;
    }
    return false;
}

if (!isShopNetworkIp($ip)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'INVALID_SHOP_NETWORK_IP',
        'message' => 'Only printer addresses within the shop local network (e.g. 192.168.x.x, 10.x.x.x, or 127.0.0.1) are allowed.'
    ]);
    exit;
}

// 1. Action: Test connection to printer IP
if ($action === 'test_connection') {
    if (empty($ip)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide a printer IP address.']);
        exit;
    }

    $socket = @fsockopen($ip, $port, $errno, $errstr, 2.5);
    if ($socket) {
        fclose($socket);
        echo json_encode([
            'success' => true,
            'message' => "Successfully connected to thermal printer at {$ip}:{$port}!",
            'ip' => $ip,
            'port' => $port
        ]);
    } else {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => "Cannot connect to printer at {$ip}:{$port} ({$errstr})",
            'hint' => 'Make sure printer power is ON, network cable is plugged in, and printer IP matches.'
        ]);
    }
    exit;
}

// 2. Action: Print raw ESC/POS binary stream
if ($action === 'print') {
    $base64Data = (string)($input['data'] ?? '');
    if (empty($base64Data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Print data is missing.']);
        exit;
    }

    $binaryData = base64_decode($base64Data);
    if ($binaryData === false || strlen($binaryData) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid print payload format.']);
        exit;
    }

    // Open raw TCP socket to thermal printer
    $socket = @fsockopen($ip, $port, $errno, $errstr, 3.0);
    if (!$socket) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error' => "Cannot reach thermal printer at {$ip}:{$port} ({$errstr})",
            'hint' => 'Please check printer power, paper roll, and network cable.'
        ]);
        exit;
    }

    stream_set_timeout($socket, 4);
    $bytesWritten = 0;
    $totalLen = strlen($binaryData);
    
    // Write in chunks to prevent socket buffer congestion
    $chunkSize = 2048;
    for ($i = 0; $i < $totalLen; $i += $chunkSize) {
        $chunk = substr($binaryData, $i, $chunkSize);
        $w = fwrite($socket, $chunk);
        if ($w === false) {
            fclose($socket);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Error writing data to printer socket.']);
            exit;
        }
        $bytesWritten += $w;
    }

    fflush($socket);
    // Small delay before closing to let printer FIFO buffer ingest final cut command
    usleep(50000); // 50ms
    fclose($socket);

    echo json_encode([
        'success' => true,
        'message' => 'Receipt sent directly to thermal printer successfully!',
        'bytes_sent' => $bytesWritten,
        'ip' => $ip,
        'port' => $port
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown printer action.']);
