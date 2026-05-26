<?php
session_start();
require_once('config.php');


header('Content-Type: application/json');

try {

    $user = $_SESSION['user_code'] ?? '';
    $type = $_SESSION['user_type'] ?? '';

    $sql = "
        SELECT p.*,
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

    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
    exit;
}