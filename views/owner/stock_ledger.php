<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';

// Seamlessly forward to unified Products & Stock Hub -> Movement Ledger tab
$prodId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$targetUrl = APP_URL . "/index.php?view=products&tab=ledger" . ($prodId > 0 ? "&product_id=" . $prodId : "");

header("Location: " . $targetUrl, true, 302);
exit;
