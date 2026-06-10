<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['data' => [], 'message' => 'Session expired. Please log in again.']);
    exit;
}

try {
    $prColumns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_requests")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('proj_code', $prColumns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_requests ADD proj_code VARCHAR(100) DEFAULT NULL AFTER request_date");
    }
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

    $where = '';
    $params = [];
    $userType = $_SESSION['user_type'] ?? '';
    $onlyMine = (($_GET['mine'] ?? '') === '1');

    if ($onlyMine && $userType !== 'Admin') {
        $where = 'WHERE pr.requested_by_code = ?';
        $params[] = $_SESSION['user_code'];
    } elseif (!in_array($userType, ['Admin', 'Inventory'], true)) {
        $where = 'WHERE pr.requested_by_code = ?';
        $params[] = $_SESSION['user_code'];
    }

    $stmt = $pdo->prepare("
        SELECT
            pr.pr_id,
            pr.pr_ref_no,
            pr.request_date,
            pr.proj_code,
            pr.requested_by,
            pr.status,
            MAX(COALESCE(popr.po_id, po.po_id)) AS po_id,
            COUNT(pri.pr_item_id) AS item_count,
            COALESCE(SUM(pri.request_qty), 0) AS total_qty
        FROM tbl_purchase_requests pr
        LEFT JOIN tbl_purchase_request_items pri ON pri.pr_id = pr.pr_id
        LEFT JOIN tbl_purchase_orders po ON po.pr_id = pr.pr_id
        LEFT JOIN tbl_purchase_order_prs popr ON popr.pr_id = pr.pr_id
        {$where}
        GROUP BY pr.pr_id, pr.pr_ref_no, pr.request_date, pr.proj_code, pr.requested_by, pr.status
        ORDER BY pr.pr_id DESC
    ");
    $stmt->execute($params);

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

        $action = '<a href="#" class="view-pr-request" data-id="' . (int)$row['pr_id'] . '"><span class="badge badge-info">View</span></a>';
        if (in_array($userType, ['Admin', 'Inventory'], true) && $status === 'PO Requested') {
            $action .= ' <a href="#" class="receive-pr-items" data-id="' . (int)$row['pr_id'] . '" data-ref="' . htmlspecialchars($row['pr_ref_no'] ?: ('PR-' . str_pad((string)$row['pr_id'], 6, '0', STR_PAD_LEFT))) . '"><span class="badge badge-warning">Receive Item</span></a>';
        }
        if (in_array($userType, ['Admin', 'Inventory'], true) && $status === 'PO Fulfilled') {
            $action .= ' <a href="#" class="encode-pr-request" data-id="' . (int)$row['pr_id'] . '" data-po-id="' . (int)$row['po_id'] . '"><span class="badge badge-success">Encode</span></a>';
        }
        if (!in_array($status, ['PO Requested', 'PO Fulfilled', 'Encoded'], true)) {
            $action .= ' <a href="#" class="delete-pr-request" data-id="' . (int)$row['pr_id'] . '"><span class="badge badge-danger">Delete</span></a>';
        }

        $data[] = [
            'pr_ref_no' => htmlspecialchars($row['pr_ref_no'] ?: ('PR-' . str_pad((string)$row['pr_id'], 6, '0', STR_PAD_LEFT))),
            'request_date' => htmlspecialchars($row['request_date']),
            'proj_code' => htmlspecialchars($row['proj_code'] ?: '-'),
            'requested_by' => htmlspecialchars($row['requested_by'] ?: '-'),
            'item_count' => (int)$row['item_count'],
            'total_qty' => number_format((float)$row['total_qty'], 2),
            'status_badge' => '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($status) . '</span>',
            'action' => $action
        ];
    }

    echo json_encode(['data' => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['data' => [], 'message' => "Request failed."]);
}
?>
