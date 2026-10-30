<?php
$view = $argv[1] ?? 'dashboard';
$user = json_decode(base64_decode($argv[2] ?? ''), true) ?: [];

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/Car-WashService/index.php?view=' . $view;
$_GET = ['view' => $view];
$_POST = [];

session_start();
$_SESSION['user_id'] = $user['id'] ?? 1;
$_SESSION['username'] = $user['username'] ?? 'owner';
$_SESSION['role'] = $user['role'] ?? 'owner';
$_SESSION['full_name'] = $user['full_name'] ?? 'Owner';
$_SESSION['logged_in_at'] = time();
$_SESSION['last_activity'] = time();
session_write_close();

ob_start();
require dirname(__DIR__) . '/index.php';
echo ob_get_clean();
