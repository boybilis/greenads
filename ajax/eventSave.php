<?php
include_once('config.php');

$file		=	$_FILES['file']['name'];
$desc	=	$_POST['ev_desc'];
$title	=	$_POST['ev_title'];
$st	=	$_POST['ev_duration_start'];
$end	=	$_POST['ev_duration_end'];


$file_image	=	'';
if($_FILES['file']['name']!=""){
    extract($_REQUEST);
	$infoExt        =   getimagesize($_FILES['file']['tmp_name']);
	if(strtolower($infoExt['mime']) == 'image/gif' || strtolower($infoExt['mime']) == 'image/jpeg' || strtolower($infoExt['mime']) == 'image/jpg' || strtolower($infoExt['mime']) == 'image/png'){
		$file	=	preg_replace('/\\s+/', '-', time().$file);
		$path   =   '../../assets/img/events/'.$file;
		move_uploaded_file($_FILES['file']['tmp_name'],$path);
		$data   =   array(
			'ev_cover'=>$file,
			'ev_desc'=>$desc,
			'ev_title'=>$title,
			'ev_duration_start'=>$st,
			'ev_duration_end'=>$end
		);
		$insert     =   $db->insert('tblevents',$data);
		if($insert){ echo 1; } else { echo 0; }
	}else{
		echo 2;
	}
}
?>
