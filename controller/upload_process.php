<?php
declare(strict_types=1);
session_start();

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new RuntimeException('Unauthorized. Please sign in.');
    }

    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $csrfPost   = $_POST['csrf_token'] ?? '';
    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $csrfHeader) ||
        !hash_equals($_SESSION['csrf_token'], $csrfPost)
    ) {
        throw new RuntimeException('Invalid CSRF token.');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
        throw new RuntimeException('No files uploaded.');
    }

    @set_time_limit(600);
    @ini_set('memory_limit', '1G');

    include('../dB/config.php');
    require_once __DIR__ . '/../vendor/autoload.php';

    // 🧩 Utility function
    function formatDate($dateStr)
    {
        if (!$dateStr) return null;
        $dateStr = trim((string)$dateStr);
        $formats = ['d/m/Y', 'm/d/Y', 'Y-m-d'];
        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $dateStr);
            if ($dt && $dt->format($fmt) === $dateStr) return $dt->format('Y-m-d');
        }
        if (is_numeric($dateStr)) {
            $origin = new DateTime('1899-12-30');
            $origin->modify('+' . (int)$dateStr . ' days');
            return $origin->format('Y-m-d');
        }
        $ts = strtotime($dateStr);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    function insertBeneficiary(mysqli $conn, int $listId, array $data)
    {
        static $stmt = null;
        if ($stmt === null) {
            $stmt = $conn->prepare("
                INSERT INTO beneficiary
                    (list_id, first_name, last_name, middle_name, ext_name, birth_date, region, province, city, barangay, marital_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) throw new RuntimeException('Failed to prepare beneficiary insert: ' . $conn->error);
        }
        $stmt->bind_param(
            "issssssssss",
            $listId,
            $data['first_name'],
            $data['last_name'],
            $data['middle_name'],
            $data['ext_name'],
            $data['birth_date'],
            $data['region'],
            $data['province'],
            $data['city'],
            $data['barangay'],
            $data['marital_status']
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Beneficiary insert failed: ' . $stmt->error);
        }
    }

    // 🧩 Setup
    $files  = $_FILES['file'];
    $userId = (int)$_SESSION['user_id'];

    unset($_SESSION['uploaded_lists'], $_SESSION['uploaded_parquet_files'], $_SESSION['upload_errors']);
    $_SESSION['uploaded_lists'] = [];
    $_SESSION['uploaded_parquet_files'] = [];
    $_SESSION['upload_errors'] = [];

    $allowedMimes = [
        'text/csv' => 'csv',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/plain' => 'csv',
    ];

    $upload_dir = sys_get_temp_dir();
    $log_dir = __DIR__ . '/../logs';
    if (!file_exists($log_dir)) mkdir($log_dir, 0777, true);

    $count = is_array($files['name']) ? count($files['name']) : 0;

    // === Create consolidated list record ===
    $combinedFileName = 'consolidated_' . date('Ymd_His') . '.parquet';
    $stmtList = $conn->prepare("
        INSERT INTO beneficiarylist (fileName, date_submitted, status, user_id)
        VALUES (?, NOW(), 'pending', ?)
    ");
    $stmtList->bind_param("si", $combinedFileName, $userId);
    $stmtList->execute();
    $listId = (int)$conn->insert_id;
    $_SESSION['uploaded_lists'] = [$listId];

    // === Create processing record ===
    $stmtProc = $conn->prepare("
        INSERT INTO processing_engine (list_id, processing_date, status)
        VALUES (?, NOW(), 'in_progress')
    ");
    $stmtProc->bind_param("i", $listId);
    $stmtProc->execute();
    $processingId = (int)$conn->insert_id;

    // === Combine into one CSV ===
    $combinedCsv = $upload_dir . '/combined_' . uniqid() . '.csv';
    $csvHandle = fopen($combinedCsv, 'w');
    if (!$csvHandle) throw new RuntimeException("Cannot open combined CSV for writing.");
    fputcsv($csvHandle, [
        'first_name', 'last_name', 'middle_name', 'ext_name',
        'birth_date', 'region', 'province', 'city', 'barangay', 'marital_status'
    ]);

    for ($i = 0; $i < $count; $i++) {
        $tmpName = $files['tmp_name'][$i];
        $filename = basename((string)$files['name'][$i]);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!is_uploaded_file($tmpName)) continue;

        if ($ext === 'csv') {
            $f = fopen($tmpName, 'r');
            $rowNum = 0;
            while (($row = fgetcsv($f, 0, ",")) !== false) {
                $rowNum++;
                if ($rowNum === 1) continue;
                $data = [
                    'first_name'      => trim($row[2] ?? ''),
                    'last_name'       => trim($row[3] ?? ''),
                    'middle_name'     => trim($row[4] ?? ''),
                    'ext_name'        => trim($row[5] ?? ''),
                    'birth_date'      => formatDate($row[6] ?? ''),
                    'region'          => trim($row[7] ?? ''),
                    'province'        => trim($row[8] ?? ''),
                    'city'            => trim($row[9] ?? ''),
                    'barangay'        => trim($row[10] ?? ''),
                    'marital_status'  => trim($row[11] ?? '')
                ];
                insertBeneficiary($conn, $listId, $data);
                fputcsv($csvHandle, array_values($data));
            }
            fclose($f);
        } else {
            $spreadsheet = IOFactory::load($tmpName);
            $sheetData = $spreadsheet->getActiveSheet()->toArray();
            foreach ($sheetData as $idx => $row) {
                if ($idx === 0) continue;
                $data = [
                    'first_name'      => trim($row[2] ?? ''),
                    'last_name'       => trim($row[3] ?? ''),
                    'middle_name'     => trim($row[4] ?? ''),
                    'ext_name'        => trim($row[5] ?? ''),
                    'birth_date'      => formatDate($row[6] ?? ''),
                    'region'          => trim($row[7] ?? ''),
                    'province'        => trim($row[8] ?? ''),
                    'city'            => trim($row[9] ?? ''),
                    'barangay'        => trim($row[10] ?? ''),
                    'marital_status'  => trim($row[11] ?? '')
                ];
                insertBeneficiary($conn, $listId, $data);
                fputcsv($csvHandle, array_values($data));
            }
        }
    }
    fclose($csvHandle);

    // ===== CONVERT TO PARQUET =====
    $python = '"' . realpath(__DIR__ . '/../.venv/Scripts/python.exe') . '"';

    $converter = realpath(__DIR__ . '/../scripts/convert_to_parquet.py');
    chdir(dirname($converter));

    file_put_contents($log_dir . '/debug_path.log',
        "Converter path: $converter\nWorking dir: " . getcwd() . "\nCombined CSV: $combinedCsv\n", FILE_APPEND
    );

    if (!$converter || !file_exists($converter)) {
        throw new RuntimeException("Converter script not found at: $converter");
    }

    $cmd = "$python " . escapeshellarg($converter) . " " . escapeshellarg($combinedCsv);
    exec($cmd . ' 2>&1', $out, $ret);
    file_put_contents($log_dir . '/parquet_debug.log', implode("\n", $out) . "\nReturn code: $ret\n\n", FILE_APPEND);

    if ($ret === 0) {
        $parquetPath = pathinfo($combinedCsv, PATHINFO_DIRNAME) . '/combined_output.parquet';
        if (file_exists($parquetPath)) {
            $parquetData = file_get_contents($parquetPath);
            $stmtParq = $conn->prepare("INSERT INTO parquet_files (list_id, file_name, file_data) VALUES (?, ?, ?)");
            $null = NULL;
            $stmtParq->bind_param("isb", $listId, $combinedFileName, $null);
            $stmtParq->send_long_data(2, $parquetData);
            $stmtParq->execute();
            $_SESSION['uploaded_parquet_files'][] = $combinedFileName;
            @unlink($parquetPath);
        }
    }

    @unlink($combinedCsv);

    $conn->query("UPDATE processing_engine SET status='completed' WHERE processing_id=$processingId");
    $conn->query("UPDATE beneficiarylist SET status='processed' WHERE list_id=$listId");

    $_SESSION['success'] = "✅ Upload complete. Beneficiaries inserted and all files merged into one Parquet file.";
    echo json_encode(['ok' => true, 'redirect' => '../admin/clean.php']);
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
?>
