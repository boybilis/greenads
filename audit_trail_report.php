<?php
session_start();
include_once('ajax/config.php');
include_once('ajax/audit_helper.php');

if (!isset($_SESSION['user_code']) || ($_SESSION['user_type'] ?? '') !== 'Admin') {
    die('Unauthorized.');
}

$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');

if ($startDate === '' || $endDate === '') {
    die('Please provide start and end dates.');
}

ensure_audit_logs_table($pdo);

$stmt = $pdo->prepare("
    SELECT user_code, user_name, user_type, action, module, reference_no, description, created_at
    FROM tbl_audit_logs
    WHERE created_at BETWEEN ? AND ?
    ORDER BY created_at ASC, audit_id ASC
");
$stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

audit_log($pdo, 'VIEW_REPORT', 'Audit Trail', $startDate . ' to ' . $endDate, 'Viewed printable audit trail report.');
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Audit Trail Logs</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; color: #111; font-size: 10px; }
        .toolbar { padding: 10px; background: #f4f4f4; border-bottom: 1px solid #ddd; }
        .toolbar button { padding: 7px 14px; cursor: pointer; }
        .sheet { padding: 10mm; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        .meta { margin-bottom: 12px; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #333; padding: 5px; vertical-align: top; }
        th { background: #f2f2f2; text-align: left; }
        .nowrap { white-space: nowrap; }
        @media print {
            .toolbar { display: none; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .sheet { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </div>
    <div class="sheet">
        <h1>Audit Trail Logs</h1>
        <div class="meta">
            Date Range: <?= htmlspecialchars($startDate); ?> to <?= htmlspecialchars($endDate); ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="nowrap">Date/Time</th>
                    <th>User</th>
                    <th>Type</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Reference</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$logs) { ?>
                    <tr>
                        <td colspan="7" style="text-align:center;">No audit logs found for this date range.</td>
                    </tr>
                <?php } else { ?>
                    <?php foreach ($logs as $log) { ?>
                        <tr>
                            <td class="nowrap"><?= htmlspecialchars($log['created_at']); ?></td>
                            <td><?= htmlspecialchars(($log['user_name'] ?: '-') . ' (' . ($log['user_code'] ?: '-') . ')'); ?></td>
                            <td><?= htmlspecialchars($log['user_type'] ?: '-'); ?></td>
                            <td><?= htmlspecialchars($log['action']); ?></td>
                            <td><?= htmlspecialchars($log['module']); ?></td>
                            <td><?= htmlspecialchars($log['reference_no'] ?: '-'); ?></td>
                            <td><?= htmlspecialchars($log['description'] ?: '-'); ?></td>
                        </tr>
                    <?php } ?>
                <?php } ?>
            </tbody>
        </table>
    </div>
</body>
</html>
