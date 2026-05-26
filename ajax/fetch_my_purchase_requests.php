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
    $where = '';
    $params = [];

    if (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Inventory'], true)) {
        $where = 'WHERE pr.requested_by_code = ?';
        $params[] = $_SESSION['user_code'];
    }

    $stmt = $pdo->prepare("
        SELECT
            pr.pr_id,
            pr.pr_ref_no,
            pr.request_date,
            pr.status,
            COUNT(pri.pr_item_id) AS item_count,
            COALESCE(SUM(pri.request_qty), 0) AS total_qty
        FROM tbl_purchase_requests pr
        LEFT JOIN tbl_purchase_request_items pri ON pri.pr_id = pr.pr_id
        {$where}
        GROUP BY pr.pr_id, pr.pr_ref_no, pr.request_date, pr.status
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
        if (in_array($_SESSION['user_type'] ?? '', ['Admin', 'Inventory'], true) && $status === 'PO Fulfilled') {
            $action .= ' <a href="#" class="encode-pr-request" data-id="' . (int)$row['pr_id'] . '"><span class="badge badge-success">Encode</span></a>';
        }

        $data[] = [
            'pr_ref_no' => htmlspecialchars($row['pr_ref_no'] ?: ('PR-' . str_pad((string)$row['pr_id'], 6, '0', STR_PAD_LEFT))),
            'request_date' => htmlspecialchars($row['request_date']),
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
