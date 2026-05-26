<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');
if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['data' => [], 'message' => 'Unauthorized.']);
    exit;
}


if (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Inventory'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Admin and Inventory users can encode PR requests.']);
    exit;
}

$prId = (int)($_GET['pr_id'] ?? 0);

if ($prId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid PR request.']);
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

    $headerStmt = $pdo->prepare("
        SELECT
            pr.pr_id,
            pr.pr_ref_no,
            pr.status,
            po.po_id,
            po.po_ref_no,
            po.receipt_no,
            po.date_received
        FROM tbl_purchase_requests pr
        INNER JOIN tbl_purchase_orders po ON po.pr_id = pr.pr_id
        WHERE pr.pr_id = ?
        ORDER BY po.po_id DESC
        LIMIT 1
    ");
    $headerStmt->execute([$prId]);
    $header = $headerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$header) {
        echo json_encode(['status' => 'error', 'message' => 'No fulfilled PO found for this PR.']);
        exit;
    }

    if (($header['status'] ?? '') !== 'PO Fulfilled') {
        echo json_encode(['status' => 'error', 'message' => 'Only PO Fulfilled requests can be encoded.']);
        exit;
    }

    if (empty($header['receipt_no']) || empty($header['date_received'])) {
        echo json_encode(['status' => 'error', 'message' => 'PO receipt no. and received date are required before encoding.']);
        exit;
    }

    $itemStmt = $pdo->prepare("
        SELECT
            poi.po_item_id,
            poi.sku,
            poi.item_name,
            poi.description,
            poi.material_type,
            poi.color,
            poi.po_qty,
            poi.unit,
            COALESCE(ti.unit_price, 0) AS unit_price,
            CASE WHEN ti.sku IS NULL THEN 0 ELSE 1 END AS item_exists,
            CASE WHEN ii.id IS NULL THEN 0 ELSE 1 END AS encoded
        FROM tbl_purchase_order_items poi
        LEFT JOIN tbl_items ti ON ti.sku = poi.sku
        LEFT JOIN tbl_inventory_in ii
            ON ii.sku = poi.sku
           AND ii.receipt_no = ?
           AND ii.po_code = ?
        WHERE poi.po_id = ?
        ORDER BY poi.item_name ASC, poi.sku ASC
    ");
    $itemStmt->execute([$header['receipt_no'], $header['po_ref_no'], (int)$header['po_id']]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'header' => [
            'pr_id' => (int)$header['pr_id'],
            'pr_ref_no' => $header['pr_ref_no'],
            'po_id' => (int)$header['po_id'],
            'po_ref_no' => $header['po_ref_no'],
            'receipt_no' => $header['receipt_no'],
            'date_received' => $header['date_received']
        ],
        'items' => array_map(function($item) {
            return [
                'po_item_id' => (int)$item['po_item_id'],
                'sku' => $item['sku'],
                'item_name' => $item['item_name'],
                'description' => $item['description'],
                'material_type' => $item['material_type'],
                'color' => $item['color'],
                'po_qty' => (float)$item['po_qty'],
                'unit' => $item['unit'],
                'unit_price' => (float)$item['unit_price'],
                'item_exists' => (int)$item['item_exists'] === 1,
                'encoded' => (int)$item['encoded'] === 1
            ];
        }, $items)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Request failed."]);
}
?>