<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid item.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM tbl_items WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    echo json_encode(['status' => 'error', 'message' => 'Item not found.']);
    exit;
}

echo json_encode(['status' => 'success', 'data' => $item]);
exit;
?>
