<?php
include_once('config.php');

$file		=	$_FILES['file']['name'];
$cat	=	$_POST['gal_cat'];
$title	=	$_POST['gal_title'];

$file_image	=	'';
if($_FILES['file']['name']!=""){
    extract($_REQUEST);
	$infoExt        =   getimagesize($_FILES['file']['tmp_name']);
	if(strtolower($infoExt['mime']) == 'image/gif' || strtolower($infoExt['mime']) == 'image/jpeg' || strtolower($infoExt['mime']) == 'image/jpg' || strtolower($infoExt['mime']) == 'image/png'){
		$file	=	preg_replace('/\\s+/', '-', time().$file);
		$path   =   '../../assets/img/gallery/'.$file;
		move_uploaded_file($_FILES['file']['tmp_name'],$path);
		$data   =   array(
			'gal_name'=>$file,
			'gal_title'=>$title,
			'gal_cat'=>$cat
		);
		$insert     =   $db->insert('tblgallery',$data);
		if($insert){ echo 1; } else { echo 0; }
	}else{
		echo 2;
	}
}
?>
