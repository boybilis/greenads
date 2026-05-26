<?php
session_start();
include_once('config.php');

header('Content-Type: application/json');

if (($_SESSION['user_type'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['data' => [], 'message' => 'Unauthorized.']);
    exit;
}

try {
    $stmt = $pdo->query("
        SELECT user_code, user_name, user_type, user_dept
        FROM tbl_user
        ORDER BY user_name ASC, user_code ASC
    ");

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $userCode = htmlspecialchars($row['user_code']);
        $row['user_code_display'] = $userCode;
        $row['user_name_display'] = htmlspecialchars($row['user_name']);
        $row['user_type_display'] = htmlspecialchars($row['user_type']);
        $row['user_dept_display'] = htmlspecialchars($row['user_dept']);
        $row['action'] = '
            <a href="#" class="edit-user" data-code="' . $userCode . '">
                <span class="badge badge-warning">Edit</span>
            </a>
            |
            <a href="#" class="reset-user-password" data-code="' . $userCode . '">
                <span class="badge badge-info">Reset Password</span>
            </a>
            |
            <a href="#" class="delete-user" data-code="' . $userCode . '">
                <span class="badge badge-danger">Delete</span>
            </a>
        ';
        $data[] = $row;
    }

    echo json_encode(['data' => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['data' => [], 'message' => "Request failed."]);
}
?>
