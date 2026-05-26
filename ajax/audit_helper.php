<?php
function ensure_audit_logs_table(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_audit_logs (
            audit_id INT AUTO_INCREMENT PRIMARY KEY,
            user_code VARCHAR(100) DEFAULT NULL,
            user_name VARCHAR(150) DEFAULT NULL,
            user_type VARCHAR(50) DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            module VARCHAR(100) NOT NULL,
            reference_no VARCHAR(150) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            ip_address VARCHAR(100) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_user_code (user_code),
            INDEX idx_audit_action (action),
            INDEX idx_audit_module (module),
            INDEX idx_audit_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function audit_log(PDO $pdo, $action, $module, $referenceNo = null, $description = null) {
    try {
        ensure_audit_logs_table($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO tbl_audit_logs
                (user_code, user_name, user_type, action, module, reference_no, description, ip_address, user_agent)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_code'] ?? null,
            $_SESSION['username'] ?? null,
            $_SESSION['user_type'] ?? null,
            $action,
            $module,
            $referenceNo,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        // Audit logging must never block the main transaction.
    }
}

function audit_changed_fields(array $before, array $after, array $fields) {
    $changes = [];

    foreach ($fields as $field) {
        $oldValue = array_key_exists($field, $before) ? (string)($before[$field] ?? '') : '';
        $newValue = array_key_exists($field, $after) ? (string)($after[$field] ?? '') : '';

        if ($oldValue !== $newValue) {
            $changes[] = $field . ': "' . $oldValue . '" -> "' . $newValue . '"';
        }
    }

    return $changes ? implode('; ', $changes) : 'No field values changed.';
}
?>
