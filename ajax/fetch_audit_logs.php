<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

header('Content-Type: application/json');

if (($_SESSION['user_type'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['data' => [], 'message' => 'Unauthorized.']);
    exit;
}

try {
    ensure_audit_logs_table($pdo);

    $stmt = $pdo->query("
        SELECT audit_id, user_code, user_name, user_type, action, module, reference_no, description, created_at
        FROM tbl_audit_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY audit_id DESC
        LIMIT 500
    ");

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data[] = [
            'created_at' => htmlspecialchars($row['created_at']),
            'user' => htmlspecialchars(($row['user_name'] ?: '-') . ' (' . ($row['user_code'] ?: '-') . ')'),
            'user_type' => htmlspecialchars($row['user_type'] ?: '-'),
            'action' => htmlspecialchars($row['action']),
            'module' => htmlspecialchars($row['module']),
            'reference_no' => htmlspecialchars($row['reference_no'] ?: '-'),
            'description' => htmlspecialchars($row['description'] ?: '-')
        ];
    }

    echo json_encode(['data' => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['data' => [], 'message' => "Request failed."]);
}
?>
