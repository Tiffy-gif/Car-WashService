with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

old_block = """// 48. ACTION: evaluate_cart_upsell (Requirement 2)
// Authoritative back-office upsell rule engine
// ========================================================
if ($action === 'evaluate_cart_upsell') {
    $cart = $inputData['cart'] ?? [];
    if (empty($cart) && isset($_POST['cart'])) {
        $cart = json_decode($_POST['cart'], true) ?: [];
    }
    $customerId = !empty($inputData['customer_id']) ? (int)$inputData['customer_id'] : 0;
    $plateNumber = strtoupper(trim($inputData['plate_number'] ?? ''));
    $vehicleType = trim($inputData['vehicle_type'] ?? 'small_car');

    $serviceIds = [];
    $productIds = [];
    $totalCartUsd = 0.0;
    foreach ($cart as $item) {
        $type = $item['type'] ?? 'service';
        $sId = !empty($item['service_id']) ? (int)$item['service_id'] : (!empty($item['id']) && in_array($type, ['service', 'repair'], true) ? (int)$item['id'] : null);
        $pId = !empty($item['product_id']) ? (int)$item['product_id'] : (!empty($item['id']) && in_array($type, ['product', 'spare_part'], true) ? (int)$item['id'] : null);
        if ($sId) $serviceIds[] = $sId;
        if ($pId) $productIds[] = $pId;
        $totalCartUsd += (float)($item['price_usd'] ?? 0) * max(1, (int)($item['qty'] ?? 1));
    }

    $matchedOffer = null;

    // Rule 1: Cart has "Exterior Wash" (ID 1) only, without Full Wash (ID 3) or Hydrophobic Wax (ID 5)
    // Offer: "Upgrade to Premium Wash for $5?"
    if (in_array(1, $serviceIds, true) && !in_array(3, $serviceIds, true) && !in_array(5, $serviceIds, true)) {
        $matchedOffer = [
            'id' => 'upsell_premium_wash',
            'title' => 'Upgrade Offer',
            'title_km' => 'ការផ្តល់ជូនដំឡើងកញ្ចប់',
            'message' => 'Upgrade to Premium Wash for $5?',
            'message_km' => 'តើលោកអ្នកចង់ដំឡើងទៅ Premium Wash ត្រឹមតែ $5 ដែរឬទេ?',
            'action_type' => 'upgrade_service',
            'replace_service_id' => 1,
            'target_service_id' => 5,
            'target_name' => 'Hydrophobic Wax & Polish',
            'target_name_km' => 'បាញ់ក្រមួន Wax & ប៉ូលាពន្លឺ',
            'offer_price_usd' => 5.00,
            'offer_price_khr' => 20500,
            'accept_label' => 'Accept',
            'accept_label_km' => 'យល់ព្រម',
            'decline_label' => 'Not now',
            'decline_label_km' => 'ទុកពេលក្រោយ'
        ];
    }
    // Rule 2: Cart has Full Wash (ID 3) without Hydrophobic Wax (ID 5)
    elseif (in_array(3, $serviceIds, true) && !in_array(5, $serviceIds, true)) {
        $matchedOffer = [
            'id' => 'upsell_add_wax',
            'title' => 'Protection Add-on',
            'title_km' => 'បន្ថែមការការពារ Wax',
            'message' => 'Add Hydrophobic Wax & Polish for $5?',
            'message_km' => 'បន្ថែមបាញ់ក្រមួន Wax & ប៉ូលាពន្លឺ ត្រឹមតែ $5 ដែរឬទេ?',
            'action_type' => 'add_service',
            'target_service_id' => 5,
            'target_name' => 'Hydrophobic Wax & Polish',
            'target_name_km' => 'បាញ់ក្រមួន Wax & ប៉ូលាពន្លឺ',
            'offer_price_usd' => 5.00,
            'offer_price_khr' => 20500,
            'accept_label' => 'Accept',
            'accept_label_km' => 'យល់ព្រម',
            'decline_label' => 'Not now',
            'decline_label_km' => 'ទុកពេលក្រោយ'
        ];
    }

    echo json_encode([
        'success' => true,
        'has_offer' => ($matchedOffer !== null),
        'offer' => $matchedOffer
    ]);
    exit;
}"""

new_block = """// 48. ACTION: evaluate_cart_upsell (Dynamic Backoffice Upsell Rule Engine)
// Authoritative back-office upsell rule engine reading from upsell_rules & settings
// ========================================================
if ($action === 'evaluate_cart_upsell') {
    // 1. Check if upsell popups are globally enabled
    $enableUpsell = (string)get_setting('enable_upsell_offers', '1');
    if ($enableUpsell === '0') {
        echo json_encode([
            'success' => true,
            'has_offer' => false,
            'offer' => null
        ]);
        exit;
    }

    $cart = $inputData['cart'] ?? [];
    if (empty($cart) && isset($_POST['cart'])) {
        $cart = json_decode($_POST['cart'], true) ?: [];
    }
    $customerId = !empty($inputData['customer_id']) ? (int)$inputData['customer_id'] : 0;
    $plateNumber = strtoupper(trim($inputData['plate_number'] ?? ''));
    $vehicleType = trim($inputData['vehicle_type'] ?? 'small_car');

    $serviceIds = [];
    $productIds = [];
    $totalCartUsd = 0.0;
    foreach ($cart as $item) {
        $type = $item['type'] ?? 'service';
        $sId = !empty($item['service_id']) ? (int)$item['service_id'] : (!empty($item['id']) && in_array($type, ['service', 'repair'], true) ? (int)$item['id'] : null);
        $pId = !empty($item['product_id']) ? (int)$item['product_id'] : (!empty($item['id']) && in_array($type, ['product', 'spare_part'], true) ? (int)$item['id'] : null);
        if ($sId) $serviceIds[] = $sId;
        if ($pId) $productIds[] = $pId;
        $totalCartUsd += (float)($item['price_usd'] ?? 0) * max(1, (int)($item['qty'] ?? 1));
    }

    $matchedOffer = null;

    // 2. Fetch active upsell rules from database
    $rulesStmt = $pdo->query("SELECT * FROM upsell_rules WHERE is_active = 1 ORDER BY id ASC");
    $rules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rules as $r) {
        // Vehicle type filter
        if (!empty($r['trigger_vehicle_type']) && $r['trigger_vehicle_type'] !== $vehicleType) {
            continue;
        }

        // Trigger service condition
        if (!empty($r['trigger_service_id'])) {
            $trigId = (int)$r['trigger_service_id'];
            if (!in_array($trigId, $serviceIds, true)) {
                continue;
            }
        }

        // Target offer already present in cart?
        if (!empty($r['offer_service_id'])) {
            $offerSvcId = (int)$r['offer_service_id'];
            if (in_array($offerSvcId, $serviceIds, true)) {
                continue; // Do not offer what customer already added
            }
        }

        // Resolve offer service details
        $targetNameEn = '';
        $targetNameKm = '';
        if (!empty($r['offer_service_id'])) {
            $svcStmt = $pdo->prepare("SELECT name_en, name_km FROM services WHERE id = ?");
            $svcStmt->execute([$r['offer_service_id']]);
            $svcInfo = $svcStmt->fetch(PDO::FETCH_ASSOC);
            if ($svcInfo) {
                $targetNameEn = $svcInfo['name_en'];
                $targetNameKm = $svcInfo['name_km'];
            }
        }

        $actionType = $r['action_type'] ?: 'add_service';
        $priceUsd = ($r['offer_price_usd'] !== null) ? (float)$r['offer_price_usd'] : 5.00;
        $rateKhr = (float)get_setting('usd_to_khr_rate', '4100');
        $priceKhr = round($priceUsd * $rateKhr);

        $headlineEn = $r['offer_headline_en'] ?: ($r['name'] ?: 'Special Offer');
        $headlineKm = $r['offer_headline_km'] ?: ($r['name'] ?: 'ការផ្តល់ជូនពិសេស');
        $descEn = $r['offer_description_en'] ?: ($actionType === 'add_service' ? "Add {$targetNameEn} to your wash for +\${$priceUsd}." : "Upgrade to {$targetNameEn} for \${$priceUsd}.");
        $descKm = $r['offer_description_km'] ?: ($actionType === 'add_service' ? "បន្ថែម {$targetNameKm} ត្រឹមតែ +\${$priceUsd}។" : "ដំឡើងទៅ {$targetNameKm} ត្រឹមតែ \${$priceUsd}។");

        $replaceId = $r['replace_service_id'] ? (int)$r['replace_service_id'] : ($r['trigger_service_id'] ? (int)$r['trigger_service_id'] : null);

        $matchedOffer = [
            'id' => (string)($r['rule_uid'] ?: ('rule_' . $r['id'])),
            'rule_id' => (int)$r['id'],
            'title' => $headlineEn,
            'title_km' => $headlineKm,
            'message' => $headlineEn,
            'message_km' => $headlineKm,
            'description' => $descEn,
            'description_km' => $descKm,
            'action_type' => $actionType,
            'replace_service_id' => $replaceId,
            'target_service_id' => (int)$r['offer_service_id'],
            'target_name' => $targetNameEn,
            'target_name_km' => $targetNameKm,
            'offer_price_usd' => $priceUsd,
            'offer_price_khr' => $priceKhr,
            'accept_label' => 'Accept',
            'accept_label_km' => 'យល់ព្រម',
            'decline_label' => 'Not now',
            'decline_label_km' => 'ទុកពេលក្រោយ'
        ];
        break; // Match first active rule
    }

    echo json_encode([
        'success' => true,
        'has_offer' => ($matchedOffer !== null),
        'offer' => $matchedOffer
    ]);
    exit;
}"""

assert old_block in content, "old_block not found in api/pos/index.php"
content = content.replace(old_block, new_block, 1)

with open('api/pos/index.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Successfully updated evaluate_cart_upsell in api/pos/index.php!")
