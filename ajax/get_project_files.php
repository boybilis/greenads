<?php
require_once 'config.php';

$proj_code = $_POST['proj_code'];

$sql = "SELECT * FROM tbl_project_files WHERE proj_code = :proj_code ORDER BY uploaded_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([':proj_code' => $proj_code]);

$files = $stmt->fetchAll();

if (!$files) {
    echo "No files uploaded.";
    exit;
}

foreach ($files as $f) {

   $path ="../ga_p/proj_files/" . $f['file_path'];

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