<?php
session_start();
include_once('config.php');
include_once('audit_helper.php');

if (($_SESSION['user_type'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo 'Unauthorized.';
    exit;
}

function sql_identifier($name) {
    return '`' . str_replace('`', '``', $name) . '`';
}

function sql_value(PDO $pdo, $value) {
    if ($value === null) {
        return 'NULL';
    }
    return $pdo->quote((string)$value);
}

try {
    $filename = DB_NAME . '_backup_' . date('Ymd_His') . '.sql';
    audit_log($pdo, 'BACKUP', 'Database', $filename, 'Downloaded database backup file: ' . $filename);

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "-- Database backup\n";
    echo "-- Database: " . DB_NAME . "\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "SET time_zone = \"+00:00\";\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = $pdo->query("SHOW FULL TABLES")->fetchAll(PDO::FETCH_NUM);

    foreach ($tables as $tableRow) {
        $table = $tableRow[0];
        $tableType = $tableRow[1] ?? '';

        if (strtoupper($tableType) !== 'BASE TABLE') {
            continue;
        }

        $tableName = sql_identifier($table);

        echo "-- --------------------------------------------------------\n";
        echo "-- Table structure for table {$tableName}\n\n";
        echo "DROP TABLE IF EXISTS {$tableName};\n";

        $createStmt = $pdo->query("SHOW CREATE TABLE {$tableName}");
        $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
        echo ($createRow['Create Table'] ?? '') . ";\n\n";

        $rowsStmt = $pdo->query("SELECT * FROM {$tableName}");
        $firstRow = true;

        while ($row = $rowsStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($firstRow) {
                echo "-- Data for table {$tableName}\n";
                $firstRow = false;
            }

            $columns = array_map('sql_identifier', array_keys($row));
            $values = array_map(function($value) use ($pdo) {
                return sql_value($pdo, $value);
            }, array_values($row));

            echo "INSERT INTO {$tableName} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
        }

        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
} catch (Exception $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Backup failed: ' . "Request failed.";
}
?>
