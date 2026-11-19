<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
header('Location: ' . APP_URL . '/index.php?view=reports');
exit;
