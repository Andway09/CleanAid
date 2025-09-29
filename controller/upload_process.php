<?php
// controller/upload_process.php
session_start();
include('../dB/config.php');

// Composer autoloader is in project root/vendor (controller is one level below root)
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Normalize many common date formats to YYYY-MM-DD.
 */
function formatDate($dateStr) {
    if (!$dateStr) return null;
    $dateStr = trim($dateStr);

    // d/m/Y (e.g., 31/12/2024)
    $dt = DateTime::createFromFormat('d/m/Y', $dateStr);
    if ($dt && $dt->format('d/m/Y') === $dateStr) return $dt->format('Y-m-d');

    // m/d/Y (e.g., 12/31/2024)
    $dt = DateTime::createFromFormat('m/d/Y', $dateStr);
    if ($dt && $dt->format('m/d/Y') === $dateStr) return $dt->format('Y-m-d');

    // Y-m-d (already normalized)
    $dt = DateTime::createFromFormat('Y-m-d', $dateStr);
    if ($dt && $dt->format('Y-m-d') === $dateStr) return $dt->format('Y-m-d');

    // Excel serial date (numeric)
    if (is_numeric($dateStr)) {
        $origin = new DateTime('1899-12-30'); // Excel epoch
        $origin->modify("+" . intval($dateStr) . " days");
        return $origin->format('Y-m-d');
    }

    // Last attempt: strtotime
    $ts = strtotime($dateStr);
    if ($ts) return date('Y-m-d', $ts);

    return null;
}

/**
 * Insert a beneficiary row with prepared statements.
 */
function insertBeneficiary($conn, $listId, $first_name, $last_name, $middle_name, $ext_name, $birth_date, $region, $province, $city, $barangay, $marital) {
    $stmt = $conn->prepare("
        INSERT INTO beneficiary
        (list_id, first_name, last_name, middle_name, ext_name, birth_date, region, province, city, barangay, marital_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "issssssssss",
        $listId,
        $first_name, $last_name, $middle_name, $ext_name,
        $birth_date, $region, $province, $city, $barangay, $marital
    );
    $stmt->execute();
}

// -----------------------------------------------------------------------------
// Validate request & files
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    $_SESSION['error'] = "❌ No files uploaded.";
    header("Location: ../view/admin/clean.php");
    exit;
}

$files  = $_FILES['file'];
$userId = $_SESSION['user_id'] ?? 1; // adjust if your session uses a different key

$_SESSION['uploaded_lists'] = [];
$_SESSION['upload_errors']  = [];

// -----------------------------------------------------------------------------
// Process each uploaded file
// -----------------------------------------------------------------------------
for ($i = 0; $i < count($files['name']); $i++) {
    $filename  = basename($files['name'][$i]);
    $tmpName   = $files['tmp_name'][$i];
    $mime      = $files['type'][$i] ?? 'application/octet-stream';
    $size      = $files['size'][$i] ?? 0;
    $ext       = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // Basic validations
    if (!is_uploaded_file($tmpName)) {
        $_SESSION['upload_errors'][] = "❌ Invalid upload for $filename";
        continue;
    }
    if ($size <= 0 || $size > 50 * 1024 * 1024) { // 50 MB limit
        $_SESSION['upload_errors'][] = "❌ File too large or empty: $filename";
        continue;
    }
    if (!in_array($ext, ['csv', 'xls', 'xlsx'])) {
        $_SESSION['upload_errors'][] = "❌ Unsupported file type ($ext) for $filename";
        continue;
    }

    // 1) Create beneficiarylist row (we only track fileName, no uploads table)
    $stmtList = $conn->prepare("
        INSERT INTO beneficiarylist (fileName, date_submitted, status, user_id)
        VALUES (?, NOW(), 'pending', ?)
    ");
    $stmtList->bind_param("si", $filename, $userId);
    $stmtList->execute();
    $listId = $conn->insert_id;
    $_SESSION['uploaded_lists'][] = $listId;

    // 2) Create processing record
    $stmtProc = $conn->prepare("
        INSERT INTO processing_engine (list_id, processing_date, status)
        VALUES (?, NOW(), 'in_progress')
    ");
    $stmtProc->bind_param("i", $listId);
    $stmtProc->execute();
    $processingId = $conn->insert_id;

    // 3) Parse directly from the temporary uploaded file path
    try {
        if ($ext === 'csv') {
            $f = fopen($tmpName, 'r');
            if (!$f) {
                throw new RuntimeException("Cannot open CSV stream.");
            }
            $rowNum = 0;
            while (($row = fgetcsv($f, 0, ",")) !== false) {
                $rowNum++;
                if ($rowNum === 1) continue; // skip header

                // Map columns (adjust indices if your file layout differs)
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
            // XLS/XLSX via PhpSpreadsheet
            $spreadsheet = IOFactory::load($tmpName);
            $sheetData   = $spreadsheet->getActiveSheet()->toArray();

            foreach ($sheetData as $idx => $row) {
                if ($idx === 0) continue; // header row

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

        // 4) Mark processing complete
        $stmtDone = $conn->prepare("UPDATE processing_engine SET status='completed' WHERE processing_id=?");
        $stmtDone->bind_param("i", $processingId);
        $stmtDone->execute();
    } catch (Throwable $e) {
        // Mark as failed and record error
        $stmtFail = $conn->prepare("UPDATE processing_engine SET status='failed' WHERE processing_id=?");
        $stmtFail->bind_param("i", $processingId);
        $stmtFail->execute();

        $_SESSION['upload_errors'][] = "⚠️ Failed to parse $filename: " . $e->getMessage();
    }
}

// Final redirect
if (!empty($_SESSION['upload_errors'])) {
    $_SESSION['error'] = implode("<br>", $_SESSION['upload_errors']);
}
$_SESSION['success'] = "✅ Upload complete. Beneficiary list(s) created and records imported.";
header("Location: ../view/admin/clean.php");
exit;
