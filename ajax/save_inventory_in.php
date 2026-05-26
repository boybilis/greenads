<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Session expired. Please log in again.'
    ]);
    exit;
}

$sku = trim($_POST['sku'] ?? '');
$quantity = (float)($_POST['quantity'] ?? 0);
$unit = trim($_POST['unit'] ?? '');
$unitPrice = (float)($_POST['unit_price'] ?? 0);
$receiptNo = trim($_POST['receipt_no'] ?? '');
$receiptDate = trim($_POST['receipt_date'] ?? '');
$poCode = trim($_POST['po_code'] ?? '');
$createdBy = $_SESSION['username'] ?? $_SESSION['user_code'];

if ($sku === '' || $quantity <= 0 || $unit === '' || $receiptNo === '' || $receiptDate === '') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please complete all required fields.'
    ]);
    exit;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_inventory_in (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(100) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL,
            stock_before DECIMAL(12,2) NOT NULL DEFAULT 0,
            stock_after DECIMAL(12,2) NOT NULL DEFAULT 0,
            receipt_no VARCHAR(100) NOT NULL,
            receipt_date DATE NOT NULL,
            po_code VARCHAR(100) DEFAULT NULL,
            created_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_inventory_in_sku (sku),
            INDEX idx_inventory_in_receipt_no (receipt_no),
            INDEX idx_inventory_in_po_code (po_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM tbl_inventory_in")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('stock_before', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_inventory_in ADD stock_before DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit_price");
    }
    if (!in_array('stock_after', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_inventory_in ADD stock_after DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER stock_before");
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT sku, material_name, quantity, unit
        FROM tbl_items
        WHERE sku = ?
        FOR UPDATE
    ");
    $stmt->execute([$sku]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new RuntimeException('Selected item was not found.');
    }

    if ($item['unit'] !== $unit) {
        throw new RuntimeException('Selected unit does not match the existing item unit.');
    }

    $stockBefore = (float)$item['quantity'];
    $stockAfter = $stockBefore + $quantity;

    $dup = $pdo->prepare("
        SELECT COUNT(*)
        FROM tbl_inventory_in
        WHERE sku = ? AND receipt_no = ?
    ");
    $dup->execute([$sku, $receiptNo]);
    if ((int)$dup->fetchColumn() > 0) {
        throw new RuntimeException('This receipt number was already recorded for the selected item.');
    }

    $insert = $pdo->prepare("
        INSERT INTO tbl_inventory_in
            (sku, item_name, quantity, unit, unit_price, stock_before, stock_after, receipt_no, receipt_date, po_code, created_by)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $sku,
        $item['material_name'],
        $quantity,
        $unit,
        $unitPrice,
        $stockBefore,
        $stockAfter,
        $receiptNo,
        $receiptDate,
        $poCode !== '' ? $poCode : null,
        $createdBy
    ]);

    $update = $pdo->prepare("
        UPDATE tbl_items
        SET quantity = quantity + ?, unit_price = ?
        WHERE sku = ?
    ");
    $update->execute([$quantity, $unitPrice, $sku]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Inventory updated successfully.'
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => "Request failed."
    ]);
}
