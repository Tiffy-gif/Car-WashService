<?php
declare(strict_types=1);

/**
 * Counter Thermal Printer Microservice (Port 1818)
 * Runs locally on the counter computer.
 * Allows cloud-hosted POS apps to print directly to local network/USB printers
 * without opening browser print dialogs, even if internet drops!
 *
 * Usage: php counter-printer-service.php
 */

$host = '127.0.0.1';
$port = 1818;

$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    echo "[-] Cannot start print bridge on {$host}:{$port}: {$errstr}\n";
    exit(1);
}

echo "[+] Counter Thermal Printer Bridge running on http://{$host}:{$port}\n";
echo "[+] Ready to print receipts directly to 80mm ESC/POS printers.\n";
echo "[+] Press Ctrl+C to stop.\n\n";

while (true) {
    $client = @stream_socket_accept($server, -1);
    if (!$client) continue;

    $request = '';
    while (!feof($client)) {
        $chunk = fread($client, 4096);
        $request .= $chunk;
        if (strpos($request, "\r\n\r\n") !== false) {
            // Check if Content-Length exists
            if (preg_match('/Content-Length:\s*(\d+)/i', $request, $m)) {
                $contentLen = (int)$m[1];
                $pos = strpos($request, "\r\n\r\n") + 4;
                while (strlen($request) - $pos < $contentLen && !feof($client)) {
                    $request .= fread($client, 4096);
                }
            }
            break;
        }
    }

    // CORS preflight
    if (str_starts_with($request, 'OPTIONS')) {
        $resp = "HTTP/1.1 200 OK\r\n"
              . "Access-Control-Allow-Origin: *\r\n"
              . "Access-Control-Allow-Methods: GET, POST, OPTIONS\r\n"
              . "Access-Control-Allow-Headers: Content-Type, Accept\r\n"
              . "Content-Length: 0\r\n\r\n";
        fwrite($client, $resp);
        fclose($client);
        continue;
    }

    // Parse body
    $bodyPos = strpos($request, "\r\n\r\n");
    $body = $bodyPos !== false ? substr($request, $bodyPos + 4) : '';
    $input = json_decode($body, true) ?? [];

    $action = $input['action'] ?? 'print';
    $ip = trim((string)($input['ip'] ?? '192.168.1.200'));
    $printerPort = (int)($input['port'] ?? 9100);

    $jsonResult = ['success' => false];
    $httpCode = 200;

    if ($action === 'test_connection') {
        $sock = @fsockopen($ip, $printerPort, $errno, $errstr, 2.5);
        if ($sock) {
            fclose($sock);
            $jsonResult = ['success' => true, 'message' => "Printer online at {$ip}:{$printerPort}"];
        } else {
            $httpCode = 502;
            $jsonResult = ['success' => false, 'error' => "Cannot connect to {$ip}:{$printerPort}: {$errstr}"];
        }
    } elseif ($action === 'print') {
        $base64 = $input['data'] ?? '';
        $binary = base64_decode($base64);
        if (!$binary) {
            $httpCode = 400;
            $jsonResult = ['success' => false, 'error' => 'Invalid print payload'];
        } else {
            $sock = @fsockopen($ip, $printerPort, $errno, $errstr, 3.0);
            if ($sock) {
                stream_set_timeout($sock, 4);
                fwrite($sock, $binary);
                fflush($sock);
                usleep(50000);
                fclose($sock);
                $jsonResult = ['success' => true, 'bytes_sent' => strlen($binary)];
            } else {
                $httpCode = 502;
                $jsonResult = ['success' => false, 'error' => "Cannot reach printer at {$ip}:{$printerPort}: {$errstr}"];
            }
        }
    } else {
        $jsonResult = ['success' => true, 'service' => 'Counter Thermal Printer Bridge 1.0', 'status' => 'online'];
    }

    $resPayload = json_encode($jsonResult);
    $resp = "HTTP/1.1 {$httpCode} OK\r\n"
          . "Content-Type: application/json; charset=utf-8\r\n"
          . "Access-Control-Allow-Origin: *\r\n"
          . "Content-Length: " . strlen($resPayload) . "\r\n\r\n"
          . $resPayload;
    fwrite($client, $resp);
    fclose($client);
}
