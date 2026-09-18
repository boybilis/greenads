<?php
session_start();
require_once('config.php');
require_once('audit_helper.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}

$userType = $_SESSION['user_type'] ?? '';
if (!in_array($userType, ['Admin', 'Manager'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Admin or the assigned Project Manager can complete a project.']);
    exit;
}

$projectCode = trim((string)($_POST['proj_code'] ?? ''));
if ($projectCode === '') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid project reference.']);
    exit;
}

try {
    $columns = $pdo->query("SHOW COLUMNS FROM tbl_project")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('proj_approval_status', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_project ADD proj_approval_status TINYINT(1) NOT NULL DEFAULT 1");
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT proj_code, proj_name, proj_mgr, proj_status, COALESCE(proj_approval_status, 1) AS proj_approval_status
        FROM tbl_project
        WHERE proj_code = ?
        FOR UPDATE
    ");
    $stmt->execute([$projectCode]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        throw new RuntimeException('Project not found.');
    }
    if ($userType === 'Manager' && ($project['proj_mgr'] ?? '') !== ($_SESSION['user_code'] ?? '')) {
        throw new RuntimeException('You can only complete projects assigned to you.');
    }
    if ((int)($project['proj_approval_status'] ?? 1) !== 1) {
        throw new RuntimeException('The project must be approved by Admin before it can be completed.');
    }
    if ((int)($project['proj_status'] ?? 0) === 1) {
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Project is already completed.']);
        exit;
    }

    $update = $pdo->prepare("UPDATE tbl_project SET proj_status = 1 WHERE proj_code = ?");
    $update->execute([$projectCode]);

    $pdo->commit();

    audit_log($pdo, 'COMPLETE', 'Project', $projectCode, 'proj_status: "Ongoing" -> "Completed"');
    echo json_encode(['status' => 'success', 'message' => 'Project marked as completed.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to complete project.';
    http_response_code($e instanceof RuntimeException ? 400 : 500);
    echo json_encode(['status' => 'error', 'message' => $message]);
}
