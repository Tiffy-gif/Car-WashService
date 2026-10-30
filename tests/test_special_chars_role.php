<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();

$testNameEn = "Supervisor's \"Chief\" & Lead";
$testNameKm = "ប្រធាន 'ផ្នែក' គ្រប់គ្រង \"ពិសេស\"";
$testCode = "lead_chief_" . time();

echo "Testing role creation with special chars & Khmer text...\n";
$stmt = $pdo->prepare("
    INSERT INTO roles (code, name_en, name_km, description, is_system, is_active, is_commission_eligible, can_assign_washer, can_assign_mechanic)
    VALUES (?, ?, ?, ?, 0, 1, 1, 1, 0)
");
$stmt->execute([$testCode, $testNameEn, $testNameKm, "Test with quotes and apostrophe: O'Connor's"]);
$roleId = (int)$pdo->lastInsertId();

echo "Created role ID: {$roleId}\n";

$fetch = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
$fetch->execute([$roleId]);
$row = $fetch->fetch(PDO::FETCH_ASSOC);

echo "Fetched name_en: " . $row['name_en'] . "\n";
echo "Fetched name_km: " . $row['name_km'] . "\n";

assert($row['name_en'] === $testNameEn, "name_en matches exact string");
assert($row['name_km'] === $testNameKm, "name_km matches exact string");

// Clean up
$pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$roleId]);
echo "Cleaned up test role successfully!\n";
