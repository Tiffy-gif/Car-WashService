<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/core/Database.php';

$pdo = Database::getInstance();
// Fetch active POS device API key and URL from environment or database defaults
$defaultApiKey = getenv('POS_DEVICE_KEY') ?: ($_ENV['POS_DEVICE_KEY'] ?? '');
$defaultUid = 'POS-TERMINAL-01';
if (!$defaultApiKey) {
    $sampleDev = $pdo->query("SELECT * FROM pos_devices WHERE status = 'active' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $defaultApiKey = $sampleDev['api_key'] ?? '';
    $defaultUid = $sampleDev['device_uid'] ?? 'POS-TERMINAL-01';
}
$defaultApiUrl = getenv('POS_URL') ?: ($_ENV['POS_URL'] ?? (API_BASE_URL . '/api/pos/'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Independent POS Client Simulator - Car Wash Pro</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/font-awesome.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;600;700&family=JetBrains+Mono:wght@400;600;700&display=swap');
        :root {
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --primary: #0284c7;
            --primary-hover: #0369a1;
            --success: #16a34a;
            --danger: #dc2626;
            --radius-md: 10px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Kantumruy Pro', system-ui, sans-serif; background: #0f172a; color: #f8fafc; padding: 24px; min-height: 100vh; }
        .wrapper { max-width: 1200px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid #334155; padding-bottom: 16px; flex-wrap: wrap; gap: 12px; }
        .badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 99px; font-size: 12px; font-weight: 700; }
        .badge-pos { background: #0284c7; color: #fff; }
        .badge-live { background: #16a34a; color: #fff; }
        .btn { cursor: pointer; padding: 9px 16px; font-size: 13.5px; font-weight: 600; border-radius: 8px; border: none; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; transition: 0.15s ease; }
        .btn-primary { background: #0284c7; color: #fff; }
        .btn-primary:hover { background: #0369a1; }
        .btn-success { background: #16a34a; color: #fff; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-secondary { background: #334155; color: #f8fafc; }
        .btn-secondary:hover { background: #475569; }
        .grid-layout { display: grid; grid-template-columns: 1fr 1.1fr; gap: 24px; }
        .pos-panel { background: #1e293b; border: 1px solid #334155; border-radius: var(--radius-md); padding: 20px; }
        .panel-title { font-size: 16px; font-weight: 700; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; color: #e2e8f0; }
        .form-group { margin-bottom: 14px; }
        .form-label { display: block; font-size: 12px; font-weight: 700; color: #94a3b8; margin-bottom: 5px; text-transform: uppercase; }
        .form-input, .form-select { width: 100%; background: #0f172a; border: 1px solid #334155; color: #f8fafc; padding: 10px 14px; border-radius: 6px; font-size: 14px; outline: none; }
        .form-input:focus, .form-select:focus { border-color: #0284c7; }
        .log-box { background: #090d16; border: 1px solid #334155; border-radius: 6px; padding: 14px; font-family: 'JetBrains Mono', monospace; font-size: 12px; max-height: 480px; overflow-y: auto; color: #38bdf8; white-space: pre-wrap; word-break: break-all; }
        .item-chip { display: inline-flex; align-items: center; gap: 6px; background: #334155; padding: 6px 12px; border-radius: 6px; font-size: 12px; margin: 3px; cursor: pointer; user-select: none; }
        .item-chip.selected { background: #0284c7; color: #fff; font-weight: 700; }
        @media (max-width: 900px) { .grid-layout { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<div class="wrapper">
    <!-- Top Bar -->
    <div class="header">
        <div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="badge badge-pos"><i class="fa-solid fa-tablet-screen-button mr-1"></i> EXTERNAL POS APP</span>
                <span class="badge badge-live" id="statusBadge"><i class="fa-solid fa-circle-dot mr-1"></i> READY</span>
            </div>
            <h1 style="font-size: 20px; font-weight: 700; margin-top: 6px;">Independent POS Client Simulator</h1>
            <p style="font-size: 13px; color: #94a3b8;">Simulates a physical Android/iPad counter terminal calling the central Car Wash Pro API.</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="<?= APP_URL ?>/index.php?view=pos_devices" class="btn btn-secondary">
                <i class="fa-solid fa-gear mr-1"></i> Owner Device Whitelist
            </a>
            <a href="<?= APP_URL ?>/index.php?view=orders" class="btn btn-primary">
                <i class="fa-solid fa-list-ol mr-1"></i> Live Web Orders Queue
            </a>
        </div>
    </div>

    <!-- 1. Connection Config Card -->
    <div class="pos-panel" style="margin-bottom: 20px; border-left: 5px solid #0284c7;">
        <div class="panel-title">
            <span><i class="fa-solid fa-plug mr-1" style="color: #0284c7;"></i> 1. POS Device Connection Settings</span>
            <span style="font-size: 11px; color: #94a3b8;">Easy to switch from Localhost to Cloud</span>
        </div>
        <div style="display: grid; grid-template-columns: 2fr 1.5fr 1fr 1fr 1fr; gap: 14px;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Central System API Endpoint</label>
                <input type="text" id="apiEndpoint" class="form-input" value="<?= htmlspecialchars($defaultApiUrl) ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">POS Device API Key (Secret)</label>
                <input type="text" id="deviceApiKey" class="form-input" value="<?= htmlspecialchars($defaultApiKey) ?>" style="font-family: 'JetBrains Mono', monospace;">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Staff User</label>
                <input type="text" id="staffUsername" class="form-input" value="admin">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Staff PIN</label>
                <input type="password" id="staffPin" class="form-input" value="123456" maxlength="6">
            </div>
            <div style="display: flex; align-items: flex-end;">
                <button type="button" class="btn btn-primary" style="width: 100%; height: 42px;" onclick="testConnection()">
                    <i class="fa-solid fa-wifi mr-1"></i> Test Link
                </button>
            </div>
        </div>
    </div>

    <div class="grid-layout">
        
        <!-- Left Column: POS Actions UI -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <!-- 2. Staff Authentication Card -->
            <div class="pos-panel">
                <div class="panel-title">
                    <span><i class="fa-solid fa-user-lock mr-1" style="color: #38bdf8;"></i> 2. Staff Login (Cashier / Owner)</span>
                    <span id="loggedInStaffBadge" class="text-xs text-muted">Not logged in</span>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr 120px; gap: 10px;">
                    <div>
                        <input type="text" id="staffUser" class="form-input" placeholder="username" value="cashier">
                    </div>
                    <div>
                        <input type="password" id="staffPass" class="form-input" placeholder="password" value="cashier123">
                    </div>
                    <div>
                        <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="doStaffLogin()">
                            <i class="fa-solid fa-sign-in mr-1"></i> Sign In
                        </button>
                    </div>
                </div>
            </div>

            <!-- 3. Catalog Sync Card -->
            <div class="pos-panel">
                <div class="panel-title">
                    <span><i class="fa-solid fa-arrows-rotate mr-1" style="color: #10b981;"></i> 3. Sync Catalog &amp; Customers</span>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="syncCatalog()">
                            <i class="fa-solid fa-boxes-stacked mr-1"></i> Sync Catalog
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="syncCustomers()">
                            <i class="fa-solid fa-address-book mr-1"></i> Sync Custs
                        </button>
                    </div>
                </div>
                <div id="catalogStatus" style="font-size: 12px; color: #94a3b8; margin-bottom: 10px;">
                    Click "Sync Catalog" to download current services, tiered vehicle prices, and products from server.
                </div>
                <div id="catalogPills" style="max-height: 140px; overflow-y: auto;">
                    <!-- Items rendered dynamically -->
                </div>
            </div>

            <!-- 4. Create New Order & Payment -->
            <div class="pos-panel" style="border-left: 5px solid #10b981;">
                <div class="panel-title">
                    <span><i class="fa-solid fa-cash-register mr-1" style="color: #10b981;"></i> 4. Submit New Order &amp; Pay</span>
                    <span class="badge" style="background: #334155;" id="orderTotalBadge">$0.00</span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <label class="form-label">Plate Number *</label>
                        <input type="text" id="orderPlate" class="form-input" value="2BA-7788" style="font-family: 'JetBrains Mono', monospace; font-weight: 700; text-transform: uppercase;">
                    </div>
                    <div>
                        <label class="form-label">Customer Name</label>
                        <input type="text" id="orderCustName" class="form-input" value="Dara Sam">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <label class="form-label">Customer Phone</label>
                        <input type="text" id="orderCustPhone" class="form-input" value="012 999 888">
                    </div>
                    <div>
                        <label class="form-label">Vehicle Type</label>
                        <select id="orderVType" class="form-select" onchange="recalcCart()">
                            <option value="small_car" selected>Small Car (Sedan)</option>
                            <option value="motorbike">Motorbike</option>
                            <option value="suv_pickup">SUV / Pickup</option>
                            <option value="van_truck">Van / Heavy Truck</option>
                        </select>
                    </div>
                </div>

                <!-- Idempotency Key -->
                <div class="form-group" style="background: #090d16; padding: 10px; border-radius: 6px; border: 1px dashed #334155;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <label class="form-label" style="margin-bottom: 0;">Unique Order UUID (Idempotency Key):</label>
                        <button type="button" class="btn btn-secondary btn-sm" style="padding: 2px 8px; font-size: 11px;" onclick="genNewIdempotencyKey()">
                            <i class="fa-solid fa-arrows-rotate mr-1"></i> New UUID
                        </button>
                    </div>
                    <input type="text" id="idempotencyKey" class="form-input" readonly style="font-family: 'JetBrains Mono', monospace; font-size: 11.5px; color: #38bdf8;">
                    <div style="font-size: 10.5px; color: #94a3b8; margin-top: 3px;">
                        Prevents duplicate orders if user taps Submit multiple times or connection drops.
                    </div>
                </div>

                <!-- Payment status toggle -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
                        <input type="checkbox" id="orderIsPaid" checked style="width: 16px; height: 16px;">
                        <span>Mark Paid Immediately at Counter</span>
                    </label>
                    <select id="orderPayMethod" class="form-select" style="width: 140px; padding: 6px 10px; font-size: 12px;">
                        <option value="cash">Cash (សាច់ប្រាក់)</option>
                        <option value="aba">ABA Payway</option>
                        <option value="khqr">Bakong KHQR</option>
                    </select>
                </div>

                <!-- Submit Buttons: Once vs Double Click Test -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <button type="button" class="btn btn-success" style="padding: 12px;" onclick="submitOrder(false)">
                        <i class="fa-solid fa-paper-plane mr-1"></i> Send Order to Cloud
                    </button>
                    <button type="button" class="btn btn-secondary" style="padding: 12px;" onclick="testIdempotentDoubleSubmit()" title="Sends exact same order twice concurrently">
                        <i class="fa-solid fa-clone mr-1" style="color: #f59e0b;"></i> Test Double-Send (Idempotency)
                    </button>
                </div>
            </div>

            <!-- 5. Add New Customer & Vehicle at Counter -->
            <div class="pos-panel">
                <div class="panel-title">
                    <span><i class="fa-solid fa-id-card mr-1" style="color: #f59e0b;"></i> 5. Fast Customer &amp; Vehicle Intake</span>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                    <input type="text" id="newCustName" class="form-input" placeholder="Customer Name" value="Sok Heng">
                    <input type="text" id="newCustPhone" class="form-input" placeholder="Phone e.g. 092 112 233" value="092 112 233">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                    <input type="text" id="newPlate" class="form-input" placeholder="Plate e.g. 2CA-9999" value="2CA-9999" style="text-transform: uppercase;">
                    <input type="text" id="newModel" class="form-input" placeholder="Model e.g. Prius 2010" value="Prius 2010">
                    <button type="button" class="btn btn-primary" onclick="submitNewCustomer()">
                        <i class="fa-solid fa-user-plus mr-1"></i> Save to System
                    </button>
                </div>
            </div>

            <!-- 6. Daily Close / Shift End Reconciliation -->
            <div class="pos-panel" style="border-left: 5px solid #a855f7;">
                <div class="panel-title">
                    <span><i class="fa-solid fa-cash-register mr-1" style="color: #a855f7;"></i> 6. POS Daily Close / Shift End</span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="fetchDailyReport()">
                        <i class="fa-solid fa-calculator mr-1"></i> Fetch Shift Report
                    </button>
                </div>
                <div id="closeReportBox" style="background: #090d16; padding: 12px; border-radius: 6px; margin-bottom: 12px; font-size: 12.5px; display: none;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <div>Paid Orders: <strong id="closePaidOrders" style="color: #fff;">0</strong></div>
                        <div>Total Sales: <strong id="closeTotalSales" style="color: #10b981;">$0.00</strong></div>
                        <div>Cash Sales: <strong id="closeCashSales" style="color: #38bdf8;">$0.00</strong></div>
                        <div>ABA / KHQR: <strong id="closeBankSales" style="color: #f59e0b;">$0.00</strong></div>
                        <div style="grid-column: span 2; border-top: 1px dashed #334155; padding-top: 6px; margin-top: 4px;">
                            Expected Drawer Cash: <strong id="closeExpectedCash" style="color: #a855f7; font-size: 14px;">$0.00</strong>
                        </div>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <label class="form-label">Opening Cash ($)</label>
                        <input type="number" step="0.01" id="closeOpeningCash" class="form-input" value="50.00" onchange="calcExpectedFromInput()">
                    </div>
                    <div>
                        <label class="form-label">Actual Counted Cash ($)</label>
                        <input type="number" step="0.01" id="closeCountedCash" class="form-input" placeholder="0.00">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label class="form-label">Shift Note / Discrepancy Reason</label>
                    <input type="text" id="closeNote" class="form-input" placeholder="e.g. Balanced drawer, or -$2 missing change">
                </div>
                <button type="button" class="btn btn-primary" style="width: 100%; background: #9333ea;" onclick="submitDailyClose()">
                    <i class="fa-solid fa-lock mr-1"></i> Submit POS Daily Close
                </button>
            </div>

        </div>

        <!-- Right Column: Live API Network Log -->
        <div class="pos-panel" style="display: flex; flex-direction: column;">
            <div class="panel-title">
                <span><i class="fa-solid fa-terminal mr-1" style="color: #38bdf8;"></i> Network &amp; JSON Payload Monitor</span>
                <button type="button" class="btn btn-secondary btn-sm" onclick="clearLog()">Clear Log</button>
            </div>
            <div id="networkLog" class="log-box" style="flex: 1; min-height: 520px;">
[INIT] Independent POS Simulator ready.
[INFO] Configured Endpoint: <?= htmlspecialchars(API_BASE_URL) ?>/api/pos/
[INFO] Device Key: <?= htmlspecialchars($defaultApiKey) ?>

Click "Test Link" or "Sync Catalog" to start.
            </div>
        </div>

    </div>
</div>

<script>
let catalogData = { services: [], pricing: {}, products: [] };
let selectedCart = [];
let loggedInStaff = null;

function log(msg, obj = null) {
    const box = document.getElementById('networkLog');
    const time = new Date().toLocaleTimeString();
    let text = `[${time}] ${msg}\n`;
    if (obj) {
        text += JSON.stringify(obj, null, 2) + '\n';
    }
    box.innerText += '\n' + text;
    box.scrollTop = box.scrollHeight;
}

function clearLog() {
    document.getElementById('networkLog').innerText = '[CLEARED] Ready for new requests.\n';
}

function genNewIdempotencyKey() {
    const uid = 'POS-ORD-' + Date.now() + '-' + Math.random().toString(36).substr(2, 6).toUpperCase();
    document.getElementById('idempotencyKey').value = uid;
    return uid;
}

let currentStaffToken = '';

async function ensureStaffLogin() {
    if (currentStaffToken) return currentStaffToken;
    const username = document.getElementById('staffUsername')?.value?.trim() || 'admin';
    const pin = document.getElementById('staffPin')?.value?.trim() || '123456';
    log(`[AUTH] Logging in staff user '${username}' to acquire session token...`);
    const baseUrl = document.getElementById('apiEndpoint').value.trim();
    const apiKey = document.getElementById('deviceApiKey').value.trim();
    const url = `${baseUrl}?action=staff_login`;
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Device-Key': apiKey
            },
            body: JSON.stringify({ action: 'staff_login', username, pin, password: pin })
        });
        const json = await res.json();
        if (res.ok && json.session_token) {
            currentStaffToken = json.session_token;
            log(`[AUTH] Staff login successful. Session token acquired.`);
            return currentStaffToken;
        } else {
            log(`[AUTH ERROR] Staff login failed: ${json.message || json.error || 'Unknown error'}`);
            return null;
        }
    } catch (e) {
        log(`[AUTH ERROR] Network error during staff login: ${e.message}`);
        return null;
    }
}

async function apiCall(action, payload = {}, headers = {}) {
    const baseUrl = document.getElementById('apiEndpoint').value.trim();
    const apiKey = document.getElementById('deviceApiKey').value.trim();

    // Auto-login to obtain staff session token if calling protected actions
    const deviceOnly = ['staff_login', 'sync_catalog', 'get_day_lock_status', 'ping', 'health'];
    if (!deviceOnly.includes(action) && !currentStaffToken) {
        await ensureStaffLogin();
    }

    const url = `${baseUrl}?action=${encodeURIComponent(action)}`;
    const reqHeaders = {
        'Content-Type': 'application/json',
        'X-Device-Key': apiKey,
        ...headers
    };
    if (currentStaffToken && action !== 'staff_login') {
        reqHeaders['X-Staff-Token'] = currentStaffToken;
    }

    log(`--> POST ${url}`, payload);

    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: reqHeaders,
            body: JSON.stringify(payload)
        });

        const json = await res.json();
        log(`<-- HTTP ${res.status} ${res.statusText}`, json);
        return { ok: res.ok, status: res.status, data: json };
    } catch (err) {
        log(`[ERROR] Connection failed: ${err.message}`);
        return { ok: false, error: err.message };
    }
}

// 1. Test Link
async function testConnection() {
    log('Testing connection to central server...');
    const res = await apiCall('sync_catalog');
    const badge = document.getElementById('statusBadge');
    if (res.ok && res.data.success) {
        badge.className = 'badge badge-live';
        badge.innerHTML = '<i class="fa-solid fa-circle-check mr-1"></i> CONNECTED';
        alert(`Connected to Car Wash Pro system!\nDevice: ${res.data.device.name}\nExchange Rate: $1 = ${res.data.exchange_rate} KHR`);
    } else {
        badge.className = 'badge badge-danger';
        badge.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1"></i> ERROR';
        alert(`Connection Failed: ${res.data?.message || 'Check endpoint or API key'}`);
    }
}

// 2. Staff Login
async function doStaffLogin() {
    const username = document.getElementById('staffUser').value.trim();
    const password = document.getElementById('staffPass').value;

    const res = await apiCall('staff_login', { username, password });
    if (res.ok && res.data.success) {
        loggedInStaff = res.data.user;
        document.getElementById('loggedInStaffBadge').innerHTML = `<span class="badge badge-live"><i class="fa-solid fa-user-check mr-1"></i> ${loggedInStaff.full_name} (${loggedInStaff.role})</span>`;
        alert(`Staff ${loggedInStaff.full_name} authenticated on POS device!`);
    } else {
        alert(res.data?.error || 'Staff authentication failed');
    }
}

// 3. Sync Catalog
async function syncCatalog() {
    const res = await apiCall('sync_catalog');
    if (res.ok && res.data.success) {
        catalogData.services = res.data.services || [];
        catalogData.pricing = res.data.pricing_matrix || {};
        catalogData.products = res.data.products || [];

        document.getElementById('catalogStatus').innerText = `Synced: ${catalogData.services.length} services, ${catalogData.products.length} products.`;

        const container = document.getElementById('catalogPills');
        container.innerHTML = '';

        // Render Services
        catalogData.services.forEach(s => {
            const chip = document.createElement('div');
            chip.className = 'item-chip';
            chip.id = `chip_s_${s.id}`;
            chip.innerHTML = `<i class="fa-solid ${s.category === 'repair' ? 'fa-wrench' : 'fa-shower'}"></i> ${s.name_en}`;
            chip.onclick = () => toggleCartItem('service', s.id, s.name_en, s.category);
            container.appendChild(chip);
        });

        // Render Products
        catalogData.products.forEach(p => {
            const chip = document.createElement('div');
            chip.className = 'item-chip';
            chip.id = `chip_p_${p.id}`;
            chip.innerHTML = `<i class="fa-solid fa-box-open"></i> ${p.name_en} ($${parseFloat(p.price_usd).toFixed(2)})`;
            chip.onclick = () => toggleCartItem(p.category === 'spare_part' ? 'spare_part' : 'product', p.id, p.name_en, null, parseFloat(p.price_usd));
            container.appendChild(chip);
        });

        // Select first service by default
        if (catalogData.services.length > 0) {
            toggleCartItem('service', catalogData.services[0].id, catalogData.services[0].name_en, catalogData.services[0].category);
        }
    }
}

function toggleCartItem(type, id, name, category, fixedPrice = null) {
    const key = `${type}_${id}`;
    const idx = selectedCart.findIndex(x => x.key === key);
    const chip = document.getElementById(type === 'service' ? `chip_s_${id}` : `chip_p_${id}`);

    if (idx >= 0) {
        selectedCart.splice(idx, 1);
        if (chip) chip.classList.remove('selected');
    } else {
        selectedCart.push({ key, type, id, name, category, fixedPrice, qty: 1 });
        if (chip) chip.classList.add('selected');
    }

    recalcCart();
}

function recalcCart() {
    const vType = document.getElementById('orderVType').value;
    let totalUsd = 0;

    selectedCart.forEach(it => {
        if (it.type === 'service' || it.type === 'repair') {
            const p = (catalogData.pricing[it.id] && catalogData.pricing[it.id][vType]) ? catalogData.pricing[it.id][vType].usd : 5.00;
            it.price_usd = p;
            totalUsd += p;
        } else {
            it.price_usd = it.fixedPrice || 0;
            totalUsd += it.price_usd;
        }
    });

    document.getElementById('orderTotalBadge').innerText = '$' + totalUsd.toFixed(2);
}

// 4. Sync Customers
async function syncCustomers() {
    const res = await apiCall('sync_customers');
    if (res.ok && res.data.success) {
        alert(`Synced ${res.data.customers.length} registered customers and ${res.data.vehicles.length} vehicles!`);
    }
}

// 5. Submit Order
async function submitOrder(isDoubleTest = false) {
    if (selectedCart.length === 0) {
        alert('Please select at least one service or product item first (Click "Sync Catalog").');
        return;
    }

    const idempotencyKey = document.getElementById('idempotencyKey').value || genNewIdempotencyKey();
    const plate = document.getElementById('orderPlate').value.trim();
    const name = document.getElementById('orderCustName').value.trim();
    const phone = document.getElementById('orderCustPhone').value.trim();
    const vType = document.getElementById('orderVType').value;
    const isPaid = document.getElementById('orderIsPaid').checked;
    const payMethod = document.getElementById('orderPayMethod').value;

    const payload = {
        idempotency_key: idempotencyKey,
        plate_number: plate,
        customer_name: name,
        customer_phone: phone,
        vehicle_type: vType,
        is_paid: isPaid,
        payment_method: payMethod,
        staff_id: loggedInStaff ? loggedInStaff.id : 2,
        status: isPaid ? 'paid' : 'waiting',
        items: selectedCart.map(it => ({
            id: it.id,
            type: it.type,
            name: it.name,
            price_usd: it.price_usd,
            qty: it.qty
        }))
    };

    const res = await apiCall('create_order', payload, { 'X-Idempotency-Key': idempotencyKey });

    if (res.ok && res.data.success) {
        if (res.data.is_duplicate) {
            alert(`[IDEMPOTENT PROTECTION]\nThe central system identified this order as ALREADY SAVED previously!\nNo duplicate order created. Order #: ${res.data.order.order_number}`);
        } else {
            alert(`Order Created Successfully on Central Cloud/Local Server!\nOrder #: ${res.data.order.order_number}\nTotal: $${parseFloat(res.data.order.total_usd).toFixed(2)}\nSource Tag: POS App (${res.data.order.pos_device_name})`);
            if (!isDoubleTest) genNewIdempotencyKey();
        }
    } else {
        alert(res.data?.error || 'Order creation failed');
    }
}

// 6. Test Double Submit (Proof of Idempotency)
async function testIdempotentDoubleSubmit() {
    log('[TESTING] Simulating rapid double-tap / retry with the EXACT SAME Idempotency UUID...');
    // 1st Send
    await submitOrder(true);
    // 2nd Send immediately with exact same UUID
    log('[TESTING] Sending 2nd identical request now...');
    await submitOrder(false);
}

// 7. Add New Customer & Vehicle
async function submitNewCustomer() {
    const name = document.getElementById('newCustName').value.trim();
    const phone = document.getElementById('newCustPhone').value.trim();
    const plate = document.getElementById('newPlate').value.trim();
    const model = document.getElementById('newModel').value.trim();

    const res = await apiCall('add_customer_vehicle', {
        customer_name: name,
        customer_phone: phone,
        plate_number: plate,
        make_model: model,
        vehicle_type: 'small_car'
    });

    if (res.ok && res.data.success) {
        alert(`Customer and vehicle registered in system database!\nPlate: ${res.data.plate_number}\nName: ${res.data.customer_name}`);
    } else {
        alert(res.data?.error || 'Failed to save customer');
    }
}

// 8. POS Daily Close & Shift Reconciliation
let currentDailyReport = null;

async function fetchDailyReport() {
    const openingCash = parseFloat(document.getElementById('closeOpeningCash').value) || 0.0;
    const res = await apiCall('get_daily_report', { opening_cash: openingCash });
    if (res.ok && res.data.success) {
        currentDailyReport = res.data.report;
        const rep = currentDailyReport;
        document.getElementById('closeReportBox').style.display = 'block';
        document.getElementById('closePaidOrders').innerText = rep.paid_orders_count;
        document.getElementById('closeTotalSales').innerText = '$' + parseFloat(rep.total_sales).toFixed(2);
        document.getElementById('closeCashSales').innerText = '$' + parseFloat(rep.cash_sales).toFixed(2);
        document.getElementById('closeBankSales').innerText = '$' + (parseFloat(rep.aba_sales) + parseFloat(rep.khqr_sales)).toFixed(2);
        document.getElementById('closeExpectedCash').innerText = '$' + parseFloat(rep.expected_cash).toFixed(2);
        // Pre-fill counted cash with expected cash as convenience
        if (!document.getElementById('closeCountedCash').value) {
            document.getElementById('closeCountedCash').value = parseFloat(rep.expected_cash).toFixed(2);
        }
    } else {
        alert(res.data?.error || 'Failed to fetch shift report');
    }
}

function calcExpectedFromInput() {
    if (currentDailyReport) {
        const opening = parseFloat(document.getElementById('closeOpeningCash').value) || 0.0;
        const cashSales = parseFloat(currentDailyReport.cash_sales) || 0.0;
        const exp = opening + cashSales;
        document.getElementById('closeExpectedCash').innerText = '$' + exp.toFixed(2);
    }
}

async function submitDailyClose() {
    const openingCash = parseFloat(document.getElementById('closeOpeningCash').value) || 0.0;
    const countedCashStr = document.getElementById('closeCountedCash').value;
    if (countedCashStr === '') {
        alert('Please count the cash drawer and enter actual counted cash.');
        return;
    }
    const countedCash = parseFloat(countedCashStr);
    const note = document.getElementById('closeNote').value.trim();

    const payload = {
        opening_cash: openingCash,
        counted_cash: countedCash,
        cashier_id: loggedInStaff ? loggedInStaff.id : 2,
        note: note
    };

    const res = await apiCall('save_pos_closing', payload);
    if (res.ok && res.data.success) {
        const close = res.data.closing;
        const diff = parseFloat(close.difference);
        let diffMsg = 'Cash Drawer is perfectly balanced ($0.00)!';
        if (diff > 0) diffMsg = `Cash Drawer has OVERAGE of +$${diff.toFixed(2)}`;
        if (diff < 0) diffMsg = `Cash Drawer has SHORTAGE of -$${Math.abs(diff).toFixed(2)}`;

        alert(`POS Daily Close Recorded Successfully!\nExpected: $${parseFloat(close.expected_cash).toFixed(2)}\nCounted: $${parseFloat(close.counted_cash).toFixed(2)}\nDifference: ${diffMsg}`);
    } else {
        alert(res.data?.error || 'Failed to record daily close');
    }
}
    genNewIdempotencyKey();
    syncCatalog();
});
</script>

</body>
</html>
