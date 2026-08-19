<?php
session_start();
include_once('config.php');
include_once('purchase_order_status_helper.php');

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

    // Keep PR workflow statuses aligned with the linked PO's actual state.
    sync_approved_po_pr_statuses($pdo);
    sync_verified_po_pr_statuses($pdo);
    $poColumns = $pdo->query("SHOW COLUMNS FROM tbl_purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('approval_status', $poColumns, true)) {
        $pdo->exec("ALTER TABLE tbl_purchase_orders ADD approval_status VARCHAR(30) NOT NULL DEFAULT 'Pending'");
    }

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

    $statusFilter = trim((string)($_GET['status_filter'] ?? ''));
    $orderSql = "
        CASE WHEN COALESCE(pr.status, 'Pending') = 'Pending' THEN 0 ELSE 1 END ASC,
        pr.request_date DESC,
        pr.pr_id DESC
    ";
    if ($statusFilter === 'po_requested') {
        $where .= ($where === '' ? 'WHERE ' : ' AND ') . "TRIM(pr.status) IN ('PO Requested', 'PO Approved', 'PO Fulfilled')";
        $orderSql = "
            CASE TRIM(pr.status)
                WHEN 'PO Fulfilled' THEN 0
                WHEN 'PO Approved' THEN 1
                WHEN 'PO Requested' THEN 2
                ELSE 3
            END ASC,
            pr.request_date DESC,
            pr.pr_id DESC
        ";
    } elseif ($statusFilter === 'exclude_po_requested') {
        $where .= ($where === '' ? 'WHERE ' : ' AND ') . "TRIM(COALESCE(pr.status, 'Pending')) NOT IN ('PO Requested', 'PO Approved', 'PO Fulfilled')";
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
            MAX(CASE
                WHEN COALESCE(linked_po.approval_status, po.approval_status, 'Pending') = 'Approved' THEN 1
                ELSE 0
            END) AS po_approved,
            COALESCE(pri.item_count, 0) AS item_count,
            COALESCE(pri.total_qty, 0) AS total_qty
        FROM tbl_purchase_requests pr
        LEFT JOIN (
            SELECT pr_id, COUNT(*) AS item_count, SUM(request_qty) AS total_qty
            FROM tbl_purchase_request_items
            GROUP BY pr_id
        ) pri ON pri.pr_id = pr.pr_id
        LEFT JOIN tbl_purchase_orders po ON po.pr_id = pr.pr_id
        LEFT JOIN tbl_purchase_order_prs popr ON popr.pr_id = pr.pr_id
        LEFT JOIN tbl_purchase_orders linked_po ON linked_po.po_id = popr.po_id
        {$where}
        GROUP BY pr.pr_id, pr.pr_ref_no, pr.request_date, pr.proj_code, pr.requested_by, pr.status, pri.item_count, pri.total_qty
        ORDER BY {$orderSql}
    ");
    $stmt->execute($params);

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'] ?: 'Pending';
        $prRefNo = $row['pr_ref_no'] ?: ('PR-' . str_pad((string)$row['pr_id'], 6, '0', STR_PAD_LEFT));
        $requestDateDisplay = !empty($row['request_date']) ? date('M d, Y', strtotime($row['request_date'])) : '-';
        $prDisplay = htmlspecialchars($prRefNo) . '<br><small class="text-muted">' . htmlspecialchars($requestDateDisplay) . '</small>';
        $projectRequestedByDisplay = htmlspecialchars($row['proj_code'] ?: '-')
            . '<br><small class="text-muted">' . htmlspecialchars($row['requested_by'] ?: '-') . '</small>';
        if ($status === 'Encoded') {
            $badgeClass = 'status-primary';
        } elseif ($status === 'For Pickup') {
            $badgeClass = 'status-success';
        } elseif ($status === 'PO Fulfilled') {
            $badgeClass = 'status-warning';
        } elseif ($status === 'PO Approved') {
            $badgeClass = 'status-info';
        } elseif (in_array($status, ['Completed', 'PO Requested'], true)) {
            $badgeClass = 'status-success';
        } else {
            $badgeClass = 'status-warning';
        }

        $action = '<a href="#" class="view-pr-request" data-id="' . (int)$row['pr_id'] . '"><span class="badge badge-info">View</span></a>';
        if (in_array($userType, ['Admin', 'Inventory'], true) && $status === 'PO Requested') {
            $action .= ' <span class="badge badge-light border text-muted">PO for Approval</span>';
        }
        if (in_array($userType, ['Admin', 'Inventory'], true) && $status === 'PO Approved') {
            $action .= ' <span class="badge badge-warning">For Delivery</span>';
        }
        if (in_array($userType, ['Admin', 'Inventory'], true) && $status === 'PO Fulfilled') {
            $action .= ' <a href="#" class="encode-pr-request" data-id="' . (int)$row['pr_id'] . '" data-po-id="' . (int)$row['po_id'] . '"><span class="badge badge-success">Receive Item</span></a>';
        }
        if (!in_array($status, ['PO Requested', 'PO Approved', 'PO Fulfilled', 'Encoded'], true)) {
            $action .= ' <a href="#" class="delete-pr-request" data-id="' . (int)$row['pr_id'] . '"><span class="badge badge-danger">Delete</span></a>';
        }

        $data[] = [
            'pr_ref_no' => htmlspecialchars($prRefNo),
            'pr_display' => $prDisplay,
            'request_date' => htmlspecialchars($row['request_date']),
            'proj_code' => htmlspecialchars($row['proj_code'] ?: '-'),
            'requested_by' => htmlspecialchars($row['requested_by'] ?: '-'),
            'project_requested_by_display' => $projectRequestedByDisplay,
            'item_count' => (int)$row['item_count'],
            'total_qty' => number_format((float)$row['total_qty'], 2),
            'status_badge' => '<span class="status-capsule ' . $badgeClass . '">' . htmlspecialchars($status) . '</span>',
            'action' => $action
        ];
    }

    echo json_encode(['data' => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['data' => [], 'message' => "Request failed."]);
}
?>
