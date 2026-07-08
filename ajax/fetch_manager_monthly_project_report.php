<?php
session_start();
require_once('config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode([
        'data' => [],
        'summary' => ['total_project_cost' => '0.00'],
        'message' => 'Session expired. Please log in again.'
    ]);
    exit;
}

if (($_SESSION['user_type'] ?? '') !== 'Manager') {
    http_response_code(403);
    echo json_encode([
        'data' => [],
        'summary' => ['total_project_cost' => '0.00'],
        'message' => 'Manager access required.'
    ]);
    exit;
}

$userCode = $_SESSION['user_code'];
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));

if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}

if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-d', strtotime($startDate . ' +1 month'));

try {
    $columns = $pdo->query("SHOW COLUMNS FROM tbl_project")->fetchAll(PDO::FETCH_COLUMN);
    $hasApprovalColumn = in_array('proj_approval_status', $columns, true);

    $where = [
        'proj_mgr = ?',
        'MONTH(proj_sd) = ?',
        'YEAR(proj_sd) = ?'
    ];
    $params = [$userCode, $month, $year];

    if ($hasApprovalColumn) {
        $where[] = 'COALESCE(proj_approval_status, 1) = 1';
    }

    $whereSql = implode(' AND ', $where);

    $summaryStmt = $pdo->prepare("
        SELECT COALESCE(SUM(CAST(REPLACE(proj_cost, ',', '') AS DECIMAL(15,2))), 0) AS total_project_cost
        FROM tbl_project
        WHERE $whereSql
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_project_cost' => 0];

    $rowsStmt = $pdo->prepare("
        SELECT
            proj_code,
            proj_name,
            proj_owner,
            proj_cost,
            proj_sd,
            proj_ed,
            proj_status
        FROM tbl_project
        WHERE $whereSql
        ORDER BY proj_sd DESC, proj_code DESC
    ");
    $rowsStmt->execute($params);

    $data = [];
    while ($row = $rowsStmt->fetch(PDO::FETCH_ASSOC)) {
        $projectCost = (float)str_replace(',', '', (string)($row['proj_cost'] ?? 0));
        $data[] = [
            'proj_code' => htmlspecialchars((string)($row['proj_code'] ?? '')),
            'proj_name' => htmlspecialchars((string)($row['proj_name'] ?? '')),
            'proj_owner' => htmlspecialchars((string)($row['proj_owner'] ?? '')),
            'proj_status' => ((int)($row['proj_status'] ?? 0) === 1)
                ? '<span class="badge bg-success">Completed</span>'
                : '<span class="badge bg-warning">Ongoing</span>',
            'proj_sd' => !empty($row['proj_sd']) ? date('M d, Y', strtotime($row['proj_sd'])) : '-',
            'proj_ed' => !empty($row['proj_ed']) ? date('M d, Y', strtotime($row['proj_ed'])) : '-',
            'proj_cost_raw' => $projectCost,
            'proj_cost' => number_format($projectCost, 2)
        ];
    }

    echo json_encode([
        'data' => $data,
        'summary' => [
            'total_project_cost_raw' => (float)($summary['total_project_cost'] ?? 0),
            'total_project_cost' => number_format((float)($summary['total_project_cost'] ?? 0), 2)
        ],
        'filter' => [
            'month' => $month,
            'year' => $year,
            'start_date' => $startDate,
            'end_date' => date('Y-m-d', strtotime($endDate . ' -1 day'))
        ]
    ]);
} catch (Exception $e) {
    error_log('fetch_manager_monthly_project_report failed.');
    http_response_code(500);
    echo json_encode([
        'data' => [],
        'summary' => ['total_project_cost' => '0.00'],
        'message' => 'Request failed.'
    ]);
}
