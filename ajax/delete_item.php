<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}

if (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Inventory'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Admin or Inventory can delete items.']);
    exit;
}

$itemId = (int)($_POST['id'] ?? 0);
if ($itemId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid item reference.']);
    exit;
}

function delete_item_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function delete_item_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $itemStmt = $pdo->prepare("SELECT id, sku, material_name FROM tbl_items WHERE id = ? LIMIT 1");
    $itemStmt->execute([$itemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        echo json_encode(['status' => 'error', 'message' => 'Item not found.']);
        exit;
    }

    $sku = $item['sku'];
    $checks = [
        ['tbl_or_items', 'sku', 'material requests'],
        ['tbl_purchase_request_items', 'sku', 'purchase requests'],
        ['tbl_purchase_order_items', 'sku', 'purchase orders'],
        ['tbl_inventory_in', 'sku', 'inventory-in history'],
        ['tbl_inventory_out', 'sku', 'inventory-out history']
    ];

    foreach ($checks as [$table, $column, $label]) {
        if (!delete_item_table_exists($pdo, $table) || !delete_item_column_exists($pdo, $table, $column)) {
            continue;
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
        $countStmt->execute([$sku]);
        if ((int)$countStmt->fetchColumn() > 0) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Cannot delete this item because it is already used in ' . $label . '.'
            ]);
            exit;
        }
    }

    $deleteStmt = $pdo->prepare("DELETE FROM tbl_items WHERE id = ?");
    $deleteStmt->execute([$itemId]);

    audit_log($pdo, 'DELETE', 'Items', $sku, 'Deleted item ' . ($item['material_name'] ?? '') . '.');

    echo json_encode(['status' => 'success', 'message' => 'Item deleted successfully.']);
} catch (Throwable $e) {
    error_log('delete_item failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()]);
}
?>
