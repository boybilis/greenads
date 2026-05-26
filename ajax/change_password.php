<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please log in again.']);
    exit;
}

$currentPassword = trim($_POST['current_password'] ?? '');
$newPassword = trim($_POST['new_password'] ?? '');
$confirmPassword = trim($_POST['confirm_password'] ?? '');
$userCode = $_SESSION['user_code'];

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    echo json_encode(['status' => 'error', 'message' => 'Please complete all password fields.']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['status' => 'error', 'message' => 'New password and confirm password do not match.']);
    exit;
}

if (strlen($newPassword) < 6) {
    echo json_encode(['status' => 'error', 'message' => 'New password must be at least 6 characters.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT pword FROM tbl_user WHERE user_code = ? LIMIT 1");
    $stmt->execute([$userCode]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($currentPassword, $user['pword'])) {
        echo json_encode(['status' => 'error', 'message' => 'Existing password is incorrect.']);
        exit;
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $pdo->prepare("UPDATE tbl_user SET pword = ? WHERE user_code = ?");
    $update->execute([$hash, $userCode]);
    audit_log($pdo, 'CHANGE_PASSWORD', 'Users', $userCode, 'Changed own password.');

    echo json_encode(['status' => 'success', 'message' => 'Password changed successfully.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Request failed."]);
}
?>
