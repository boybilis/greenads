<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');
if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['data' => [], 'message' => 'Unauthorized.']);
    exit;
}


$poId = (int)($_GET['po_id'] ?? 0);

if ($poId <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid PO reference.'
    ]);
    exit;
}

try {
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
    $poItemColumns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_order_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('pr_ref_no', $poItemColumns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_order_items ADD pr_ref_no VARCHAR(30) DEFAULT NULL AFTER pr_item_id");
    }

    $headerStmt = $pdo->prepare("
        SELECT
            po.po_id,
            po.po_ref_no,
            po.po_date,
            po.created_by,
            COALESCE(GROUP_CONCAT(DISTINCT popr.pr_ref_no ORDER BY popr.pr_id SEPARATOR ', '), pr.pr_ref_no) AS pr_ref_no,
            s.supplier_id,
            s.supplier_name,
            s.supplier_owner,
            s.address,
            s.contact_no,
            s.email
        FROM tbl_purchase_orders po
        LEFT JOIN tbl_purchase_requests pr ON pr.pr_id = po.pr_id
        LEFT JOIN tbl_purchase_order_prs popr ON popr.po_id = po.po_id
        LEFT JOIN tbl_suppliers s ON s.supplier_id = po.supplier_id
        WHERE po.po_id = ?
        GROUP BY po.po_id, po.po_ref_no, po.po_date, po.created_by, pr.pr_ref_no, s.supplier_id, s.supplier_name, s.supplier_owner, s.address, s.contact_no, s.email
    ");
    $headerStmt->execute([$poId]);
    $header = $headerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$header) {
        echo json_encode([
            'status' => 'error',
            'message' => 'PO request was not found.'
        ]);
        exit;
    }

    $itemStmt = $pdo->prepare("
        SELECT
            poi.sku,
            COALESCE(poi.pr_ref_no, pr.pr_ref_no) AS pr_ref_no,
            poi.item_name,
            COALESCE(poi.material_type, ti.material_type) AS material_type,
            poi.description,
            poi.color,
            poi.request_qty,
            poi.po_qty,
            COALESCE(poi.unit_price, 0) AS unit_price,
            COALESCE(poi.line_total, (poi.po_qty * COALESCE(poi.unit_price, 0))) AS line_total,
            poi.unit
        FROM tbl_purchase_order_items poi
        LEFT JOIN tbl_purchase_request_items pri ON pri.pr_item_id = poi.pr_item_id
        LEFT JOIN tbl_purchase_requests pr ON pr.pr_id = pri.pr_id
        LEFT JOIN tbl_items ti ON ti.sku = poi.sku
        WHERE poi.po_id = ?
        ORDER BY COALESCE(poi.pr_ref_no, pr.pr_ref_no) ASC, poi.item_name ASC, poi.sku ASC
    ");
    $itemStmt->execute([$poId]);

    $items = [];
    while ($row = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'sku' => $row['sku'],
            'pr_ref_no' => $row['pr_ref_no'] ?: '-',
            'item_name' => $row['item_name'],
            'material_type' => $row['material_type'],
            'description' => $row['description'],
            'color' => $row['color'] ?: 'N/A',
            'request_qty' => (float)$row['request_qty'],
            'po_qty' => (float)$row['po_qty'],
            'unit_price' => (float)$row['unit_price'],
            'line_total' => (float)$row['line_total'],
            'unit' => $row['unit']
        ];
    }

    echo json_encode([
        'status' => 'success',
        'purchase_order' => [
            'po_id' => (int)$header['po_id'],
            'po_ref_no' => $header['po_ref_no'],
            'po_date' => $header['po_date'],
            'pr_ref_no' => $header['pr_ref_no'],
            'created_by' => $header['created_by'],
            'supplier' => [
                'supplier_id' => (int)$header['supplier_id'],
                'supplier_name' => $header['supplier_name'],
                'supplier_owner' => $header['supplier_owner'],
                'address' => $header['address'],
                'contact_no' => $header['contact_no'],
                'email' => $header['email']
            ],
            'items' => $items
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => "Request failed."
    ]);
}
