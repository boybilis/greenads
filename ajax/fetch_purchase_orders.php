<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_purchase_orders (
            po_id INT AUTO_INCREMENT PRIMARY KEY,
            po_ref_no VARCHAR(30) DEFAULT NULL UNIQUE,
            pr_id INT NOT NULL,
            supplier_id INT NOT NULL,
            po_date DATE NOT NULL,
            created_by VARCHAR(100) DEFAULT NULL,
            created_by_code VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_po_ref_no (po_ref_no),
            INDEX idx_po_pr_id (pr_id),
            INDEX idx_po_supplier_id (supplier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $poColumns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('receipt_no', $poColumns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_orders ADD receipt_no VARCHAR(100) DEFAULT NULL AFTER po_date");
    }
    if (!in_array('date_received', $poColumns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_orders ADD date_received DATE DEFAULT NULL AFTER receipt_no");
    }
    if (!in_array('fulfillment_status', $poColumns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_orders ADD fulfillment_status VARCHAR(30) NOT NULL DEFAULT 'Pending' AFTER date_received");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_purchase_order_items (
            po_item_id INT AUTO_INCREMENT PRIMARY KEY,
            po_id INT NOT NULL,
            po_ref_no VARCHAR(30) DEFAULT NULL,
            pr_item_id INT NOT NULL,
            sku VARCHAR(100) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            color VARCHAR(100) DEFAULT NULL,
            request_qty DECIMAL(12,2) NOT NULL,
            po_qty DECIMAL(12,2) NOT NULL,
            unit VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_po_items_po_id (po_id),
            INDEX idx_po_items_sku (sku)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->query("
        SELECT
            po.po_id,
            po.po_ref_no,
            pr.pr_ref_no,
            po.po_date,
            po.receipt_no,
            po.date_received,
            po.fulfillment_status,
            s.supplier_name,
            po.created_by,
            COUNT(poi.po_item_id) AS item_count,
            COALESCE(SUM(poi.po_qty), 0) AS total_po_qty
        FROM tbl_purchase_orders po
        LEFT JOIN tbl_purchase_requests pr ON pr.pr_id = po.pr_id
        LEFT JOIN tbl_suppliers s ON s.supplier_id = po.supplier_id
        LEFT JOIN tbl_purchase_order_items poi ON poi.po_id = po.po_id
        GROUP BY po.po_id, po.po_ref_no, pr.pr_ref_no, po.po_date, po.receipt_no, po.date_received, po.fulfillment_status, s.supplier_name, po.created_by
        ORDER BY po.po_id DESC
    ");

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $isFulfilled = ($row['fulfillment_status'] ?? 'Pending') === 'Fulfilled';
        $poRefNo = $row['po_ref_no'] ?: ('PO-' . str_pad((string)$row['po_id'], 6, '0', STR_PAD_LEFT));
        $action = '<a href="purchase_order_print?po_id=' . (int)$row['po_id'] . '" target="_blank"><span class="badge badge-info">View</span></a>';
        if (!$isFulfilled) {
            $action .= ' | <a href="#" class="fulfill-po" data-id="' . (int)$row['po_id'] . '" data-ref="' . htmlspecialchars($poRefNo) . '"><span class="badge badge-warning">Fulfill</span></a>';
        }

        $data[] = [
            'po_id' => (int)$row['po_id'],
            'po_ref_no_raw' => $poRefNo,
            'po_ref_no' => htmlspecialchars($poRefNo),
            'pr_ref_no' => htmlspecialchars($row['pr_ref_no'] ?: '-'),
            'po_date' => htmlspecialchars($row['po_date']),
            'receipt_no' => htmlspecialchars($row['receipt_no'] ?: '-'),
            'date_received' => htmlspecialchars($row['date_received'] ?: '-'),
            'fulfillment_status' => $isFulfilled ? '<span class="badge badge-success">Fulfilled</span>' : '<span class="badge badge-secondary">Pending</span>',
            'supplier_name' => htmlspecialchars($row['supplier_name'] ?: '-'),
            'item_count' => (int)$row['item_count'],
            'total_po_qty' => number_format((float)$row['total_po_qty'], 2),
            'created_by' => htmlspecialchars($row['created_by'] ?: '-'),
            'action' => $action
        ];
    }

    echo json_encode(['data' => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'data' => [],
        'message' => $e->getMessage()
    ]);
}
