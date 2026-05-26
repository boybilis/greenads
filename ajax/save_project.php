<?php
session_start();
require_once('config.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    try {

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
            'proj_mgr'    => $_POST['proj_mgr'],
            'proj_name'   => $_POST['proj_name'],
            'proj_owner'  => $_POST['proj_owner'],
            'proj_cost'   => $_POST['proj_cost'],
            'proj_desc'   => $_POST['proj_desc'],
            'proj_sd'     => $_POST['proj_sd'],
            'proj_ed'     => $_POST['proj_ed'],
            'proj_status' => $_POST['proj_status'],
			'public_token' => $public_token
        ];

        $db->insert('tbl_project', $data);

        echo json_encode([
            'status' => 'success',
            'message' => 'Project saved successfully',
            'proj_code' => $proj_code
        ]);

    } catch (Exception $e) {

        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}