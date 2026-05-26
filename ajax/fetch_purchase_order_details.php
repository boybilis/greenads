<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

$poId = (int)($_GET['po_id'] ?? 0);

if ($poId <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid PO reference.'
    ]);
    exit;
}

try {
    $headerStmt = $pdo->prepare("
        SELECT
            po.po_id,
            po.po_ref_no,
            po.po_date,
            po.created_by,
            pr.pr_ref_no,
            s.supplier_id,
            s.supplier_name,
            s.supplier_owner,
            s.address,
            s.contact_no,
            s.email
        FROM tbl_purchase_orders po
        LEFT JOIN tbl_purchase_requests pr ON pr.pr_id = po.pr_id
        LEFT JOIN tbl_suppliers s ON s.supplier_id = po.supplier_id
        WHERE po.po_id = ?
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
            poi.item_name,
            COALESCE(poi.material_type, ti.material_type) AS material_type,
            poi.description,
            poi.color,
            poi.request_qty,
            poi.po_qty,
            poi.unit
        FROM tbl_purchase_order_items poi
        LEFT JOIN tbl_items ti ON ti.sku = poi.sku
        WHERE poi.po_id = ?
        ORDER BY poi.item_name ASC, poi.sku ASC
    ");
    $itemStmt->execute([$poId]);

    $items = [];
    while ($row = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'sku' => $row['sku'],
            'item_name' => $row['item_name'],
            'material_type' => $row['material_type'],
            'description' => $row['description'],
            'color' => $row['color'] ?: 'N/A',
            'request_qty' => (float)$row['request_qty'],
            'po_qty' => (float)$row['po_qty'],
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
        'message' => $e->getMessage()
    ]);
}
