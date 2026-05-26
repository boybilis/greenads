<?php
session_start();
include_once('config.php');
header('Content-Type: application/json');

$userType = $_SESSION['user_type'] ?? '';
$userCode = $_SESSION['user_code'] ?? '';

if ($userType === 'Manager') {
    $stmt = $pdo->prepare("
        SELECT *
        FROM item_requests
        WHERE requested_by = ?
        ORDER BY id DESC
    ");
    $stmt->execute([$userCode]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $rows = $db->getAllRecords('item_requests');
}

$data = [];

if ($rows && is_array($rows)) {
    foreach ($rows as $row) {


		$status=$row['status'];
		
		if($status=='Pending'){
			$status='<span class="badge bg-warning text-dark">Pending</span>';
		}elseif($status=='Ordered'){
			$status='<span class="badge bg-info">Ordered</span>';
		}

       $desc = !empty($row['description']) ? htmlspecialchars($row['description']) : '-';
$description = "Color: " . htmlspecialchars($row['item_color']) . "<br>" . $desc;
		
		

        $data[] = [
            $row['item_name'],
            $description,
            $status
        ];
    }
}

echo json_encode(["data" => $data]);
exit;
