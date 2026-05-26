<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

header('Content-Type: application/json');

if (($_SESSION['user_type'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$userCode = trim($_POST['user_code'] ?? '');

if ($userCode === '') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid user.']);
    exit;
}

if ($userCode === ($_SESSION['user_code'] ?? '')) {
    echo json_encode(['status' => 'error', 'message' => 'You cannot delete your own account.']);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM tbl_user WHERE user_code = ?");
    $stmt->execute([$userCode]);

    if ($stmt->rowCount() > 0) {
        audit_log($pdo, 'DELETE', 'Users', $userCode, 'Deleted user account.');
        echo json_encode(['status' => 'success', 'message' => 'User deleted successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Request failed."]);
}
?>
