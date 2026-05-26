<?php
session_start();
include_once('ajax/config.php');
include_once('ajax/audit_helper.php');

header('Content-Type: application/json');

$user_code = trim($_POST['user_code'] ?? '');
$pword     = trim($_POST['pword'] ?? '');

if ($user_code === '' || $pword === '') {
    echo json_encode([
        "status" => "error",
        "message" => "Please enter user code and password."
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT user_code, pword, user_type, user_name, user_dept
        FROM tbl_user
        WHERE user_code = ?
        LIMIT 1
    ");
    $stmt->execute([$user_code]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($pword, $user['pword'])) {

        $_SESSION['user_code'] = $user['user_code'];
        $_SESSION['username']  = $user['user_name'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['user_dept'] = $user['user_dept'];

        audit_log($pdo, 'LOGIN', 'Auth', $user['user_code'], 'User logged in.');

        echo json_encode([
            "status" => "success",
            "message" => "Login successful.",
            "redirect" => "dashboard"
        ]);
        exit;

    }
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]);
    exit;
}

echo json_encode([
    "status" => "error",
    "message" => "Invalid user code or password."
]);
exit;
?>
