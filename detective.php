<?php

/**
 * Advanced Site and Database Change Monitoring Script (PHPDetective)
 *
 * This script takes snapshot-based fingerprints (hashes) of files and database rows
 * to detect ADDED, MODIFIED, and DELETED items, including their content.
 * When a change is detected, it sends a detailed email notification using PHPMailer.
 */

// --- Error Reporting and Settings ---
error_reporting(E_ALL);
ini_set('display_errors', 1); // Set to 0 in production, 1 for CLI debugging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/detective_error.log');
set_time_limit(600); // 10 minutes

echo "Advanced site monitoring script starting...\n";

// --- Composer Autoloader ---
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die("ERROR: Composer 'autoload.php' not found. Please run 'composer install'.\n");
}
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\SMTP;

echo "PHPMailer loaded successfully.\n";

// --- Configuration ---
$config = [
    // Database configuration
    'db_host' => 'localhost',
    'db_name' => 'YOUR_DB_NAME',
    'db_user' => 'YOUR_DB_USER',
    'db_pass' => 'YOUR_DB_PASSWORD',
    'db_charset' => 'utf8mb4',

    // SMTP configuration
    'smtp_host' => 'smtp.example.com',
    'smtp_user' => 'your_email@example.com',
    'smtp_pass' => 'YOUR_SMTP_PASSWORD',
    'smtp_port' => 465,
    'smtp_secure' => PHPMailer::ENCRYPTION_SMTPS, // Use SMTPS (SSL) for port 465

    // Email settings
    'email_from' => 'scanner@example.com',
    'email_from_name' => 'Site Change Detective',
    'email_to' => 'your_alert_email@example.com',
    'email_subject' => 'Site Changes Detected (Detailed Report)',

    // Directory to monitor
    'monitor_dir' => '/path/to/your/website/htdocs'
];

// --- Exclusion Settings ---
$excluded_extensions = ['tmp', 'temp', 'log', 'bak', 'swp', 'lock', 'txt', 'xml', 'cache', 'sql', 'zip', 'rar', 'tar', 'gz', '7z'];
$excluded_directories = ['vendor', 'cache', 'tmp', 'temp', 'logs', 'backup', 'archives', '.git', '.idea', 'node_modules'];
$excluded_tables = ['logs', 'sessions', 'cache_table', 'transients'];

// --- State File Management ---
$state_file = __DIR__ . '/scan_state.json';
$previous_state = ['files' => [], 'database' => [], 'timestamp' => time() - 3600]; // Default to 1 hour ago
if (file_exists($state_file)) {
    $previous_state_content = file_get_contents($state_file);
    if ($previous_state_content) {
        $previous_state = json_decode($previous_state_content, true);
        echo "Previous state file loaded from: " . date('Y-m-d H:i:s', $previous_state['timestamp'] ?? time()) . "\n";
    }
} else {
    echo "Previous state file not found, initiating first scan.\n";
}

// --- Main Processing Block ---
$email_message_body = "";
$changes_detected = false;

try {
    // Database connection
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
    $mysqli->set_charset($config['db_charset']);
    echo "Database connection successful.\n";

    // --- Scan Current State ---
    $current_state = [
        'files' => getCurrentFileState($config['monitor_dir'], $excluded_extensions, $excluded_directories),
        'database' => getCurrentDatabaseState($mysqli, $excluded_tables),
        'timestamp' => time()
    ];

    // --- Compare States ---
    $file_changes = compareStates($previous_state['files'], $current_state['files']);
    $db_changes = compareStates($previous_state['database'], $current_state['database'], $mysqli);

    // --- Generate Report ---
    $report = "";
    $report .= generateReportSection("File System Changes", $file_changes);
    $report .= generateReportSection("Database Changes", $db_changes);

    if (!empty($report)) {
        $changes_detected = true;
        $email_message_body = "Site scan completed at " . date('Y-m-d H:i:s') . "\n";
        $email_message_body .= "Comparing against state from " . date('Y-m-d H:i:s', $previous_state['timestamp']) . "\n\n";
        $email_message_body .= "--- CHANGE SUMMARY ---\n" . $report;
    }

    // --- Notification and State Update ---
    if ($changes_detected) {
        sendNotification($config, $email_message_body);
    } else {
        echo "No changes detected.\n";
    }

    if (file_put_contents($state_file, json_encode($current_state, JSON_PRETTY_PRINT)) === false) {
        throw new Exception("Failed to save new state file: {$state_file}");
    }
    echo "Current state successfully saved to '{$state_file}'.\n";

    $mysqli->close();

} catch (Exception $e) {
    $error_message = "CRITICAL ERROR: " . $e->getMessage() . "\nLine: " . $e->getLine() . "\nFile: " . $e->getFile();
    echo $error_message;
    error_log($error_message);
    sendNotification($config, "Site Monitoring Script Failed!\n\n" . $error_message);
}

echo "Script execution finished.\n";


// --- Functions ---

function getCurrentFileState(string $directory, array $excluded_extensions, array $excluded_directories): array {
    echo "Scanning file states...\n";
    $state = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (isExcluded($path, $file->getBasename(), $file->getExtension(), $excluded_directories, $excluded_extensions)) {
            continue;
        }
        if ($file->isFile() && $file->isReadable()) {
            $state[$path] = md5_file($path);
        }
    }
    echo "File scan complete. Found " . count($state) . " files.\n";
    return $state;
}

function getCurrentDatabaseState(mysqli $mysqli, array $excluded_tables): array {
    echo "Scanning database states...\n";
    $state = [];
    $tables_result = $mysqli->query("SHOW TABLES");
    while ($table_row = $tables_result->fetch_row()) {
        $table_name = $table_row[0];
        if (in_array($table_name, $excluded_tables)) continue;

        $pk_result = $mysqli->query("SHOW KEYS FROM `{$table_name}` WHERE Key_name = 'PRIMARY'");
        $pk_col = $pk_result->fetch_assoc()['Column_name'] ?? null;
        if (!$pk_col) {
            echo "WARNING: Primary key not found for table '{$table_name}', skipping.\n";
            continue;
        }

        $state[$table_name] = ['pk_col' => $pk_col, 'rows' => []];
        $rows_result = $mysqli->query("SELECT * FROM `{$table_name}`");
        while ($row = $rows_result->fetch_assoc()) {
            $pk_val = $row[$pk_col];
            $state[$table_name]['rows'][$pk_val] = md5(json_encode($row));
        }
    }
    echo "Database scan complete.\n";
    return $state;
}

function fetchDbRow(mysqli $mysqli, string $table, string $pk_col, $pk_val): ?array {
    $stmt = $mysqli->prepare("SELECT * FROM `{$table}` WHERE `{$pk_col}` = ?");
    $stmt->bind_param('s', $pk_val);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

function compareStates(array $old_state, array $new_state, ?mysqli $mysqli = null): array {
    $changes = ['added' => [], 'modified' => [], 'deleted' => []];

    foreach ($new_state as $key => $value) {
        if (!isset($old_state[$key])) { // New item (file or table)
            if (is_array($value)) { // New table
                $pk_col = $value['pk_col'];
                foreach ($value['rows'] as $pk => $hash) {
                    $changes['added'][$key][$pk] = fetchDbRow($mysqli, $key, $pk_col, $pk);
                }
            } else { // New file
                $changes['added'][$key] = file_get_contents($key);
            }
            continue;
        }

        if (is_array($value)) { // Database table
            $pk_col = $value['pk_col'];
            foreach ($value['rows'] as $pk => $hash) {
                if (!isset($old_state[$key]['rows'][$pk])) { // New row
                    $changes['added'][$key][$pk] = fetchDbRow($mysqli, $key, $pk_col, $pk);
                } elseif ($old_state[$key]['rows'][$pk] !== $hash) { // Modified row
                    $changes['modified'][$key][$pk] = fetchDbRow($mysqli, $key, $pk_col, $pk);
                }
            }
        } else { // File
            if ($old_state[$key] !== $value) {
                $changes['modified'][$key] = file_get_contents($key);
            }
        }
    }

    foreach ($old_state as $key => $value) {
        if (!isset($new_state[$key])) {
            $changes['deleted'][] = "Item (File/Table): " . $key;
            continue;
        }
        if (is_array($value)) { // Database table
            foreach ($value['rows'] as $pk => $hash) {
                if (!isset($new_state[$key]['rows'][$pk])) {
                    $changes['deleted'][] = "Database Row: Table: {$key}, ID: {$pk}";
                }
            }
        }
    }
    return $changes;
}

function generateReportSection(string $title, array $changes): string {
    $report = "";
    $has_changes = !empty($changes['added']) || !empty($changes['modified']) || !empty($changes['deleted']);

    if (!$has_changes) return "";

    $report .= "--- {$title} ---\n";

    $formatContent = function ($content, $is_db = false) {
        $limit = 500;
        if ($is_db) {
            $formatted = "";
            foreach ($content as $col => $val) {
                $val_str = is_null($val) ? 'NULL' : (is_string($val) ? mb_substr($val, 0, 100) : $val);
                if (is_string($val) && mb_strlen($val) > 100) $val_str .= '...';
                $formatted .= "        - {$col}: {$val_str}\n";
            }
            return $formatted;
        }
        $truncated = mb_substr($content, 0, $limit);
        if (mb_strlen($content) > $limit) $truncated .= "\n    ... (content truncated)";
        return "    -- Content Start --\n" . $truncated . "\n    -- Content End --\n\n";
    };

    if (!empty($changes['added'])) {
        $report .= "  ✅ ADDED:\n";
        foreach ($changes['added'] as $key => $value) {
            if (is_array($value)) { // DB
                $report .= "    - Table: {$key}\n";
                foreach ($value as $pk => $row_content) {
                    $report .= "      - ID: {$pk}\n";
                    $report .= $formatContent($row_content, true);
                }
            } else { // File
                $report .= "    - File: {$key}\n";
                $report .= $formatContent($value);
            }
        }
    }
    if (!empty($changes['modified'])) {
        $report .= "  🔄 MODIFIED:\n";
        foreach ($changes['modified'] as $key => $value) {
            if (is_array($value)) { // DB
                $report .= "    - Table: {$key}\n";
                foreach ($value as $pk => $row_content) {
                    $report .= "      - ID: {$pk}\n";
                    $report .= $formatContent($row_content, true);
                }
            } else { // File
                $report .= "    - File: {$key}\n";
                $report .= $formatContent($value);
            }
        }
    }
    if (!empty($changes['deleted'])) {
        $report .= "  ❌ DELETED:\n" . "    - " . implode("\n    - ", $changes['deleted']) . "\n";
    }

    return $report . "\n";
}

function isExcluded(string $path, string $basename, string $extension, array $excluded_dirs, array $excluded_exts): bool {
    foreach ($excluded_dirs as $excluded_dir) {
        if (strpos($path, DIRECTORY_SEPARATOR . $excluded_dir . DIRECTORY_SEPARATOR) !== false || $basename === $excluded_dir) {
            return true;
        }
    }
    return in_array(strtolower($extension), $excluded_exts, true);
}

function sendNotification(array $config, string $body): void {
    echo "Sending email notification...\n";
    $mail = new PHPMailer(true);
    try {
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Enable for debugging
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_user'];
        $mail->Password   = $config['smtp_pass'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($config['email_from'], $config['email_from_name']);
        $mail->addAddress($config['email_to']);
        $mail->isHTML(false); // Send as plain text
        $mail->Subject = $config['email_subject'];
        $mail->Body    = $body;
        $mail->send();
        echo "Email sent successfully.\n";
    } catch (PHPMailerException $e) {
        echo "Email could not be sent. Error: {$mail->ErrorInfo}\n";
        error_log("PHPMailer Error: {$mail->ErrorInfo}");
    }
}
