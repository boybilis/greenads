<?php
session_start();
require_once('config.php');


header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized."
    ]);
    exit;
}

try {
    $columns = $pdo->query("SHOW COLUMNS FROM tbl_project")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('proj_approval_status', $columns, true)) {
        $pdo->exec("ALTER TABLE tbl_project ADD proj_approval_status TINYINT(1) NOT NULL DEFAULT 1");
    }

    $user = $_SESSION['user_code'] ?? '';
    $type = $_SESSION['user_type'] ?? '';

    $sql = "
        SELECT p.*,
               COALESCE(p.proj_approval_status, 1) AS proj_approval_status,
               COUNT(f.id) AS file_count
        FROM tbl_project p
        LEFT JOIN tbl_project_files f 
            ON p.proj_code = f.proj_code
    ";

    $params = [];

    // =========================
    // ROLE FILTER
    // =========================
    if ($type !== 'Admin') {
        $sql .= " WHERE p.proj_mgr = ? ";
        $params[] = $user;
    }

    $sql .= " GROUP BY p.proj_code ORDER BY p.proj_code DESC";

    $stmt = $db->getPdo()->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($rows);
    exit;

} catch (Exception $e) {
    error_log('fetch_projects failed.');

    echo json_encode([
        "status" => "error",
        "message" => "Request failed."
    ]);
    exit;
}
