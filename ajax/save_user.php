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
$userName = trim($_POST['user_name'] ?? '');
$userType = trim($_POST['user_type'] ?? '');
$userDept = trim($_POST['user_dept'] ?? '');
$password = trim($_POST['pword'] ?? '');
$mode = trim($_POST['mode'] ?? 'edit');

if ($userCode === '' || $userName === '' || $userType === '' || $userDept === '') {
    echo json_encode(['status' => 'error', 'message' => 'Please complete all required fields.']);
    exit;
}

try {
    if ($mode === 'add') {
        if ($password === '') {
            echo json_encode(['status' => 'error', 'message' => 'Please enter a password.']);
            exit;
        }

        $dup = $pdo->prepare("SELECT COUNT(*) FROM tbl_user WHERE user_code = ?");
        $dup->execute([$userCode]);
        if ((int)$dup->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => 'User code already exists.']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO tbl_user (user_code, pword, user_type, user_name, user_dept)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userCode, $hash, $userType, $userName, $userDept]);
        audit_log($pdo, 'CREATE', 'Users', $userCode, 'Added user ' . $userName . ' as ' . $userType . '.');

        echo json_encode(['status' => 'success', 'message' => 'User added successfully.']);
        exit;
    }

    $beforeStmt = $pdo->prepare("SELECT user_name, user_type, user_dept FROM tbl_user WHERE user_code = ? LIMIT 1");
    $beforeStmt->execute([$userCode]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("
        UPDATE tbl_user
        SET user_name = ?, user_type = ?, user_dept = ?
        WHERE user_code = ?
    ");
    $stmt->execute([$userName, $userType, $userDept, $userCode]);
    $after = [
        'user_name' => $userName,
        'user_type' => $userType,
        'user_dept' => $userDept
    ];
    audit_log($pdo, 'UPDATE', 'Users', $userCode, audit_changed_fields($before, $after, ['user_name', 'user_type', 'user_dept']));

    echo json_encode(['status' => 'success', 'message' => 'User updated successfully.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Request failed."]);
}
?>
