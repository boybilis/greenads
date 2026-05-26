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

try {
    $userStmt = $pdo->prepare("SELECT user_type FROM tbl_user WHERE user_code = ? LIMIT 1");
    $userStmt->execute([$userCode]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit;
    }

    $defaultPassword = strtolower($user['user_type']);
    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE tbl_user SET pword = ? WHERE user_code = ?");
    $stmt->execute([$hash, $userCode]);

    if ($stmt->rowCount() > 0) {
        audit_log($pdo, 'RESET_PASSWORD', 'Users', $userCode, 'Reset password to default user type password.');
        echo json_encode([
            'status' => 'success',
            'message' => 'Password reset to default: ' . $defaultPassword
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Request failed."]);
}
?>
