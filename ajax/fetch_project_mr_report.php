<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode([
        'data' => [],
        'summary' => [
            'count' => 0,
            'grand_total' => '0.00'
        ],
        'message' => 'Session expired. Please log in again.'
    ]);
    exit;
}

$projCode = trim($_GET['proj_code'] ?? '');
$userCode = $_SESSION['user_code'];
$isAdmin = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin';

try {
    $where = ['o.or_status IN (1, 3)'];
    $params = [];

    if ($projCode !== '') {
        $where[] = 'o.proj_code = ?';
        $params[] = $projCode;
    }

    if (!$isAdmin) {
        $where[] = 'p.proj_mgr = ?';
        $params[] = $userCode;
    }

    $whereSql = implode(' AND ', $where);

    $summaryStmt = $pdo->prepare("
        SELECT COUNT(*) AS mr_count, COALESCE(SUM(o.grand_total), 0) AS grand_total
        FROM tbl_or o
        INNER JOIN tbl_project p ON p.proj_code = o.proj_code
        WHERE $whereSql
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    $rowsStmt = $pdo->prepare("
        SELECT
            o.or_no,
            o.or_date,
            o.proj_code,
            o.dept_code,
            o.prepared_by,
            o.remarks,
            o.grand_total
        FROM tbl_or o
        INNER JOIN tbl_project p ON p.proj_code = o.proj_code
        WHERE $whereSql
        ORDER BY o.or_date DESC, o.or_id DESC
    ");
    $rowsStmt->execute($params);

    $data = [];
    while ($row = $rowsStmt->fetch(PDO::FETCH_ASSOC)) {
        $data[] = [
            'or_no' => htmlspecialchars($row['or_no'] ?? ''),
            'or_date' => !empty($row['or_date']) ? date('M d, Y', strtotime($row['or_date'])) : '-',
            'proj_code' => htmlspecialchars($row['proj_code'] ?? ''),
            'dept_code' => htmlspecialchars($row['dept_code'] ?? ''),
            'prepared_by' => htmlspecialchars($row['prepared_by'] ?? ''),
            'remarks' => htmlspecialchars($row['remarks'] ?: '-'),
            'grand_total' => number_format((float)$row['grand_total'], 2)
        ];
    }

    echo json_encode([
        'data' => $data,
        'summary' => [
            'count' => (int)($summary['mr_count'] ?? 0),
            'grand_total' => number_format((float)($summary['grand_total'] ?? 0), 2)
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'data' => [],
        'summary' => [
            'count' => 0,
            'grand_total' => '0.00'
        ],
        'message' => "Request failed."
    ]);
}
