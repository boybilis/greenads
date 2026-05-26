<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

header('Content-Type: application/json');

if (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Inventory'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Admin and Inventory users can encode PR items.']);
    exit;
}

$prId = (int)($_POST['pr_id'] ?? 0);
$poItemId = (int)($_POST['po_item_id'] ?? 0);
$unitPrice = (float)($_POST['unit_price'] ?? 0);
$inventorySku = trim($_POST['inventory_sku'] ?? '');
$createdBy = $_SESSION['username'] ?? $_SESSION['user_code'];

if ($prId <= 0 || $poItemId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid item reference.']);
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tbl_inventory_in (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            pr.pr_id,
            pr.pr_ref_no,
            pr.status,
            po.po_id,
            po.po_ref_no,
            po.receipt_no,
            po.date_received,
            poi.po_item_id,
            poi.pr_item_id,
            poi.sku,
            poi.item_name,
            poi.po_qty,
            poi.unit
        FROM tbl_purchase_requests pr
        INNER JOIN tbl_purchase_orders po ON po.pr_id = pr.pr_id
        INNER JOIN tbl_purchase_order_items poi ON poi.po_id = po.po_id
        WHERE pr.pr_id = ? AND poi.po_item_id = ?
        LIMIT 1
    ");
    $stmt->execute([$prId, $poItemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('PO item was not found.');
    }

    if (($row['status'] ?? '') !== 'PO Fulfilled') {
        throw new RuntimeException('Only PO Fulfilled requests can be encoded.');
    }

    if (empty($row['receipt_no']) || empty($row['date_received'])) {
        throw new RuntimeException('PO receipt no. and received date are required before encoding.');
    }

    $targetSku = $row['sku'];
    $isRequestedSku = stripos($row['sku'], 'REQ') === 0;

    if ($isRequestedSku) {
        if ($inventorySku === '') {
            throw new RuntimeException('Please add item details first before encoding this requested item.');
        }
        $targetSku = $inventorySku;
    }

    $itemStmt = $pdo->prepare("
        SELECT sku, material_name, material_type, color, quantity, unit
        FROM tbl_items
        WHERE sku = ?
        FOR UPDATE
    ");
    $itemStmt->execute([$targetSku]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new RuntimeException('Inventory item not found: ' . $targetSku);
    }

    $targetUnit = $row['unit'];
    if ($isRequestedSku && trim((string)$targetUnit) === '') {
        $targetUnit = $item['unit'];
    }

    if ($item['unit'] !== $targetUnit) {
        throw new RuntimeException('Unit mismatch for ' . $targetSku . '. Inventory unit is ' . $item['unit'] . '.');
    }

    $dup = $pdo->prepare("
        SELECT COUNT(*)
        FROM tbl_inventory_in
        WHERE sku = ? AND receipt_no = ? AND po_code = ?
    ");
    $dup->execute([$targetSku, $row['receipt_no'], $row['po_ref_no']]);
    if ((int)$dup->fetchColumn() > 0) {
        throw new RuntimeException('This item was already encoded for this receipt.');
    }

    $stockBefore = (float)$item['quantity'];
    $quantity = (float)$row['po_qty'];
    $stockAfter = $stockBefore + $quantity;

    $insert = $pdo->prepare("
        INSERT INTO tbl_inventory_in
            (sku, item_name, quantity, unit, unit_price, stock_before, stock_after, receipt_no, receipt_date, po_code, created_by)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $targetSku,
        $item['material_name'],
        $quantity,
        $targetUnit,
        $unitPrice,
        $stockBefore,
        $stockAfter,
        $row['receipt_no'],
        $row['date_received'],
        $row['po_ref_no'],
        $createdBy
    ]);

    $update = $pdo->prepare("
        UPDATE tbl_items
        SET quantity = quantity + ?, unit_price = ?
        WHERE sku = ?
    ");
    $update->execute([$quantity, $unitPrice, $targetSku]);

    if ($isRequestedSku && $targetSku !== $row['sku']) {
        $updatePoItem = $pdo->prepare("
            UPDATE tbl_purchase_order_items
            SET sku = ?, item_name = ?, material_type = ?, color = ?, unit = ?
            WHERE po_item_id = ?
        ");
        $updatePoItem->execute([$targetSku, $item['material_name'], $item['material_type'], $item['color'], $targetUnit, $poItemId]);

        $updatePrItem = $pdo->prepare("
            UPDATE tbl_purchase_request_items
            SET sku = ?, item_name = ?, unit = ?
            WHERE pr_item_id = ?
        ");
        $updatePrItem->execute([$targetSku, $item['material_name'], $targetUnit, (int)$row['pr_item_id']]);
    }

    $remainingStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tbl_purchase_order_items poi
        LEFT JOIN tbl_inventory_in ii
            ON ii.sku = poi.sku
           AND ii.receipt_no = ?
           AND ii.po_code = ?
        WHERE poi.po_id = ? AND ii.id IS NULL
    ");
    $remainingStmt->execute([$row['receipt_no'], $row['po_ref_no'], (int)$row['po_id']]);
    $remaining = (int)$remainingStmt->fetchColumn();

    if ($remaining === 0) {
        $closeStmt = $pdo->prepare("UPDATE tbl_purchase_requests SET status = 'Encoded' WHERE pr_id = ?");
        $closeStmt->execute([$prId]);
    }

    $pdo->commit();

    audit_log($pdo, 'ENCODE_ITEM', 'Purchase Request', $row['pr_ref_no'], 'Encoded ' . $targetSku . ($targetSku !== $row['sku'] ? ' from requested SKU ' . $row['sku'] : '') . '; quantity: +' . $quantity . '; receipt_no: ' . $row['receipt_no'] . '; po_code: ' . $row['po_ref_no'] . ($remaining === 0 ? '; status: "PO Fulfilled" -> "Encoded"' : ''));

    echo json_encode([
        'status' => 'success',
        'message' => $remaining === 0 ? 'Item encoded. PR request is now closed.' : 'Item encoded successfully.',
        'remaining' => $remaining
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Request failed."]);
}
?>