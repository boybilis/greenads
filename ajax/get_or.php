<?php
session_start();
include_once('config.php');
header('Content-Type: application/json');
if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode(['data' => [], 'message' => 'Unauthorized.']);
    exit;
}


$or_id = $_POST['or_id'];
//$or_id=1;

// ✔ use dynamic get method
$or = $db->getTblOrByOrId($or_id);
if (!$or || !isset($or[0])) {
    echo json_encode([
        "status" => "error",
        "message" => "Material Request not found."
    ]);
    exit;
}

$projectStmt = $pdo->prepare("SELECT proj_name FROM tbl_project WHERE proj_code = ? LIMIT 1");
$projectStmt->execute([$or[0]['proj_code'] ?? '']);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC);
$or[0]['proj_name'] = $project['proj_name'] ?? '';

// ✔ get items
$items = $db->getTblOrItemsByOrId($or_id);

echo json_encode([
    "status" => "success",
    "data" => $or[0],
    "items" => $items
]);
?>
