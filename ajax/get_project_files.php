<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo "Unauthorized.";
    exit;
}

$proj_code = trim((string)($_POST['proj_code'] ?? ''));
if ($proj_code === '') {
    echo "No files uploaded.";
    exit;
}

$sql = "SELECT * FROM tbl_project_files WHERE proj_code = :proj_code ORDER BY uploaded_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([':proj_code' => $proj_code]);

$files = $stmt->fetchAll();

if (!$files) {
    echo "No files uploaded.";
    exit;
}

foreach ($files as $f) {

   $path = "../proj_files/" . $f['file_path'];

    echo "<div class='mb-2 border p-2'>";

    if ($f['file_type'] === 'image') {
        echo "<img src='$path' width='120' class='me-2'>";
    } else {
        echo "<i class='fa fa-file-pdf'></i> ";
    }

    echo "<a href='$path' target='_blank'>View File</a>";

    echo "</div>";
}
?>
