<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['data' => [], 'message' => 'Unauthorized.']);
    exit;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_purchase_requests (
            pr_id INT AUTO_INCREMENT PRIMARY KEY,
            pr_ref_no VARCHAR(30) DEFAULT NULL UNIQUE,
            request_date DATE NOT NULL,
            requested_by VARCHAR(100) DEFAULT NULL,
            requested_by_code VARCHAR(100) DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_purchase_requests_ref (pr_ref_no),
            INDEX idx_purchase_requests_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_purchase_request_items (
            pr_item_id INT AUTO_INCREMENT PRIMARY KEY,
            pr_id INT NOT NULL,
            sku VARCHAR(100) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            request_qty DECIMAL(12,2) NOT NULL,
            unit VARCHAR(50) DEFAULT NULL,
            on_hand_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            reserved_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            available_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
            reorder_level DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pr_items_pr_id (pr_id),
            INDEX idx_pr_items_sku (sku),
            CONSTRAINT fk_pr_items_request
                FOREIGN KEY (pr_id) REFERENCES tbl_purchase_requests(pr_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $itemStmt = $pdo->prepare("
        SELECT sku, item_name, description, request_qty, unit, available_qty
        FROM tbl_purchase_request_items
        WHERE pr_id = ?
        ORDER BY item_name ASC, sku ASC
    ");

    $stmt = $pdo->query("
        SELECT
            pr.pr_id,
            pr.pr_ref_no,
            pr.request_date,
            pr.requested_by,
            pr.status,
            COUNT(pri.pr_item_id) AS item_count,
            COALESCE(SUM(pri.request_qty), 0) AS total_qty
        FROM tbl_purchase_requests pr
        LEFT JOIN tbl_purchase_request_items pri ON pri.pr_id = pr.pr_id
        GROUP BY pr.pr_id, pr.pr_ref_no, pr.request_date, pr.requested_by, pr.status
        ORDER BY pr.pr_id DESC
    ");

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'] ?: 'Pending';
        if ($status === 'Encoded') {
            $badgeClass = 'badge-primary';
        } elseif ($status === 'For Pickup') {
            $badgeClass = 'badge-success';
        } elseif ($status === 'PO Fulfilled') {
            $badgeClass = 'badge-warning';
        } elseif (in_array($status, ['Completed', 'PO Requested'], true)) {
            $badgeClass = 'badge-success';
        } else {
            $badgeClass = 'badge-warning';
        }
        $itemStmt->execute([(int)$row['pr_id']]);

        $items = [];
        while ($item = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'sku' => $item['sku'],
                'item_name' => $item['item_name'],
                'description' => $item['description'],
                'request_qty' => number_format((float)$item['request_qty'], 2),
                'unit' => $item['unit'],
                'available_qty' => number_format((float)$item['available_qty'], 2)
            ];
        }

        $action = sprintf(
            '<a href="#" class="view-pr-request" data-id="%d">
                <span class="badge badge-info">View</span>
             </a>',
            (int)$row['pr_id']
        );

        if (!in_array($status, ['PO Requested', 'PO Fulfilled', 'Encoded'], true)) {
            $action .= sprintf(
                ' <a href="#" class="create-po-request" data-id="%d">
                    <span class="badge badge-secondary">For PO</span>
                 </a>',
                (int)$row['pr_id']
            );
        }

        $data[] = [
            'pr_id' => (int)$row['pr_id'],
            'items' => $items,
            'pr_ref_no' => htmlspecialchars($row['pr_ref_no'] ?: ('PR-' . str_pad((string)$row['pr_id'], 6, '0', STR_PAD_LEFT))),
            'request_date' => htmlspecialchars($row['request_date']),
            'requested_by' => htmlspecialchars($row['requested_by'] ?: '-'),
            'item_count' => (int)$row['item_count'],
            'total_qty' => number_format((float)$row['total_qty'], 2),
            'status_badge' => '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($status) . '</span>',
            'action' => $action
    ];
	}

    echo json_encode(['data' => $data]);
} catch (Exception $e) {
    error_log('fetch_purchase_requests failed.');
    http_response_code(500);
    echo json_encode([
        'data' => [],
        'message' => 'Request failed.'
    ]);
}
