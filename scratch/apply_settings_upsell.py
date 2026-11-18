import re

with open('views/owner/settings.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update validTabs
old_tabs = "$validTabs = ['shop', 'receipt', 'money', 'payment', 'bays', 'oil', 'loyalty', 'performance', 'telegram', 'sync', 'system', 'history'];"
new_tabs = "$validTabs = ['shop', 'receipt', 'money', 'payment', 'bays', 'oil', 'loyalty', 'upsell', 'performance', 'telegram', 'sync', 'system', 'history'];"
assert old_tabs in content, "old_tabs not found"
content = content.replace(old_tabs, new_tabs, 1)

# 2. Add action handlers after save_loyalty
action_code = """
    // 5c. Save Upsell Global Control
    elseif ($action === 'save_upsell_global') {
        $enableUpsell = !empty($_POST['enable_upsell_offers']) ? '1' : '0';
        $changed = update_setting($pdo, 'enable_upsell_offers', $enableUpsell, $userId, $userName);
        $message = $changed ? "Upsell global settings updated successfully!" : "No changes made to global upsell settings.";
        $messageType = $changed ? 'success' : 'info';
    }

    // 5d. Save Upsell Rule (Create / Update)
    elseif ($action === 'save_upsell_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $name = trim((string)($_POST['rule_name'] ?? ''));
        $triggerServiceId = !empty($_POST['trigger_service_id']) ? (int)$_POST['trigger_service_id'] : null;
        $triggerVehicleType = !empty($_POST['trigger_vehicle_type']) ? trim((string)$_POST['trigger_vehicle_type']) : null;
        $offerServiceId = !empty($_POST['offer_service_id']) ? (int)$_POST['offer_service_id'] : null;
        $actionType = in_array(($_POST['action_type'] ?? ''), ['add_service', 'upgrade_service'], true) ? $_POST['action_type'] : 'add_service';
        $offerPriceUsd = ($_POST['offer_price_usd'] !== '') ? max(0, (float)$_POST['offer_price_usd']) : 5.00;
        $headlineEn = trim((string)($_POST['offer_headline_en'] ?? ''));
        $headlineKm = trim((string)($_POST['offer_headline_km'] ?? ''));
        $descEn = trim((string)($_POST['offer_description_en'] ?? ''));
        $descKm = trim((string)($_POST['offer_description_km'] ?? ''));
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $message = "Rule name is required.";
            $messageType = 'danger';
        } elseif (!$offerServiceId) {
            $message = "Target offer service is required.";
            $messageType = 'danger';
        } else {
            if ($ruleId > 0) {
                $stmt = $pdo->prepare("UPDATE upsell_rules SET 
                    name = ?, trigger_service_id = ?, trigger_vehicle_type = ?, 
                    offer_service_id = ?, action_type = ?, offer_price_usd = ?, 
                    offer_headline_en = ?, offer_headline_km = ?, 
                    offer_description_en = ?, offer_description_km = ?, 
                    is_active = ? 
                    WHERE id = ?");
                $stmt->execute([
                    $name, $triggerServiceId, $triggerVehicleType,
                    $offerServiceId, $actionType, $offerPriceUsd,
                    $headlineEn, $headlineKm,
                    $descEn, $descKm,
                    $isActive, $ruleId
                ]);
                $message = "Upsell rule updated successfully!";
            } else {
                $ruleUid = 'UPSELL-' . strtoupper(bin2hex(random_bytes(4)));
                $stmt = $pdo->prepare("INSERT INTO upsell_rules (
                    rule_uid, name, trigger_service_id, trigger_category, trigger_vehicle_type,
                    offer_service_id, action_type, offer_price_usd,
                    offer_headline_en, offer_headline_km, offer_description_en, offer_description_km,
                    is_active, created_at
                ) VALUES (?, ?, ?, 'wash', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $ruleUid, $name, $triggerServiceId, $triggerVehicleType,
                    $offerServiceId, $actionType, $offerPriceUsd,
                    $headlineEn, $headlineKm,
                    $descEn, $descKm,
                    $isActive
                ]);
                $message = "New upsell rule created successfully!";
            }
            $messageType = 'success';
        }
    }

    // 5e. Toggle Upsell Rule Status
    elseif ($action === 'toggle_upsell_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0) {
            $pdo->prepare("UPDATE upsell_rules SET is_active = (1 - is_active) WHERE id = ?")->execute([$ruleId]);
            $message = "Upsell rule status updated successfully!";
            $messageType = 'success';
        }
    }

    // 5f. Delete Upsell Rule
    elseif ($action === 'delete_upsell_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        if ($ruleId > 0) {
            $pdo->prepare("DELETE FROM upsell_rules WHERE id = ?")->execute([$ruleId]);
            $message = "Upsell rule deleted successfully!";
            $messageType = 'success';
        }
    }
"""

anchor_action = "$messageType = $changed > 0 ? 'success' : 'info';\n    }\n\n    // 6. Save Telegram"
assert anchor_action in content, "anchor_action not found"
content = content.replace(anchor_action, "$messageType = $changed > 0 ? 'success' : 'info';\n    }\n" + action_code + "\n    // 6. Save Telegram", 1)

# 3. Add data fetch for upsells
data_code = """
// Fetch Services and Upsell Rules for Upsell Management Tab
$allServices = [];
$allUpsellRules = [];
$upsellStats = ['total_rules' => 0, 'active_rules' => 0];
$upsellLogStats = ['shown' => 0, 'accepted' => 0, 'declined' => 0, 'rate' => 0];
try {
    $allServices = $pdo->query("SELECT id, name_en, name_km, category FROM services WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $allUpsellRules = $pdo->query("
        SELECT ur.*, s1.name_en AS trigger_service_name, s2.name_en AS offer_service_name 
        FROM upsell_rules ur 
        LEFT JOIN services s1 ON ur.trigger_service_id = s1.id 
        LEFT JOIN services s2 ON ur.offer_service_id = s2.id 
        ORDER BY ur.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $upStatStmt = $pdo->query("SELECT COUNT(*) as total_rules, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_rules FROM upsell_rules");
    $upsellStats = $upStatStmt->fetch(PDO::FETCH_ASSOC) ?: $upsellStats;
    
    $logStmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted, SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as declined FROM upsell_logs");
    $lData = $logStmt->fetch(PDO::FETCH_ASSOC);
    if ($lData && $lData['total'] > 0) {
        $upsellLogStats['shown'] = (int)$lData['total'];
        $upsellLogStats['accepted'] = (int)$lData['accepted'];
        $upsellLogStats['declined'] = (int)$lData['declined'];
        $upsellLogStats['rate'] = round(($upsellLogStats['accepted'] / max(1, $upsellLogStats['shown'])) * 100, 1);
    }
} catch (Throwable $e) {}
"""

anchor_data = "while ($r = $sStmt->fetch()) {\n    $settings[$r['setting_key']] = $r['setting_value'];\n}"
assert anchor_data in content, "anchor_data not found"
content = content.replace(anchor_data, anchor_data + "\n" + data_code, 1)

# 4. Add tab button
tab_button_old = """    <button type="button" class="btn btn-sm <?= ($activeTab === 'loyalty') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('loyalty')">
        <i class="fa-solid fa-award mr-1" style="color: #f59e0b;"></i> 6. Loyalty &amp; Tiers
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'performance') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('performance')">
        <i class="fa-solid fa-gauge-high mr-1" style="color: #10b981;"></i> 7. Performance &amp; Leaderboard
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'telegram') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('telegram')">
        <i class="fa-brands fa-telegram mr-1" style="color: #0284c7;"></i> 8. Telegram
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'sync') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('sync')">
        <i class="fa-solid fa-cloud-arrow-up mr-1" style="color: #6366f1;"></i> 9. Cloud Sync"""

tab_button_new = """    <button type="button" class="btn btn-sm <?= ($activeTab === 'loyalty') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('loyalty')">
        <i class="fa-solid fa-award mr-1" style="color: #f59e0b;"></i> 6. Loyalty &amp; Tiers
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'upsell') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('upsell')">
        <i class="fa-solid fa-bullseye mr-1" style="color: #8b5cf6;"></i> 7. Upsell &amp; Offers
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'performance') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('performance')">
        <i class="fa-solid fa-gauge-high mr-1" style="color: #10b981;"></i> 8. Performance &amp; Leaderboard
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'telegram') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('telegram')">
        <i class="fa-brands fa-telegram mr-1" style="color: #0284c7;"></i> 9. Telegram
    </button>
    <button type="button" class="btn btn-sm <?= ($activeTab === 'sync') ? 'btn-primary' : 'btn-secondary' ?>" onclick="switchTab('sync')">
        <i class="fa-solid fa-cloud-arrow-up mr-1" style="color: #6366f1;"></i> 10. Cloud Sync"""

assert tab_button_old in content, "tab_button_old not found"
content = content.replace(tab_button_old, tab_button_new, 1)

# Update remaining tab numbering
content = content.replace("10. System", "11. System", 1)

# 5. Insert tab pane before tab_performance
with open('scratch/draft_upsell_tab.py', 'r', encoding='utf-8') as f:
    draft = f.read()
# extract tab_upsell_html
idx_start = draft.find('tab_upsell_html = """') + len('tab_upsell_html = """')
idx_end = draft.rfind('"""')
tab_upsell_html = draft[idx_start:idx_end].strip()

anchor_pane = "<!-- ======================================================== -->\n<!-- GROUP 6B: PERFORMANCE & LEADERBOARD SETTINGS -->"
assert anchor_pane in content, "anchor_pane not found"
content = content.replace(anchor_pane, tab_upsell_html + "\n\n" + anchor_pane, 1)

with open('views/owner/settings.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Successfully injected Upsell Settings into views/owner/settings.php!")
