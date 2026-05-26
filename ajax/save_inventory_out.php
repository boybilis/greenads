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
$referenceNo = trim($_POST['reference_no'] ?? '');
$transactionDate = trim($_POST['transaction_date'] ?? '');
$remarks = trim($_POST['remarks'] ?? '');
$createdBy = $_SESSION['username'] ?? $_SESSION['user_code'];

if ($sku === '' || $quantity <= 0 || $unit === '' || $referenceNo === '' || $transactionDate === '') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Please complete all required fields.'
    ]);
    exit;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_inventory_out (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(100) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            stock_before DECIMAL(12,2) NOT NULL DEFAULT 0,
            stock_after DECIMAL(12,2) NOT NULL DEFAULT 0,
            reference_no VARCHAR(100) NOT NULL,
            transaction_date DATE NOT NULL,
            remarks TEXT DEFAULT NULL,
            created_by VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_inventory_out_sku (sku),
            INDEX idx_inventory_out_reference_no (reference_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM tbl_inventory_out")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('stock_before', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_inventory_out ADD stock_before DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit");
    }
    if (!in_array('stock_after', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_inventory_out ADD stock_after DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER stock_before");
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            i.sku,
            i.material_name,
            i.quantity,
            i.unit,
            COALESCE(r.reserved_qty, 0) AS reserved_qty
        FROM tbl_items i
        LEFT JOIN (
            SELECT oi.sku, SUM(oi.qty) AS reserved_qty
            FROM tbl_or_items oi
            INNER JOIN tbl_or o ON o.or_id = oi.or_id
            WHERE o.or_status = 0
            GROUP BY oi.sku
        ) r ON r.sku = i.sku
        WHERE i.sku = ?
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

    $currentQty = (float)$item['quantity'];
    $reservedQty = (float)$item['reserved_qty'];
    $availableQty = $currentQty - $reservedQty;

    if ($quantity > $availableQty) {
        throw new RuntimeException('Quantity out cannot exceed available stock. Available: ' . $availableQty . ' ' . $unit);
    }

    $stockBefore = $currentQty;
    $stockAfter = $stockBefore - $quantity;

    $dup = $pdo->prepare("
        SELECT COUNT(*)
        FROM tbl_inventory_out
        WHERE sku = ? AND reference_no = ?
    ");
    $dup->execute([$sku, $referenceNo]);
    if ((int)$dup->fetchColumn() > 0) {
        throw new RuntimeException('This reference number was already recorded for the selected item.');
    }

    $insert = $pdo->prepare("
        INSERT INTO tbl_inventory_out
            (sku, item_name, quantity, unit, stock_before, stock_after, reference_no, transaction_date, remarks, created_by)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $sku,
        $item['material_name'],
        $quantity,
        $unit,
        $stockBefore,
        $stockAfter,
        $referenceNo,
        $transactionDate,
        $remarks !== '' ? $remarks : null,
        $createdBy
    ]);

    $update = $pdo->prepare("
        UPDATE tbl_items
        SET quantity = quantity - ?
        WHERE sku = ?
    ");
    $update->execute([$quantity, $sku]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Inventory out saved successfully.'
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
