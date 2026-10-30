<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';

$pdo = Database::getInstance();
$apiKey = 'pos_sec_test_key_8899aabb';
$url = 'http://localhost/Car-WashService/api/pos/index.php?action=sync_catalog&api_key=' . $apiKey;

function callSyncCatalog(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        throw new RuntimeException("Failed to call sync_catalog. HTTP Code: {$httpCode}, Response: {$response}");
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON returned: {$response}");
    }

    return $json;
}

echo "=== 1. INITIAL SYNC_CATALOG CALL ===\n";
$data1 = callSyncCatalog($url);

echo "Success: " . ($data1['success'] ? 'true' : 'false') . "\n";
echo "Categories Version: " . ($data1['categories_version'] ?? 'MISSING') . "\n";
echo "Categories Updated At: " . ($data1['categories_updated_at'] ?? 'MISSING') . "\n";
echo "Categories count: " . count($data1['categories']) . "\n\n";

echo "--- CATEGORIES LIST ---\n";
foreach ($data1['categories'] as $cat) {
    echo sprintf(
        "ID: %-2d | Type: %-7s | Code: %-12s | Order: %-2d | Active: %d | Name: %s (%s)\n",
        $cat['id'],
        $cat['type'],
        $cat['code'],
        $cat['sort_order'],
        $cat['is_active'],
        $cat['name_en'],
        $cat['name_km']
    );
}

echo "\n--- TWO SAMPLE SERVICES ---\n";
for ($i = 0; $i < min(2, count($data1['services'])); $i++) {
    $s = $data1['services'][$i];
    echo sprintf("ID: %d | Name: %s | category (string): %s | category_id: %s\n", $s['id'], $s['name_en'], $s['category'], var_export($s['category_id'], true));
}

echo "\n--- TWO SAMPLE PRODUCTS ---\n";
for ($i = 0; $i < min(2, count($data1['products'])); $i++) {
    $p = $data1['products'][$i];
    echo sprintf("ID: %d | Name: %s | category (string): %s | category_id: %s\n", $p['id'], $p['name_en'], $p['category'], var_export($p['category_id'], true));
}

echo "\n=== 2. TESTING CATEGORY RENAMED ===\n";
// Pick first service category
$targetCat = $pdo->query("SELECT id, name_en, sort_order, is_active FROM categories WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$origName = $targetCat['name_en'];
$tempName = "Car Wash & Detailing (Renamed Test)";

// Rename category in Category Control
$pdo->prepare("UPDATE categories SET name_en = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1")->execute([$tempName]);
sleep(1); // ensure timestamp step if needed

$dataRenamed = callSyncCatalog($url);
$renamedCat = null;
foreach ($dataRenamed['categories'] as $c) {
    if ($c['id'] == 1) {
        $renamedCat = $c;
        break;
    }
}

echo "Original Name: {$origName}\n";
echo "Renamed Name in API: " . ($renamedCat['name_en'] ?? 'NOT FOUND') . "\n";
echo "Old Version: " . $data1['categories_version'] . "\n";
echo "New Version: " . $dataRenamed['categories_version'] . "\n";
$renamePassed = ($renamedCat && $renamedCat['name_en'] === $tempName && $data1['categories_version'] !== $dataRenamed['categories_version']);
echo "Rename Test: " . ($renamePassed ? "PASSED" : "FAILED") . "\n";

// Restore name
$pdo->prepare("UPDATE categories SET name_en = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1")->execute([$origName]);

echo "\n=== 3. TESTING CATEGORY REORDERED ===\n";
$origOrder = (int)$targetCat['sort_order'];
$tempOrder = 99; // Move to end

$pdo->prepare("UPDATE categories SET sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1")->execute([$tempOrder]);
sleep(1);

$dataReordered = callSyncCatalog($url);
$lastCat = end($dataReordered['categories']);
echo "Original Order: {$origOrder}, Temp Order: {$tempOrder}\n";
echo "Last Category ID in API now: " . ($lastCat['id'] ?? 'NONE') . " (Sort Order: " . ($lastCat['sort_order'] ?? 'NONE') . ")\n";
echo "Old Version: " . $data1['categories_version'] . "\n";
echo "New Version: " . $dataReordered['categories_version'] . "\n";
$reorderPassed = ($lastCat && $lastCat['id'] == 1 && $lastCat['sort_order'] == 99 && $data1['categories_version'] !== $dataReordered['categories_version']);
echo "Reorder Test: " . ($reorderPassed ? "PASSED" : "FAILED") . "\n";

// Restore order
$pdo->prepare("UPDATE categories SET sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1")->execute([$origOrder]);

echo "\n=== 4. TESTING CATEGORY DISABLED ===\n";
$pdo->prepare("UPDATE categories SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = 1")->execute();
sleep(1);

$dataDisabled = callSyncCatalog($url);
$foundCat1 = false;
foreach ($dataDisabled['categories'] as $c) {
    if ($c['id'] == 1) {
        $foundCat1 = true;
        break;
    }
}
echo "Category 1 found in active list: " . ($foundCat1 ? "YES (FAILED)" : "NO (CORRECT)") . "\n";
echo "Categories count after disabling: " . count($dataDisabled['categories']) . " (was " . count($data1['categories']) . ")\n";
echo "Old Version: " . $data1['categories_version'] . "\n";
echo "New Version: " . $dataDisabled['categories_version'] . "\n";
$disablePassed = (!$foundCat1 && $data1['categories_version'] !== $dataDisabled['categories_version']);
echo "Disable Test: " . ($disablePassed ? "PASSED" : "FAILED") . "\n";

// Restore active status
$pdo->prepare("UPDATE categories SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = 1")->execute();

echo "\n=== 5. RESTORATION CHECK ===\n";
$dataRestored = callSyncCatalog($url);
echo "Final Categories Count: " . count($dataRestored['categories']) . "\n";
echo "Final Version: " . $dataRestored['categories_version'] . "\n";

echo "\n=== PRETTY JSON OUTPUT FOR REPORTING ===\n";
$displayPayload = [
    'categories_version' => $dataRestored['categories_version'],
    'categories_updated_at' => $dataRestored['categories_updated_at'],
    'categories' => $dataRestored['categories'],
    'sample_items' => [
        'service' => [
            'id' => $dataRestored['services'][0]['id'],
            'name_en' => $dataRestored['services'][0]['name_en'],
            'name_km' => $dataRestored['services'][0]['name_km'],
            'category' => $dataRestored['services'][0]['category'],
            'category_id' => $dataRestored['services'][0]['category_id'],
            'description' => $dataRestored['services'][0]['description']
        ],
        'product' => [
            'id' => $dataRestored['products'][0]['id'],
            'name_en' => $dataRestored['products'][0]['name_en'],
            'name_km' => $dataRestored['products'][0]['name_km'],
            'category' => $dataRestored['products'][0]['category'],
            'category_id' => $dataRestored['products'][0]['category_id'],
            'brand' => $dataRestored['products'][0]['brand'],
            'price_usd' => $dataRestored['products'][0]['price_usd'],
            'stock_qty' => $dataRestored['products'][0]['stock_qty']
        ]
    ]
];
echo json_encode($displayPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if ($renamePassed && $reorderPassed && $disablePassed) {
    echo "\n============================================\n";
    echo " ALL TESTS PASSED SUCCESSFULLY! \n";
    echo "============================================\n";
} else {
    echo "\n============================================\n";
    echo " SOME TESTS FAILED! \n";
    echo "============================================\n";
}
