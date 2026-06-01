<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (($_SESSION['user_type'] ?? '') === 'Inventory') {
        exit("Inventory users are not allowed to create item requests.");
    }

  

    $item_name  = $_POST['item_name'] ?? '';
    $item_color = $_POST['item_color'] ?? '';
    $desc       = $_POST['description'] ?? '';
    $userCode   = $_SESSION['user_code'] ?? '';
    $username   = $_SESSION['username'] ?? '';

    if ($userCode === '' && $username === '') {
        exit("Session expired. Please log in again.");
    }

    if (empty($item_name)) {
        exit("Item name is required");
    }

    $stmt = $pdo->prepare("
        INSERT INTO item_requests (item_name, item_color, description, requested_by)
        VALUES (?, ?, ?, ?)
    ");

    // Store requester as user_code for consistent filtering.
    $requestedBy = $userCode !== '' ? $userCode : $username;
    $stmt->execute([$item_name, $item_color, $desc, $requestedBy]);

    echo "success"; // IMPORTANT for toastr
}
