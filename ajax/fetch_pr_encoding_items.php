<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['data' => [], 'message' => 'Unauthorized.']);
    exit;
}


if (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Inventory', 'Purchasing'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Admin, Inventory, and Purchasing users can encode PR requests.']);
    exit;
}

$prId = (int)($_GET['pr_id'] ?? 0);
$poId = (int)($_GET['po_id'] ?? 0);

if ($prId <= 0 && $poId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid PR/PO request.']);
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_purchase_order_prs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            po_id INT NOT NULL,
            pr_id INT NOT NULL,
            pr_ref_no VARCHAR(30) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_po_pr (po_id, pr_id),
            INDEX idx_po_prs_po_id (po_id),
            INDEX idx_po_prs_pr_id (pr_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if ($poId > 0) {
        $headerStmt = $pdo->prepare("
            SELECT
                pr.pr_id,
                pr.pr_ref_no,
                pr.status,
                po.po_id,
                po.po_ref_no,
                po.receipt_no,
                po.date_received
            FROM tbl_purchase_orders po
            LEFT JOIN tbl_purchase_order_prs popr ON popr.po_id = po.po_id AND popr.pr_id = ?
            INNER JOIN tbl_purchase_requests pr ON pr.pr_id = COALESCE(popr.pr_id, po.pr_id)
            WHERE po.po_id = ? AND pr.pr_id = ?
            LIMIT 1
        ");
        $headerStmt->execute([$prId, $poId, $prId]);
    } else {
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
    }
    $header = $headerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$header) {
        echo json_encode(['status' => 'error', 'message' => 'No fulfilled PO found for this PR.']);
        exit;
    }

    if (($header['status'] ?? '') !== 'PO Fulfilled') {
        echo json_encode(['status' => 'error', 'message' => 'Only PO Fulfilled requests can be encoded.']);
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
            COALESCE(poi.unit_price, 0) AS unit_price,
            CASE WHEN EXISTS (
                SELECT 1
                FROM tbl_inventory_in ii
                WHERE ii.sku = poi.sku
                  AND ii.receipt_no = ?
                  AND ii.po_code = ?
            ) THEN 1 ELSE 0 END AS encoded
        FROM tbl_purchase_order_items poi
        INNER JOIN tbl_purchase_request_items pri ON pri.pr_item_id = poi.pr_item_id
        WHERE poi.po_id = ?
          AND pri.pr_id = ?
        ORDER BY poi.item_name ASC, poi.sku ASC
    ");
    $itemStmt->execute([
        $header['receipt_no'],
        $header['po_ref_no'],
        (int)$header['po_id'],
        (int)$header['pr_id']
    ]);
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
                'item_exists' => stripos((string)$item['sku'], 'REQ') !== 0,
                'encoded' => (int)($item['encoded'] ?? 0) === 1
            ];
        }, $items)
    ]);
} catch (Exception $e) {
    error_log('fetch_pr_encoding_items failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Request failed."]);
}
?>
