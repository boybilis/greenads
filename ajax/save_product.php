<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

$userType = $_SESSION['user_type'] ?? '';
$canManagePricing = in_array($userType, ['Admin', 'Purchasing'], true);
$isPurchasingOnly = ($userType === 'Purchasing');

$pid=$_POST['id'];
$sku=trim($_POST['sku'] ?? '');

function sku_part($value, $length = 3) {
	$value = preg_replace('/[^a-zA-Z0-9]/', '', (string)$value);
	return strtoupper(substr($value, 0, $length));
}

function color_code($color) {
	$standardColors = [
		'black' => ['single' => 'BLK', 'combo' => 'BL'],
		'white' => ['single' => 'WHT', 'combo' => 'WH'],
		'red' => ['single' => 'RED', 'combo' => 'RD'],
		'green' => ['single' => 'GRN', 'combo' => 'GR'],
		'blue' => ['single' => 'BLU', 'combo' => 'BL'],
		'yellow' => ['single' => 'YEL', 'combo' => 'YL'],
		'orange' => ['single' => 'ORG', 'combo' => 'OR'],
		'purple' => ['single' => 'PUR', 'combo' => 'PR'],
		'pink' => ['single' => 'PNK', 'combo' => 'PK'],
		'brown' => ['single' => 'BRN', 'combo' => 'BR'],
		'gray' => ['single' => 'GRY', 'combo' => 'GY'],
		'grey' => ['single' => 'GRY', 'combo' => 'GY'],
		'navy' => ['single' => 'NVY', 'combo' => 'NV'],
		'beige' => ['single' => 'BGE', 'combo' => 'BG'],
		'cream' => ['single' => 'CRM', 'combo' => 'CR'],
		'gold' => ['single' => 'GLD', 'combo' => 'GD'],
		'silver' => ['single' => 'SLV', 'combo' => 'SL'],
		'maroon' => ['single' => 'MRN', 'combo' => 'MR'],
		'denim' => ['single' => 'DNM', 'combo' => 'DN']
	];

	$colorWords = preg_split('/[\s\/,-]+/', strtolower(trim((string)$color)));
	$colorWords = array_values(array_filter($colorWords, function($word) {
		return $word !== '';
	}));

	if (count($colorWords) <= 1) {
		$word = $colorWords[0] ?? '';
		return $standardColors[$word]['single'] ?? sku_part($word, 3);
	}

	$code = '';
	foreach (array_slice($colorWords, 0, 2) as $word) {
		$code .= $standardColors[$word]['combo'] ?? sku_part($word, 2);
	}
	return $code;
}

function generate_sku($materialName, $color, $gsm) {
	$words = preg_split('/\s+/', trim((string)$materialName));
	$materialCode = sku_part($words[0] ?? '', 3);
	if (isset($words[1]) && $words[1] !== '') {
		$materialCode .= sku_part($words[1], 1);
	}

	$colorCode = color_code($color);
	$skuParts = array_filter([$materialCode, $colorCode]);
	$sku = implode('-', $skuParts);

	$gsm = trim((string)$gsm);
	if ($gsm !== '' && $gsm !== '0') {
		$sku .= '-' . preg_replace('/[^a-zA-Z0-9]/', '', $gsm);
	}

	return $sku;
}

if($pid!=""){

		$beforeStmt = $pdo->prepare("SELECT sku, material_name, material_type, category, color, gsm, description, quantity, unit, unit_price, location, reorder_level FROM tbl_items WHERE id = ? LIMIT 1");
		$beforeStmt->execute([$pid]);
		$before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

		$set=array();
		
		foreach($_POST as $key => $value) {
	//if($value!=''){
		$set += [$key => $value];
	//}
}

        if (!$canManagePricing) {
            $set['unit_price'] = $before['unit_price'] ?? 0;
        }
        if ($isPurchasingOnly) {
            $set['quantity'] = $before['quantity'] ?? 0;
        }

		$where   =   array(	
			'id'=>$pid
			);
			
		$upd    =   $db->update('tbl_items',$set,$where);
		if($upd){
			$after = [
				'sku' => $_POST['sku'] ?? '',
				'material_name' => $_POST['material_name'] ?? '',
				'material_type' => $_POST['material_type'] ?? '',
				'category' => $_POST['category'] ?? '',
				'color' => $_POST['color'] ?? '',
				'gsm' => $_POST['gsm'] ?? '',
				'description' => $_POST['description'] ?? '',
				'quantity' => $set['quantity'] ?? ($_POST['quantity'] ?? ''),
				'unit' => $_POST['unit'] ?? '',
				'unit_price' => $set['unit_price'] ?? ($_POST['unit_price'] ?? ''),
				'location' => $_POST['location'] ?? '',
				'reorder_level' => $_POST['reorder_level'] ?? ''
			];
			audit_log($pdo, 'UPDATE', 'Items', $sku, audit_changed_fields($before, $after, ['sku', 'material_name', 'material_type', 'category', 'color', 'gsm', 'description', 'quantity', 'unit', 'unit_price', 'location', 'reorder_level']));
			echo 1;
		} else { echo 0; }
	
}else{
	
	if($userType === 'Purchasing'){
		echo 0;
		exit;
	}

	if($sku==""){
		$sku = generate_sku($_POST['material_name'] ?? '', $_POST['color'] ?? '', $_POST['gsm'] ?? '');
		$_POST['sku'] = $sku;
	}

	if($sku!=""){

		$data=array();

			foreach($_POST as $key => $value) {
	if($value!=''){
		$data += [$key => $value];
 
	}
}

        if (!$canManagePricing) {
            $data['unit_price'] = 0;
        }
        if ($isPurchasingOnly) {
            $data['quantity'] = 0;
        }
		$insert     =   $db->insert('tbl_items',$data);
		if($insert){ audit_log($pdo, 'CREATE', 'Items', $sku, 'Added item ' . ($_POST['material_name'] ?? '') . '.'); echo 1; } else { echo 0; }
	}else{
		echo 2;
	}	 
 }
 
 
 
?>





