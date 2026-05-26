<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

if (($_SESSION['user_type'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo 'Unauthorized.';
    exit;
}

$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');

if ($startDate === '' || $endDate === '') {
    http_response_code(400);
    echo 'Please provide start and end dates.';
    exit;
}

function pdf_escape($text) {
    $text = str_replace(["\\", "(", ")", "\r", "\n"], ["\\\\", "\\(", "\\)", " ", " "], (string)$text);
    return $text;
}

function wrap_pdf_text($text, $length) {
    $text = trim((string)$text);
    if ($text === '') {
        return ['-'];
    }
    return explode("\n", wordwrap($text, $length, "\n", true));
}

function pdf_text($x, $y, $size, $text, $font = 'F1') {
    return "BT /{$font} {$size} Tf {$x} {$y} Td (" . pdf_escape($text) . ") Tj ET\n";
}

function build_pdf(array $pageStreams) {
    $objects = [];
    $pageObjectNumbers = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    foreach ($pageStreams as $stream) {
        $pageObjNo = count($objects) + 1;
        $contentObjNo = $pageObjNo + 1;
        $pageObjectNumbers[] = $pageObjNo;
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentObjNo} 0 R >>";
        $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
    }

    $kids = implode(' ', array_map(function($num) {
        return $num . ' 0 R';
    }, $pageObjectNumbers));
    $objects[1] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($pageObjectNumbers) . " >>";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $objNo = $index + 1;
        $pdf .= "{$objNo} 0 obj\n{$object}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

    return $pdf;
}

try {
    ensure_audit_logs_table($pdo);

    $stmt = $pdo->prepare("
        SELECT user_code, user_name, user_type, action, module, reference_no, description, created_at
        FROM tbl_audit_logs
        WHERE created_at BETWEEN ? AND ?
        ORDER BY created_at ASC, audit_id ASC
    ");
    $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    audit_log($pdo, 'DOWNLOAD_PDF', 'Audit Trail', $startDate . ' to ' . $endDate, 'Downloaded audit trail PDF report.');

    $pages = [];
    $page = '';
    $pageNo = 1;
    $y = 550;

    $newPage = function() use (&$page, &$y, &$pageNo, $startDate, $endDate) {
        $page = '';
        $y = 550;
        $page .= pdf_text(28, $y, 14, 'Audit Trail Logs');
        $page .= pdf_text(650, $y, 8, 'Page ' . $pageNo);
        $y -= 16;
        $page .= pdf_text(28, $y, 9, 'Date Range: ' . $startDate . ' to ' . $endDate);
        $y -= 18;
        $page .= pdf_text(28, $y, 8, 'Date/Time');
        $page .= pdf_text(125, $y, 8, 'User');
        $page .= pdf_text(250, $y, 8, 'Type');
        $page .= pdf_text(315, $y, 8, 'Action');
        $page .= pdf_text(405, $y, 8, 'Module');
        $page .= pdf_text(500, $y, 8, 'Reference');
        $page .= pdf_text(605, $y, 8, 'Description');
        $y -= 10;
        $page .= pdf_text(28, $y, 8, str_repeat('-', 156));
        $y -= 12;
    };

    $newPage();

    if (!$logs) {
        $page .= pdf_text(28, $y, 9, 'No audit logs found for this date range.');
    } else {
        foreach ($logs as $log) {
            $user = ($log['user_name'] ?: '-') . ' (' . ($log['user_code'] ?: '-') . ')';
            $descLines = wrap_pdf_text($log['description'] ?: '-', 42);
            $userLines = wrap_pdf_text($user, 24);
            $lineCount = max(count($descLines), count($userLines), 1);
            $rowHeight = ($lineCount * 10) + 8;

            if ($y - $rowHeight < 32) {
                $pages[] = $page;
                $pageNo++;
                $newPage();
            }

            for ($i = 0; $i < $lineCount; $i++) {
                $lineY = $y - ($i * 10);
                if ($i === 0) {
                    $page .= pdf_text(28, $lineY, 7, $log['created_at']);
                    $page .= pdf_text(250, $lineY, 7, $log['user_type'] ?: '-');
                    $page .= pdf_text(315, $lineY, 7, $log['action'] ?: '-');
                    $page .= pdf_text(405, $lineY, 7, $log['module'] ?: '-');
                    $page .= pdf_text(500, $lineY, 7, $log['reference_no'] ?: '-');
                }
                $page .= pdf_text(125, $lineY, 7, $userLines[$i] ?? '');
                $page .= pdf_text(605, $lineY, 7, $descLines[$i] ?? '');
            }

            $y -= $rowHeight;
        }
    }

    $pages[] = $page;
    $pdf = build_pdf($pages);
    $filename = 'audit_trail_' . $startDate . '_to_' . $endDate . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PDF download failed: ' . $e->getMessage();
}
?>
