<?php
/**
 * CleanAid - Server-Sent Events Cleaning Process (Optimized)
 * Streams JSON progress updates while executing Python batch cleaning.
 */

ini_set('max_execution_time', 0);
set_time_limit(0);
ignore_user_abort(true);

// ✅ Start session BEFORE headers
session_start();
require_once __DIR__ . '/../dB/config.php';

// --- SSE headers ---
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
ob_implicit_flush(true);
ob_end_flush();

// --- Error handling ---
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_exception_handler(function ($e) {
    echo "data: " . json_encode(["error" => $e->getMessage()]) . "\n\n";
    flush();
    exit;
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    echo "data: " . json_encode(["error" => "$errstr ($errfile:$errline)"]) . "\n\n";
    flush();
    exit;
});

// --- Configuration ---
$batchSize = 500;

// --- Fetch data from DB ---
$res = $conn->query("SELECT * FROM beneficiary ORDER BY beneficiary_id ASC");
if (!$res) {
    echo "data: " . json_encode(["error" => "Database query failed"]) . "\n\n";
    flush();
    exit;
}
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$totalRecords = count($rows);

if ($totalRecords === 0) {
    echo "data: " . json_encode(["error" => "No records found for cleaning"]) . "\n\n";
    flush();
    exit;
}

/**
 * Run Python cleaner by writing the batch to a temporary CSV
 */
function runPythonCleaner(array $records): array {
    $scriptPath = realpath(__DIR__ . '/../clean_data.py');
    if (!$scriptPath) return ["error" => "Python script not found"];

    // Write records to temp CSV
    $tmp = tempnam(sys_get_temp_dir(), 'cleanaid_');
    $csvPath = $tmp . '.csv';
    @rename($tmp, $csvPath);
    $fp = fopen($csvPath, 'w');
    if (!$fp) return ["error" => "Unable to create temp CSV"];

    $headers = [
        'Beneficiary ID','List ID','First Name','Middle Name','Last Name','Ext',
        'Birth Date','Region','Province','City','Barangay','Marital Status'
    ];
    fputcsv($fp, $headers);

    foreach ($records as $r) {
        fputcsv($fp, [
            $r['beneficiary_id'] ?? '',
            $r['list_id'] ?? '',
            $r['first_name'] ?? '',
            $r['middle_name'] ?? '',
            $r['last_name'] ?? '',
            $r['ext_name'] ?? '',
            $r['birth_date'] ?? '',
            $r['region'] ?? '',
            $r['province'] ?? '',
            $r['city'] ?? '',
            $r['barangay'] ?? '',
            $r['marital_status'] ?? '',
        ]);
    }
    fclose($fp);

    // Choose Python executable
    $python = stripos(PHP_OS, 'WIN') === 0 ? 'python' : 'python3';
    $cmd = escapeshellarg($python) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($csvPath);

    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        @unlink($csvPath);
        return ["error" => "Failed to start Python process"];
    }

    fclose($pipes[0]);
    $output = '';
    $start = time();
    while (!feof($pipes[1])) {
        $line = fgets($pipes[1]);
        if ($line === false) break;
        $output .= $line;

        if (time() - $start > 120) {
            proc_terminate($proc);
            fclose($pipes[1]);
            $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
            @unlink($csvPath);
            return ["error" => "Python process timeout"];
        }
    }
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    @unlink($csvPath);

    if ($exitCode !== 0) {
        return ["error" => trim($err ?: "Python exited with code $exitCode")];
    }

    $data = json_decode($output, true);
    if (!is_array($data)) {
        return ["error" => "Invalid JSON returned from Python"];
    }

    return $data;
}

// --- Stream cleaning progress ---
$flaggedAll = [];
$total = $totalRecords;

for ($offset = 0; $offset < $total; $offset += $batchSize) {
    $batch = array_slice($rows, $offset, $batchSize);
    $result = runPythonCleaner($batch);

    if (isset($result['error'])) {
        echo "data: " . json_encode(["error" => $result['error']]) . "\n\n";
        flush();
        exit;
    }

    // Python returns a flat array of flagged records
    $flaggedRows = array_values(array_filter($result, fn($r) => !empty($r['Reason'])));
    if (!empty($flaggedRows)) {
        $flaggedAll = array_merge($flaggedAll, $flaggedRows);
    }

    $percent = min(100, intval((($offset + $batchSize) / $total) * 100));
    echo "data: " . json_encode(["progress" => $percent, "message" => "Cleaning... {$percent}%"]) . "\n\n";
    flush();
    usleep(400000); // smooth updates
}

// --- Save flagged results ---
if (!empty($flaggedAll)) {
    $stmt = $conn->prepare("
        INSERT INTO duplicaterecord (beneficiary_id, flagged_reason, status)
        VALUES (?, ?, 'unresolved')
    ");
    foreach ($flaggedAll as $r) {
        $bid = (int)($r['Beneficiary ID'] ?? 0);
        $reason = (string)($r['Reason'] ?? 'Unspecified');
        $stmt->bind_param("is", $bid, $reason);
        $stmt->execute();
    }
    $stmt->close();
}

// --- Final event ---
echo "data: " . json_encode([
    "progress" => 100,
    "complete" => true,
    "message" => "✅ Cleaning complete!",
    "flagged_count" => count($flaggedAll),
    "total_records" => $totalRecords
]) . "\n\n";
flush();
exit;
?>
