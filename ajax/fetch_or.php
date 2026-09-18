<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(["data" => [], "message" => "Unauthorized."]);
    exit;
}

$statusFilters = ['pending' => 0, 'approved' => 1, 'cancelled' => 2, 'claimed' => 3];
$statusFilter = $_GET['status'] ?? '';
if (!is_string($statusFilter) || ($statusFilter !== '' && !array_key_exists($statusFilter, $statusFilters))) {
    http_response_code(400);
    echo json_encode(['data' => [], 'message' => 'Invalid status filter.']);
    exit;
}

$conditions = [];
$params = [];
if (isset($_SESSION['user_dept']) && $_SESSION['user_dept'] === 'Project') {
    $conditions[] = 'o.user_code = ?';
    $params[] = $_SESSION['user_code'];
}
if ($statusFilter !== '') {
    $conditions[] = 'o.or_status = ?';
    $params[] = $statusFilters[$statusFilter];
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$stmt = $pdo->prepare("
    SELECT o.*, p.proj_name
    FROM tbl_or o
    LEFT JOIN tbl_project p ON p.proj_code = o.proj_code
    $where
    ORDER BY o.or_id DESC
");
$stmt->execute($params);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);





$data = [];

if ($rows && is_array($rows)) {

    foreach ($rows as $row) {

        // ✅ STATUS BADGE (Bootstrap 5 version)
        if ((int)$row['or_status'] === 0) {
            $status = '<span class="status-capsule status-warning">Pending</span>';
        } elseif ((int)$row['or_status'] === 1) {
            $status = '<span class="status-capsule status-success">Approved</span>';
        } elseif ((int)$row['or_status'] === 2) {
            $status = '<span class="status-capsule status-danger">Cancelled</span>';
        } elseif ((int)$row['or_status'] === 3) {
            $status = '<span class="status-capsule status-primary">Approved and Claimed</span>';
        } else {
            $status = '<span class="status-capsule status-secondary">Unknown</span>';
        }

        // ✅ SAFE DATE
        $date = !empty($row['or_date'])
            ? date("M d, Y", strtotime($row['or_date']))
            : '-';

        // ✅ SAFE OUTPUT
        $or_no = htmlspecialchars($row['or_no']);
        $dept  = htmlspecialchars($row['dept_code']);
        $proj  = htmlspecialchars($row['proj_code']);
        $projName = htmlspecialchars($row['proj_name'] ?: '-');
        $projectDisplay = $projName . '<br><small class="text-muted">' . $proj . '</small>';
        $prep  = htmlspecialchars($row['prepared_by']);

        // ✅ VIEW BUTTON INSIDE OR NO
        $or_display = $or_no . '
            <br>
            <a href="#" class="view-or" data-id="' . $row['or_id'] . '">
                <span class="badge bg-info">View</span>
            </a>
        ';

        // ✅ ACTION BUTTONS
        if ((int)$row['or_status'] === 1) {
            if (($_SESSION['user_type'] ?? '') === 'Manager') {
                $action = '<span class="text-muted">Ready for Claiming</span>';
            } else {
                $action = '
                    <a href="#" class="claim-or" data-id="' . $row['or_id'] . '">
                        <span class="badge bg-warning text-dark">Claim</span>
                    </a>
                ';
            }
        } elseif ((int)$row['or_status'] === 3) {
            $action = '<span class="status-capsule status-primary">Claimed</span>';
        } else {
            $action = '
                <a href="#" class="edit-or" data-id="' . $row['or_id'] . '">
                    <span class="badge bg-warning text-dark">Edit</span>
                </a>
                |
                <a href="#" class="delete-or" data-id="' . $row['or_id'] . '">
                    <span class="badge bg-danger">Delete</span>
                </a>
            ';
        }

        $data[] = [
            "or_no" => $or_display,
            "or_date" => $date,
            "dept_code" => $dept,
            "proj_name" => $projName,
            "proj_code" => $proj,
            "project_display" => $projectDisplay,
            "prepared_by" => $prep,
            "grand_total" => number_format((float)$row['grand_total'], 2),
            "status_badge" => $status,
            "action" => $action
        ];
    }
}

echo json_encode(["data" => $data]);
exit;
?>
