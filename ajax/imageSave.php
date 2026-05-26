<?php
session_start();
include_once('config.php');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo 0;
    exit;
}

$file = $_FILES['file']['name'] ?? '';
$cat = trim((string)($_POST['gal_cat'] ?? ''));
$title = trim((string)($_POST['gal_title'] ?? ''));

$file_image = '';
if ($file !== '' && isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $infoExt = getimagesize($_FILES['file']['tmp_name']);
    $mime = strtolower($infoExt['mime'] ?? '');
    if ($mime === 'image/gif' || $mime === 'image/jpeg' || $mime === 'image/jpg' || $mime === 'image/png') {
        $file = preg_replace('/\\s+/', '-', time() . $file);
        $path = '../../assets/img/gallery/' . $file;
        move_uploaded_file($_FILES['file']['tmp_name'], $path);
        $data = array(
            'gal_name' => $file,
            'gal_title' => $title,
            'gal_cat' => $cat
        );
        $insert = $db->insert('tblgallery', $data);
        if ($insert) {
            echo 1;
        } else {
            echo 0;
        }
    } else {
        echo 2;
    }
}
?>
