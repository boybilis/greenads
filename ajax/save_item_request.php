<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  

    $item_name  = $_POST['item_name'] ?? '';
    $item_color = $_POST['item_color'] ?? '';
    $desc       = $_POST['description'] ?? '';
    $user       = $_SESSION['username'] ?? 'Unknown';

    if (empty($item_name)) {
        exit("Item name is required");
    }

    $stmt = $pdo->prepare("
        INSERT INTO item_requests (item_name, item_color, description, requested_by)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([$item_name, $item_color, $desc, $user]);

    echo "success"; // IMPORTANT for toastr
}