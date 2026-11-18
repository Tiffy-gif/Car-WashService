<?php
require_once __DIR__ . '/../api/db.php';
$db = getDbConnection();
$st = $db->query("SELECT id, username, full_name, role, pin, is_active FROM staff");
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
