<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');
header('Content-Type: application/json');

audit_log($pdo, 'LOGOUT', 'Auth', $_SESSION['user_code'] ?? null, 'User logged out.');

session_unset();
session_destroy();

echo json_encode([
    "status" => "success"
]);
exit;
?>
