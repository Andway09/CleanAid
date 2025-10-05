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

    // CSRF check (double submit pattern)
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $csrfPost = $_POST['csrf_token'] ?? '';
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

    // Increase limits for large spreadsheets (tune to your infra)
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    include('../dB/config.php');

    // Composer autoloader is in project root/vendor (controller is one level below root)
    require_once __DIR__ . '/../vendor/autoload.php';

    // ===== Utilities =====
    function formatDate($dateStr) {
        if (!$dateStr) return null;
        $dateStr = trim((string)$dateStr);

        // d/m/Y
        $dt = DateTime::createFromFormat('d/m/Y', $dateStr);
        if ($dt && $dt->format('d/m/Y') === $dateStr) return $dt->format('Y-m-d');

        // m/d/Y
        $dt = DateTime::createFromFormat('m/d/Y', $dateStr);
        if ($dt && $dt->format('m/d/Y') === $dateStr) return $dt->format('Y-m-d');

        // Y-m-d
        $dt = DateTime::createFromFormat('Y-m-d', $dateStr);
        if ($dt && $dt->format('Y-m-d') === $dateStr) return $dt->format('Y-m-d');

        // Excel serial number
        if (is_numeric($dateStr)) {
            $origin = new DateTime('1899-12-30');
            $origin->modify('+' . (int)$dateStr . ' days');
            return $origin->format('Y-m-d');
        }

        // strtotime fallback
        $ts = strtotime($dateStr);
        if ($ts) return date('Y-m-d', $ts);

        return null;
    }

    function insertBeneficiary(mysqli $conn, int $listId, string $first_name, string $last_name, string $middle_name, string $ext_name, ?string $birth_date, string $region, string $province, string $city, string $barangay, string $marital) {
        static $stmt = null;
        if ($stmt === null) {
            $stmt = $conn->prepare("
                INSERT INTO beneficiary
                  (list_id, first_name, last_name, middle_name, ext_name, birth_date, region, province, city, barangay, marital_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                throw new RuntimeException('Prep beneficiary insert failed: ' . $conn->error);
            }
        }
        $stmt->bind_param(
            "issssssssss",
            $listId,
            $first_name, $last_name, $middle_name, $ext_name,
            $birth_date, $region, $province, $city, $barangay, $marital
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Beneficiary exec failed: ' . $stmt->error);
        }
    }

    // ===== Request data =====
    $files  = $_FILES['file'];
    $userId = (int)($_SESSION['user_id']);

    $_SESSION['uploaded_lists'] = [];
    $_SESSION['upload_errors']  = [];

    // ===== Allowed mime types (server-side) =====
    $allowedMimes = [
        'text/csv' => 'csv',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        // Some browsers may send CSV as generic text/plain
        'text/plain' => 'csv',
    ];
    $maxBytesPerFile = 50 * 1024 * 1024; // 50 MB

    // ===== Process files =====
    $count = is_array($files['name']) ? count($files['name']) : 0;

    for ($i = 0; $i < $count; $i++) {
        $filename  = basename((string)$files['name'][$i]);
        $tmpName   = $files['tmp_name'][$i] ?? '';
        $size      = (int)($files['size'][$i] ?? 0);
        $ext       = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        try {
            // Basic validations
            if (!$tmpName || !is_uploaded_file($tmpName)) {
                throw new RuntimeException("Invalid upload for $filename.");
            }
            if ($size <= 0 || $size > $maxBytesPerFile) {
                throw new RuntimeException("$filename: file too large or empty.");
            }
            if (!in_array($ext, ['csv','xls','xlsx'], true)) {
                throw new RuntimeException("$filename: unsupported extension '$ext'.");
            }

            // MIME validation
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmpName) ?: 'application/octet-stream';
            if (!array_key_exists($mime, $allowedMimes)) {
                throw new RuntimeException("$filename: invalid MIME type ($mime).");
            }

            // 1) Create beneficiarylist row
            $stmtList = $conn->prepare("
                INSERT INTO beneficiarylist (fileName, date_submitted, status, user_id)
                VALUES (?, NOW(), 'pending', ?)
            ");
            if (!$stmtList) throw new RuntimeException('Prep beneficiarylist failed: ' . $conn->error);
            $stmtList->bind_param("si", $filename, $userId);
            if (!$stmtList->execute()) throw new RuntimeException('Exec beneficiarylist failed: ' . $stmtList->error);
            $listId = (int)$conn->insert_id;
            $_SESSION['uploaded_lists'][] = $listId;

            // 2) Create processing record
            $stmtProc = $conn->prepare("
                INSERT INTO processing_engine (list_id, processing_date, status)
                VALUES (?, NOW(), 'in_progress')
            ");
            if (!$stmtProc) throw new RuntimeException('Prep processing_engine failed: ' . $conn->error);
            $stmtProc->bind_param("i", $listId);
            if (!$stmtProc->execute()) throw new RuntimeException('Exec processing_engine failed: ' . $stmtProc->error);
            $processingId = (int)$conn->insert_id;

            // 3) Parse and import inside a transaction for speed/consistency
            $conn->begin_transaction();

            try {
                if ($allowedMimes[$mime] === 'csv' || $ext === 'csv') {
                    // Parse CSV
                    $f = fopen($tmpName, 'r');
                    if (!$f) throw new RuntimeException("Cannot open CSV stream.");
                    $rowNum = 0;
                    while (($row = fgetcsv($f, 0, ",")) !== false) {
                        $rowNum++;
                        if ($rowNum === 1) continue; // header

                        try {
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
                        } catch (Throwable $rowErr) {
                            // Log row error but continue
                            error_log("Row error in $filename (line $rowNum): " . $rowErr->getMessage());
                            continue;
                        }
                    }
                    fclose($f);
                } else {
                    // XLS/XLSX via PhpSpreadsheet
                    $spreadsheet = IOFactory::load($tmpName);
                    $sheetData   = $spreadsheet->getActiveSheet()->toArray();

                    foreach ($sheetData as $idx => $row) {
                        if ($idx === 0) continue; // header row
                        try {
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
                        } catch (Throwable $rowErr) {
                            error_log("Row error in $filename (row $idx): " . $rowErr->getMessage());
                            continue;
                        }
                    }
                }

                $conn->commit();

                // 4) Mark processing complete
                $stmtDone = $conn->prepare("UPDATE processing_engine SET status='completed' WHERE processing_id=?");
                $stmtDone->bind_param("i", $processingId);
                $stmtDone->execute();

                // ✅ Also update beneficiarylist so dashboard reflects status
                $stmtListDone = $conn->prepare("UPDATE beneficiarylist SET status='processed' WHERE list_id=?");
                $stmtListDone->bind_param("i", $listId);
                $stmtListDone->execute();

            } catch (Throwable $e) {
                $conn->rollback();

                // Mark as failed and record error
                $stmtFail = $conn->prepare("UPDATE processing_engine SET status='failed' WHERE processing_id=?");
                $stmtFail->bind_param("i", $processingId);
                $stmtFail->execute();

                // ✅ Also mark beneficiarylist as failed
                $stmtListFail = $conn->prepare("UPDATE beneficiarylist SET status='failed' WHERE list_id=?");
                $stmtListFail->bind_param("i", $listId);
                $stmtListFail->execute();

                $_SESSION['upload_errors'][] = "⚠️ Failed to parse $filename: " . $e->getMessage();
            }

        } catch (Throwable $fileErr) {
            $_SESSION['upload_errors'][] = $fileErr->getMessage();
        } finally {
            // Clean temp file (if still present)
            if (!empty($tmpName) && is_file($tmpName)) {
                @unlink($tmpName);
            }
        }
    }

    // Finalize messages for clean.php
    if (!empty($_SESSION['upload_errors'])) {
        $_SESSION['error'] = implode("<br>", array_map('htmlspecialchars', $_SESSION['upload_errors']));
    }
    $_SESSION['success'] = "✅ Upload complete. Beneficiary list(s) created and records imported.";

    echo json_encode([
        'ok' => true,
        'redirect' => '../admin/clean.php'
    ]);
    exit;

} catch (Throwable $e) {
    // Top-level error (auth/CSRF/invalid request)
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
