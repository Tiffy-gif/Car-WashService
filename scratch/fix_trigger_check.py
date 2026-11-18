with open('api/pos/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

old_trigger_check = """        // Trigger service condition
        if (!empty($r['trigger_service_id'])) {
            $trigId = (int)$r['trigger_service_id'];
            if (!in_array($trigId, $serviceIds, true)) {
                continue;
            }
        }"""

new_trigger_check = """        // Trigger service condition
        if (!empty($r['trigger_service_id'])) {
            $trigId = (int)$r['trigger_service_id'];
            if (!in_array($trigId, $serviceIds, true)) {
                continue;
            }
        } elseif (!empty($r['trigger_category'])) {
            $cat = $r['trigger_category'];
            $hasCat = false;
            foreach ($cart as $it) {
                if (($it['category'] ?? '') === $cat) { $hasCat = true; break; }
            }
            if (!$hasCat) {
                continue;
            }
        }

        // Trigger mileage check
        if (!empty($r['trigger_min_mileage'])) {
            $mileage = !empty($inputData['mileage']) ? (int)$inputData['mileage'] : null;
            if ($mileage === null || $mileage < (int)$r['trigger_min_mileage']) {
                continue;
            }
        }"""

assert old_trigger_check in content, "old_trigger_check not found"
content = content.replace(old_trigger_check, new_trigger_check, 1)

with open('api/pos/index.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Updated trigger check in api/pos/index.php")
