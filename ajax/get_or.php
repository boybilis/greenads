<?php
session_start();
include_once('config.php');
header('Content-Type: application/json');

$or_id = $_POST['or_id'];
//$or_id=1;

// ✔ use dynamic get method
$or = $db->getTblOrByOrId($or_id);

// ✔ get items
$items = $db->getTblOrItemsByOrId($or_id);

echo json_encode([
    "status" => "success",
    "data" => $or[0],
    "items" => $items
]);
?>