<?php
session_start();
require_once('config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['data' => [], 'summary' => [], 'message' => 'Unauthorized.']);
    exit;
}

if (($_SESSION['user_type'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['data' => [], 'summary' => [], 'message' => 'Admin access required.']);
    exit;
}

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
    $stmt = $pdo->prepare("
        SELECT
            u.user_code,
            u.user_name,
            COALESCE(ps.ongoing_projects, 0) AS ongoing_projects,
            COALESCE(ps.finished_projects, 0) AS finished_projects,
            COALESCE(ps.total_projects, 0) AS total_projects,
            COALESCE(ps.total_project_cost, 0) AS total_project_cost,
            COALESCE(ms.claimed_mr_amount, 0) AS claimed_mr_amount
        FROM tbl_user u
        LEFT JOIN (
            SELECT
                proj_mgr,
                SUM(CASE WHEN proj_status = 0 THEN 1 ELSE 0 END) AS ongoing_projects,
                SUM(CASE WHEN proj_status = 1 THEN 1 ELSE 0 END) AS finished_projects,
                COUNT(proj_code) AS total_projects,
                COALESCE(SUM(CAST(REPLACE(proj_cost, ',', '') AS DECIMAL(15,2))), 0) AS total_project_cost
            FROM tbl_project
            WHERE proj_sd >= ? AND proj_sd < ?
            GROUP BY proj_mgr
        ) ps ON ps.proj_mgr = u.user_code
        LEFT JOIN (
            SELECT
                p.proj_mgr,
                COALESCE(SUM(CAST(REPLACE(o.grand_total, ',', '') AS DECIMAL(15,2))), 0) AS claimed_mr_amount
            FROM tbl_or o
            INNER JOIN tbl_project p ON p.proj_code = o.proj_code
            WHERE o.or_date >= ? AND o.or_date < ? AND o.or_status = 3
            GROUP BY p.proj_mgr
        ) ms ON ms.proj_mgr = u.user_code
        WHERE u.user_type = 'Manager'
        ORDER BY u.user_name ASC, u.user_code ASC
    ");
    $stmt->execute([$startDate, $endDate, $startDate, $endDate]);

    $rows = [];
    $summary = [
        'ongoing_projects' => 0,
        'finished_projects' => 0,
        'total_projects' => 0,
        'total_project_cost_raw' => 0.0,
        'total_project_cost' => '0.00',
        'claimed_mr_amount_raw' => 0.0,
        'claimed_mr_amount' => '0.00'
    ];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ongoing = (int)$row['ongoing_projects'];
        $finished = (int)$row['finished_projects'];
        $totalProjects = (int)$row['total_projects'];
        $projectCost = (float)$row['total_project_cost'];
        $claimedMrAmount = (float)$row['claimed_mr_amount'];

        $summary['ongoing_projects'] += $ongoing;
        $summary['finished_projects'] += $finished;
        $summary['total_projects'] += $totalProjects;
        $summary['total_project_cost_raw'] += $projectCost;
        $summary['claimed_mr_amount_raw'] += $claimedMrAmount;

        $rows[] = [
            'manager_code' => htmlspecialchars((string)$row['user_code']),
            'manager_name' => htmlspecialchars((string)$row['user_name']),
            'ongoing_projects' => $ongoing,
            'finished_projects' => $finished,
            'total_projects' => $totalProjects,
            'total_project_cost_raw' => $projectCost,
            'total_project_cost' => number_format($projectCost, 2),
            'claimed_mr_amount_raw' => $claimedMrAmount,
            'claimed_mr_amount' => number_format($claimedMrAmount, 2)
        ];
    }

    $summary['total_project_cost'] = number_format((float)$summary['total_project_cost_raw'], 2);
    $summary['claimed_mr_amount'] = number_format((float)$summary['claimed_mr_amount_raw'], 2);

    echo json_encode([
        'data' => $rows,
        'summary' => $summary,
        'filter' => [
            'month' => $month,
            'year' => $year,
            'start_date' => $startDate,
            'end_date' => date('Y-m-d', strtotime($endDate . ' -1 day'))
        ]
    ]);
} catch (Exception $e) {
    error_log('fetch_admin_manager_project_report failed.');
    http_response_code(500);
    echo json_encode(['data' => [], 'summary' => [], 'message' => 'Request failed.']);
}
