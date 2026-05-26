<?php
session_start();
require_once('config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_code'])) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized.'
    ]);
    exit;
}

if (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Manager'], true)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Only Admin or Manager can create projects.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    try {
        $columns = $pdo->query("SHOW COLUMNS FROM tbl_project")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('proj_approval_status', $columns, true)) {
            $pdo->exec("ALTER TABLE tbl_project ADD proj_approval_status TINYINT(1) NOT NULL DEFAULT 1");
        }

        $creatorType = $_SESSION['user_type'] ?? '';
        $approvalStatus = $creatorType === 'Manager' ? 0 : 1;
        $projectManagerCode = trim((string)($_POST['proj_mgr'] ?? ''));
        if ($creatorType === 'Manager') {
            $projectManagerCode = $_SESSION['user_code'];
        }

        if ($projectManagerCode === '') {
            throw new Exception('Project manager is required.');
        }

        // =========================
        // AUTO-GENERATE PROJECT CODE (JAN2026-001)
        // =========================
        $month = strtoupper(date('M'));
        $year = date('Y');
        $prefix = $month . $year;

        $likePrefix = $prefix . '-%';

        $stmt = $db->getPdo()->prepare("
            SELECT proj_code 
            FROM tbl_project 
            WHERE proj_code LIKE ?
            ORDER BY proj_code DESC 
            LIMIT 1
        ");

        $stmt->execute([$likePrefix]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $last_number = (int) substr($row['proj_code'], -3);
            $next_number = $last_number + 1;
        } else {
            $next_number = 1;
        }

        $proj_code = $prefix . '-' . str_pad($next_number, 3, '0', STR_PAD_LEFT);
		$public_token = bin2hex(random_bytes(16));

        // =========================
        // INSERT PROJECT (NO FILES)
        // =========================
        $data = [
            'proj_code'   => $proj_code,
            'proj_mgr'    => $projectManagerCode,
            'proj_name'   => $_POST['proj_name'],
            'proj_owner'  => $_POST['proj_owner'],
            'proj_cost'   => $_POST['proj_cost'],
            'proj_desc'   => $_POST['proj_desc'],
            'proj_sd'     => $_POST['proj_sd'],
            'proj_ed'     => $_POST['proj_ed'],
            'proj_status' => $_POST['proj_status'],
            'proj_approval_status' => $approvalStatus,
			'public_token' => $public_token
        ];

        $db->insert('tbl_project', $data);

        echo json_encode([
            'status' => 'success',
            'message' => $approvalStatus === 0
                ? 'Project created and submitted for Admin approval.'
                : 'Project saved successfully.',
            'proj_code' => $proj_code
        ]);

    } catch (Exception $e) {

        echo json_encode([
            'status' => 'error',
            'message' => "Request failed."
        ]);
    }
}
