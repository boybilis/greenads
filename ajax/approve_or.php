<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Session expired. Please log in again.'
    ]);
    exit;
}

$orId = (int)($_POST['or_id'] ?? 0);
$createdBy = $_SESSION['username'] ?? $_SESSION['user_code'];

if ($orId <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid material request.'
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

    $headerStmt = $pdo->prepare("
        SELECT or_id, or_no, or_date, or_status
        FROM tbl_or
        WHERE or_id = ?
        FOR UPDATE
    ");
    $headerStmt->execute([$orId]);
    $or = $headerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$or) {
        throw new RuntimeException('Material request not found.');
    }

    if ((int)$or['or_status'] !== 0) {
        throw new RuntimeException('Only pending material requests can be approved.');
    }

    $itemsStmt = $pdo->prepare("
        SELECT sku, item_name, qty, unit
        FROM tbl_or_items
        WHERE or_id = ?
    ");
    $itemsStmt->execute([$orId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new RuntimeException('Material request has no items.');
    }

    $insertOut = $pdo->prepare("
        INSERT INTO tbl_inventory_out
            (sku, item_name, quantity, unit, stock_before, stock_after, reference_no, transaction_date, remarks, created_by)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $updateItem = $pdo->prepare("
        UPDATE tbl_items
        SET quantity = quantity - ?
        WHERE sku = ?
    ");

    foreach ($items as $requestItem) {
        $sku = $requestItem['sku'];
        $qty = (float)$requestItem['qty'];
        $unit = $requestItem['unit'];

        $itemStmt = $pdo->prepare("
            SELECT
                i.sku,
                i.material_name,
                i.quantity,
                i.unit,
                COALESCE(r.reserved_qty, 0) AS other_reserved_qty
            FROM tbl_items i
            LEFT JOIN (
                SELECT oi.sku, SUM(oi.qty) AS reserved_qty
                FROM tbl_or_items oi
                INNER JOIN tbl_or o ON o.or_id = oi.or_id
                WHERE o.or_status = 0 AND o.or_id <> ?
                GROUP BY oi.sku
            ) r ON r.sku = i.sku
            WHERE i.sku = ?
            FOR UPDATE
        ");
        $itemStmt->execute([$orId, $sku]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new RuntimeException('Item not found: ' . $sku);
        }

        if ($item['unit'] !== $unit) {
            throw new RuntimeException('Unit mismatch for item: ' . $sku);
        }

        $stockBefore = (float)$item['quantity'];
        $availableForThisMr = $stockBefore - (float)$item['other_reserved_qty'];
        if ($qty > $availableForThisMr) {
            throw new RuntimeException('Insufficient stock for ' . $sku . '. Available after other reservations: ' . $availableForThisMr . ' ' . $unit);
        }

        $stockAfter = $stockBefore - $qty;

        $insertOut->execute([
            $sku,
            $requestItem['item_name'],
            $qty,
            $unit,
            $stockBefore,
            $stockAfter,
            $or['or_no'],
            $or['or_date'],
            'Approved material request',
            $createdBy
        ]);

        $updateItem->execute([$qty, $sku]);
    }

    $approveStmt = $pdo->prepare("UPDATE tbl_or SET or_status = 1 WHERE or_id = ?");
    $approveStmt->execute([$orId]);

    $pdo->commit();
    audit_log($pdo, 'APPROVE', 'Material Request', $or['or_no'], 'or_status: "Pending" -> "Approved"; inventory quantity deducted for requested items.');

    echo json_encode([
        'status' => 'success',
        'message' => 'Material request approved and inventory updated.'
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
