<?php
// controller/upload_process.php
declare(strict_types=1);
session_start();

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

try {
    // ===== Basic guards =====
    if (!isset($_SESSION['user_id'])) {
        throw new RuntimeException('Unauthorized. Please sign in.');
    }

    // CSRF check
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

    // Increase limits for large spreadsheets
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    include('../dB/config.php');
    require_once __DIR__ . '/../vendor/autoload.php';

    // ===== Utilities =====
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

    function insertBeneficiary(mysqli $conn, int $listId, string $first_name, string $last_name, string $middle_name, string $ext_name, ?string $birth_date, string $region, string $province, string $city, string $barangay, string $marital)
    {
        static $stmt = null;
        if ($stmt === null) {
            $stmt = $conn->prepare("
                INSERT INTO beneficiary
                  (list_id, first_name, last_name, middle_name, ext_name, birth_date, region, province, city, barangay, marital_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) throw new RuntimeException('Prep beneficiary insert failed: ' . $conn->error);
        }
        $stmt->bind_param(
            "issssssssss",
            $listId,
            $first_name,
            $last_name,
            $middle_name,
            $ext_name,
            $birth_date,
            $region,
            $province,
            $city,
            $barangay,
            $marital
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Beneficiary exec failed: ' . $stmt->error);
        }
    }

    // ===== Setup =====
    $files  = $_FILES['file'];
    $userId = (int)($_SESSION['user_id']);

    // 🧹 FULL RESET — clear old session data before any upload batch
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
    $maxBytesPerFile = 50 * 1024 * 1024;
    $count           = is_array($files['name']) ? count($files['name']) : 0;
    $upload_dir      = sys_get_temp_dir();

    // Log folder
    $log_dir = __DIR__ . '/../logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0777, true);
    }

    for ($i = 0; $i < $count; $i++) {
        $filename = basename((string)$files['name'][$i]);
        $tmpName  = $files['tmp_name'][$i] ?? '';
        $size     = (int)($files['size'][$i] ?? 0);
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        try {
            // === Validation ===
            if (!$tmpName || !is_uploaded_file($tmpName)) {
                throw new RuntimeException("Invalid upload for $filename.");
            }
            if ($size <= 0 || $size > $maxBytesPerFile) {
                throw new RuntimeException("$filename: file too large or empty.");
            }
            if (!in_array($ext, ['csv', 'xls', 'xlsx'], true)) {
                throw new RuntimeException("$filename: unsupported extension '$ext'.");
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($tmpName) ?: 'application/octet-stream';
            if (!array_key_exists($mime, $allowedMimes)) {
                throw new RuntimeException("$filename: invalid MIME type ($mime).");
            }

            // 1️⃣ Create beneficiarylist record
            $stmtList = $conn->prepare("
                INSERT INTO beneficiarylist (fileName, date_submitted, status, user_id)
                VALUES (?, NOW(), 'pending', ?)
            ");
            $stmtList->bind_param("si", $filename, $userId);
            $stmtList->execute();
            $listId = (int)$conn->insert_id;

            // ✅ Append this list ID (do not replace)
            $_SESSION['uploaded_lists'][] = $listId;

            // 2️⃣ Create processing record
            $stmtProc = $conn->prepare("
                INSERT INTO processing_engine (list_id, processing_date, status)
                VALUES (?, NOW(), 'in_progress')
            ");
            $stmtProc->bind_param("i", $listId);
            $stmtProc->execute();
            $processingId = (int)$conn->insert_id;

            // 3️⃣ Parse and import data
            $conn->begin_transaction();
            try {
                if ($allowedMimes[$mime] === 'csv' || $ext === 'csv') {
                    $f = fopen($tmpName, 'r');
                    if (!$f) throw new RuntimeException("Cannot open CSV stream.");
                    $rowNum = 0;
                    while (($row = fgetcsv($f, 0, ",")) !== false) {
                        $rowNum++;
                        if ($rowNum === 1) continue;
                        $first_name  = trim($row[2] ?? '');
                        $last_name   = trim($row[3] ?? '');
                        $middle_name = trim($row[4] ?? '');
                        $ext_name    = trim($row[5] ?? '');
                        $birth_date  = formatDate($row[6] ?? '');
                        $region      = trim($row[7] ?? '');
                        $province    = trim($row[8] ?? '');
                        $city        = trim($row[9] ?? '');
                        $barangay    = trim($row[10] ?? '');
                        $marital     = trim($row[11] ?? '');
                        insertBeneficiary($conn, $listId, $first_name, $last_name, $middle_name, $ext_name, $birth_date, $region, $province, $city, $barangay, $marital);
                    }
                    fclose($f);
                } else {
                    $spreadsheet = IOFactory::load($tmpName);
                    $sheetData   = $spreadsheet->getActiveSheet()->toArray();
                    foreach ($sheetData as $idx => $row) {
                        if ($idx === 0) continue;
                        $first_name  = trim($row[2] ?? '');
                        $last_name   = trim($row[3] ?? '');
                        $middle_name = trim($row[4] ?? '');
                        $ext_name    = trim($row[5] ?? '');
                        $birth_date  = formatDate($row[6] ?? '');
                        $region      = trim($row[7] ?? '');
                        $province    = trim($row[8] ?? '');
                        $city        = trim($row[9] ?? '');
                        $barangay    = trim($row[10] ?? '');
                        $marital     = trim($row[11] ?? '');
                        insertBeneficiary($conn, $listId, $first_name, $last_name, $middle_name, $ext_name, $birth_date, $region, $province, $city, $barangay, $marital);
                    }
                }
                $conn->commit();

                // 4️⃣ Convert CSV/XLSX → Parquet
                $python = stripos(PHP_OS, 'WIN') === 0 ? 'python' : 'python3';
                $converter = realpath(__DIR__ . '/../scripts/convert_to_parquet.py');
                $target_path = $upload_dir . '/' . uniqid('upl_', true) . '_' . $filename;
                copy($files['tmp_name'][$i], $target_path);

                if ($converter && file_exists($target_path)) {
                    $cmd = "$python " . escapeshellarg($converter) . " " . escapeshellarg($target_path);
                    file_put_contents($log_dir . '/parquet_debug.log', "Running: $cmd\n", FILE_APPEND);
                    exec($cmd . ' 2>&1', $out, $ret);
                    file_put_contents($log_dir . '/parquet_debug.log', implode("\n", $out) . "\nReturn code: $ret\n\n", FILE_APPEND);

                    if ($ret === 0) {
                        $parquetPath = pathinfo($target_path, PATHINFO_DIRNAME) . '/' . pathinfo($target_path, PATHINFO_FILENAME) . '.parquet';
                        if (file_exists($parquetPath)) {
                            $parquetData = file_get_contents($parquetPath);
                            $parquetFileName = pathinfo($filename, PATHINFO_FILENAME) . '.parquet';

                            $stmtParq = $conn->prepare("
                                INSERT INTO parquet_files (list_id, file_name, file_data)
                                VALUES (?, ?, ?)
                            ");
                            if ($stmtParq) {
                                $null = NULL;
                                $stmtParq->bind_param("isb", $listId, $parquetFileName, $null);
                                $stmtParq->send_long_data(2, $parquetData);
                                $stmtParq->execute();
                            }
                            // ✅ Append parquet filenames too
                            $_SESSION['uploaded_parquet_files'][] = $parquetFileName;
                            @unlink($parquetPath);
                            @unlink($target_path);
                        }
                    }
                }

                // ✅ Update statuses
                $stmtDone = $conn->prepare("UPDATE processing_engine SET status='completed' WHERE processing_id=?");
                $stmtDone->bind_param("i", $processingId);
                $stmtDone->execute();

                $stmtListDone = $conn->prepare("UPDATE beneficiarylist SET status='processed' WHERE list_id=?");
                $stmtListDone->bind_param("i", $listId);
                $stmtListDone->execute();

            } catch (Throwable $e) {
                $conn->rollback();
                $_SESSION['upload_errors'][] = $e->getMessage();
            }
        } catch (Throwable $fileErr) {
            $_SESSION['upload_errors'][] = $fileErr->getMessage();
        }
    }

    if (!empty($_SESSION['upload_errors'])) {
        $_SESSION['error'] = implode("<br>", array_map('htmlspecialchars', $_SESSION['upload_errors']));
    }

    $_SESSION['success'] = "✅ Upload complete. Parquet file(s) created and saved in database.";
    echo json_encode(['ok' => true, 'redirect' => '../admin/clean.php']);
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
?>
