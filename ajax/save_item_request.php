<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (($_SESSION['user_type'] ?? '') === 'Inventory') {
        exit("Inventory users are not allowed to create item requests.");
    }

  

    $item_name  = $_POST['item_name'] ?? '';
    $item_color = $_POST['item_color'] ?? '';
    $requestQty = (float)($_POST['request_qty'] ?? 0);
    $unit       = trim((string)($_POST['unit'] ?? ''));
    $desc       = $_POST['description'] ?? '';
    $userCode   = $_SESSION['user_code'] ?? '';
    $username   = $_SESSION['username'] ?? '';

    if ($userCode === '' && $username === '') {
        exit("Session expired. Please log in again.");
    }

    if (empty($item_name)) {
        exit("Item name is required");
    }
    if ($requestQty <= 0 || $unit === '') {
        exit("Quantity and unit are required");
    }

    $columns = $pdo->query("SHOW COLUMNS FROM item_requests")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('request_qty', $columns, true)) {
        $pdo->exec("ALTER TABLE item_requests ADD request_qty DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER item_color");
    }
    if (!in_array('unit', $columns, true)) {
        $pdo->exec("ALTER TABLE item_requests ADD unit VARCHAR(50) DEFAULT NULL AFTER request_qty");
    }

    $stmt = $pdo->prepare("
        INSERT INTO item_requests (item_name, item_color, request_qty, unit, description, requested_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    // Store requester as user_code for consistent filtering.
    $requestedBy = $userCode !== '' ? $userCode : $username;
    $stmt->execute([$item_name, $item_color, $requestQty, $unit, $desc, $requestedBy]);

    echo "success"; // IMPORTANT for toastr
}
