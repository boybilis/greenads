<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (($_SESSION['user_type'] ?? '') === 'Inventory') {
        exit("Inventory users are not allowed to create item requests.");
    }

  

    $requestId  = (int)($_POST['request_id'] ?? 0);
    $item_name  = trim((string)($_POST['item_name'] ?? ''));
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

    $requestedBy = $userCode !== '' ? $userCode : $username;

    if ($requestId > 0) {
        $stmt = $pdo->prepare("SELECT id, status, requested_by FROM item_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            exit("Item request not found.");
        }
        if (($existing['status'] ?? '') !== 'Pending') {
            exit("Only pending item requests can be edited.");
        }
        if (($_SESSION['user_type'] ?? '') !== 'Admin' && !in_array($existing['requested_by'], [$userCode, $username], true)) {
            exit("You are not allowed to edit this item request.");
        }

        $stmt = $pdo->prepare("
            UPDATE item_requests
            SET item_name = ?, item_color = ?, request_qty = ?, unit = ?, description = ?
            WHERE id = ?
        ");
        $stmt->execute([$item_name, $item_color, $requestQty, $unit, $desc, $requestId]);

        echo "success";
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO item_requests (item_name, item_color, request_qty, unit, description, requested_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    // Store requester as user_code for consistent filtering.
    $stmt->execute([$item_name, $item_color, $requestQty, $unit, $desc, $requestedBy]);

    echo "success"; // IMPORTANT for toastr
}
