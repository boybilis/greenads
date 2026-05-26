<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_POST['proj_code']) || !isset($_FILES['file'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing required data"
    ]);
    exit;
}

$proj_code = $_POST['proj_code'];
$file = $_FILES['file'];

$allowed = ['jpg','jpeg','png','pdf'];

$originalName = $file['name'];
$tmp = $file['tmp_name'];
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if (!in_array($ext, $allowed)) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid file type"
    ]);
    exit;
}

$fileType = ($ext === 'pdf') ? 'pdf' : 'image';

// safe filename
$newFileName = uniqid('proj_', true) . "." . $ext;

// folder
$uploadDir = "../proj_files/";
$fullPath = $uploadDir . $newFileName;

if (move_uploaded_file($tmp, $fullPath)) {

    $data = [
        'proj_code' => $proj_code,
        'file_name' => $originalName,
        'file_path' => $newFileName,
        'file_type' => $fileType
    ];

    try {
        $db->insert('tbl_project_files', $data);

        echo json_encode([
            "status" => "success",
            "message" => "File uploaded successfully",
            "proj_code" => $proj_code
        ]);

    } catch (Exception $e) {
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }

} else {
    echo json_encode([
        "status" => "error",
        "message" => "File upload failed"
    ]);
}
?>