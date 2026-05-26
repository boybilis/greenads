<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

if (($_SESSION['user_type'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Admin can approve projects.']);
    exit;
}

$projCode = trim((string)($_POST['proj_code'] ?? ''));
if ($projCode === '') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid project code.']);
    exit;
}

try {
    $columns = $pdo->query("SHOW COLUMNS FROM tbl_project")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('proj_approval_status', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_project ADD proj_approval_status TINYINT(1) NOT NULL DEFAULT 1");
    }

    $stmt = $pdo->prepare("UPDATE tbl_project SET proj_approval_status = 1 WHERE proj_code = ? LIMIT 1");
    $stmt->execute([$projCode]);

    if ($stmt->rowCount() < 1) {
        echo json_encode(['status' => 'error', 'message' => 'Project not found or already approved.']);
        exit;
    }

    echo json_encode(['status' => 'success', 'message' => 'Project approved successfully.']);
} catch (Exception $e) {
    error_log('approve_project failed.');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Request failed.']);
}
?>
