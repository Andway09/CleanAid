<?php
session_start();
require_once __DIR__ . '/../../dB/config.php';

// ✅ Auth guard
if (!isset($_SESSION['user_id'])) {
  header('Location: ../../login.php');
  exit;
}

// ✅ CSRF token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* -----------------------------------------------------------------------
   1) Collect list IDs (from POST or Session)
------------------------------------------------------------------------ */
if (!empty($_POST['lists']) && is_array($_POST['lists'])) {
    $_SESSION['uploaded_lists'] = array_values(
        array_filter($_POST['lists'], fn($v) => ctype_digit((string)$v))
    );
}
$lists = $_SESSION['uploaded_lists'] ?? [];

/* -----------------------------------------------------------------------
   2) DB helpers
------------------------------------------------------------------------ */
function fetch_rows_for_list(mysqli $conn, int $listId): array {
    $stmt = $conn->prepare("
        SELECT beneficiary_id, list_id, first_name, middle_name, last_name, ext_name,
               birth_date, region, province, city, barangay, marital_status
        FROM beneficiary WHERE list_id = ? ORDER BY beneficiary_id ASC
    ");
    $stmt->bind_param("i", $listId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function fetch_filename_for_list(mysqli $conn, int $listId): string {
    $stmt = $conn->prepare("SELECT fileName FROM beneficiarylist WHERE list_id = ?");
    $stmt->bind_param("i", $listId);
    $stmt->execute();
    $stmt->bind_result($fileName);
    $name = $stmt->fetch() ? ($fileName ?: "List_$listId.csv") : "List_$listId.csv";
    $stmt->close();
    return $name;
}

/* -----------------------------------------------------------------------
   3) Write temp CSVs
------------------------------------------------------------------------ */
function write_temp_csv_for_list(array $rows, string $displayFileName, string $tempDir): string {
    $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $displayFileName);
    $path = rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;

    $headers = [
        'Beneficiary ID','List ID','First Name','Middle Name','Last Name','Ext',
        'Birth Date','Region','Province','City','Barangay','Marital Status'
    ];

    if (($fp = fopen($path, 'w')) === false) {
        throw new RuntimeException("Failed to create temp CSV at $path");
    }

    fputcsv($fp, $headers);
    foreach ($rows as $r) {
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
            $r['marital_status'] ?? ''
        ]);
    }
    fclose($fp);
    return $path;
}

/* -----------------------------------------------------------------------
   4) Execute Python analysis
------------------------------------------------------------------------ */
function run_python_analysis_with_files(array $filePaths): array {
    if (empty($filePaths)) return [];

    $scriptPath = escapeshellarg(__DIR__ . "/../../clean_data.py");
    $python = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'python' : 'python3';
    $cmd = "$python $scriptPath " . implode(' ', array_map('escapeshellarg', $filePaths));


    $spec = [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]];
    $proc = proc_open($cmd, $spec, $pipes);
    if (!is_resource($proc)) return ["error" => "Failed to start analyzer."];

    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exitCode = proc_close($proc);

    if (empty(trim($out))) {
    return ["error" => "Python returned no output. Check clean_data.py execution or permissions."];
}


    $data = json_decode($out, true);
    if ($exitCode !== 0 || !$data || isset($data['error'])) {
        return ["error" => $data['error'] ?? ("Analysis failed: " . trim($err ?: $out))];
    }

    // accept both flat-table or nested structure
    if (isset($data['table'])) return $data['table'];
    return $data;
}

/* -----------------------------------------------------------------------
   5) Save results to DB
------------------------------------------------------------------------ */
function save_flagged_rows_grouped(mysqli $conn, array $flaggedRows): void {
    if (empty($flaggedRows)) return;
    $byList = [];
    foreach ($flaggedRows as $r) {
        if (empty($r['List ID']) || empty($r['Beneficiary ID']) || empty($r['Reason'])) continue;
        $byList[(int)$r['List ID']][] = $r;
    }

    foreach ($byList as $listId => $rows) {
        $processingId = null;
        $stmt = $conn->prepare("SELECT processing_id FROM processing_engine WHERE list_id = ? ORDER BY processing_id DESC LIMIT 1");
        $stmt->bind_param("i", $listId);
        $stmt->execute();
        $stmt->bind_result($processingId);
        $stmt->fetch();
        $stmt->close();
        if (!$processingId) continue;

        $conn->query("DELETE FROM duplicaterecord WHERE processing_id = " . (int)$processingId);

        $ins = $conn->prepare("
          INSERT INTO duplicaterecord (beneficiary_id, processing_id, flagged_reason, status)
          VALUES (?, ?, ?, 'unresolved')
        ");
        foreach ($rows as $r) {
            $bid = (int)$r['Beneficiary ID'];
            $reason = (string)$r['Reason'];
            $ins->bind_param("iis", $bid, $processingId, $reason);
            $ins->execute();
        }
        $ins->close();
    }
}

/* -----------------------------------------------------------------------
   6) Main processing
------------------------------------------------------------------------ */
$overall = ['total_records'=>0];
$allFlagged = [];
$perList = [];
$tempDir = sys_get_temp_dir() . '/cleanaid_' . uniqid();
@mkdir($tempDir, 0777, true);

$filePaths = [];
foreach ($lists as $lid) {
    if (!ctype_digit((string)$lid)) continue;
    $lid = (int)$lid;
    $fileName = fetch_filename_for_list($conn, $lid);
    $rows = fetch_rows_for_list($conn, $lid);
    $overall['total_records'] += count($rows);

    try {
        $csv = write_temp_csv_for_list($rows, $fileName, $tempDir);
        $filePaths[] = $csv;
        $perList[] = ['list_id'=>$lid,'fileName'=>$fileName,'error'=>null,'summary'=>['total_records'=>count($rows)]];
    } catch (Throwable $e) {
        $perList[] = ['list_id'=>$lid,'fileName'=>$fileName,'error'=>$e->getMessage(),'summary'=>['total_records'=>count($rows)]];
    }
}

$data = !empty($filePaths) ? run_python_analysis_with_files($filePaths) : [];
if (is_array($data) && !isset($data['error'])) {
    $allFlagged = array_values(array_filter($data, fn($r) => !empty($r['Reason'])));
    save_flagged_rows_grouped($conn, $allFlagged);
} else {
    $perList[] = ['list_id'=>0,'fileName'=>'Combined','error'=>$data['error'] ?? 'Analysis failed','summary'=>['total_records'=>0]];
}

// cleanup temp files
try {
    foreach ($filePaths as $p) {
        if (file_exists($p)) @unlink($p);
    }
    if (is_dir($tempDir)) @rmdir($tempDir);
} catch (Throwable $e) {
    error_log("Cleanup warning: " . $e->getMessage());
}


/* -----------------------------------------------------------------------
   7) Compute summary stats
------------------------------------------------------------------------ */
$overall['exact_duplicates_count'] = 0;
$overall['fuzzy_duplicates_count'] = 0;
$overall['sounds_like_count'] = 0;

foreach ($allFlagged as $r) {
    $reason = strtolower($r['Reason'] ?? '');
    if (str_contains($reason, 'exact duplicate')) $overall['exact_duplicates_count']++;
    if (str_contains($reason, 'possible duplicate')) $overall['fuzzy_duplicates_count']++;
    if (str_contains($reason, 'sounds-like duplicate')) $overall['sounds_like_count']++;
}

/* -----------------------------------------------------------------------
   8) Render Page
------------------------------------------------------------------------ */
include("./includes/header.php");
include("./includes/topbar.php");
include("./includes/sidebar.php");
?>
<style>
/* --- Layout fixes --- */
.content-header {
  display: none !important;
}

/* Ensures the content area doesn't overlap the sidebar */
.content-wrapper {
  margin-left: 250px; /* match your sidebar width */
  padding: 20px;
  background: url('../../assets/img/bg-login.png') no-repeat center center fixed;
  min-height: 100vh;
  box-sizing: border-box;
  background-size: cover;
  position: relative;
}

/* Adjust top spacing if you have a fixed topbar */
@media (min-width: 992px) {
  .content-wrapper {
    margin-top: 60px; /* height of fixed topbar if any */
  }
}

/* Responsive behavior for smaller devices */
@media (max-width: 991px) {
  .content-wrapper {
    margin-left: 0;
    padding: 15px;
  }
}

/* --- Review page container --- */
.review-page {
  max-width: 1200px;
  margin: 0 auto;
  padding: 20px 15px 30px;
}

/* --- Card styling --- */
.ca-card {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  background: #fff;
  margin-bottom: 20px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
.ca-body {
  padding: 18px;
}

/* --- Chips, badges, and summary --- */
.chip {
  display: inline-block;
  font-size: 0.8rem;
  background: #f3f4f6;
  color: #374151;
  padding: 5px 10px;
  border-radius: 999px;
  font-weight: 500;
}

.badge-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 6px;
}
.badge {
  background: #eef2ff;
  color: #3730a3;
  border: 1px solid #c7d2fe;
  border-radius: 999px;
  padding: 3px 8px;
  font-size: 0.75rem;
}

/* --- Table styling --- */
.table-wrap {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: auto;
  margin-top: 12px;
}

.table {
  width: 100%;
  border-collapse: collapse;
}
.table th,
.table td {
  padding: 0.75rem 1rem;
  font-size: 0.9rem;
  vertical-align: middle;
  white-space: nowrap;
}
.table thead th {
  background: #f9fafb;
  position: sticky;
  top: 0;
  z-index: 1;
}

/* --- Alerts --- */
.alert-tight {
  padding: 12px;
  margin: 10px 0;
  border-radius: 6px;
}
.alert-success {
  background: #d1fae5;
  color: #065f46;
}

/* --- Buttons --- */
.btn-export {
  background: #2563eb;
  color: #fff;
  border: none;
  padding: 10px 16px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.85rem;
  margin-top: 12px;
  transition: background 0.2s ease;
}
.btn-export:hover {
  background: #1d4ed8;
}

/* --- General utility --- */
.page-title {
  font-size: 1.4rem;
  font-weight: 600;
  margin-bottom: 10px;
  color: #111827;
}
</style>


<div class="content-wrapper">
  <section class="content">
    <div class="container-fluid review-page">
      <div class="page-title fw-bold mb-3">Review Summary</div>

      <?php if (empty($lists)): ?>
        <div class="ca-card"><div class="ca-body">No uploaded lists found or cleaning not yet run.</div></div>
      <?php else: ?>
        <div class="ca-card"><div class="ca-body">
          <div class="d-flex align-items-center mb-2" style="gap:8px;">
            <span class="chip">Summary</span>
            <small>Across <?= count($lists) ?> list(s)</small>
          </div>
          <ul class="mb-2">
            <li><strong>Total Records:</strong> <?= (int)$overall['total_records'] ?></li>
            <li><strong>Exact Duplicates:</strong> <?= (int)$overall['exact_duplicates_count'] ?></li>
            <li><strong>Possible Duplicates:</strong> <?= (int)$overall['fuzzy_duplicates_count'] ?></li>
            <li><strong>Sounds-Like Duplicates:</strong> <?= (int)$overall['sounds_like_count'] ?></li>
          </ul>
          <div class="badge-list">
            <?php foreach ($perList as $pl): ?>
              <span class="badge" title="List ID: <?= (int)$pl['list_id'] ?>">
                <?= htmlspecialchars($pl['fileName']) ?><?= !empty($pl['error']) ? ' — error' : '' ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div></div>

        <div class="ca-card"><div class="ca-body">
          <div class="d-flex align-items-center mb-3" style="gap:8px;">
            <span class="chip">Flagged Records</span><small>All Lists</small>
          </div>

          <?php if (empty($allFlagged)): ?>
            <div class="alert-success alert-tight">No issues found 🎉</div>
          <?php else: ?>
            <div class="table-wrap">
              <table id="flaggedTable" class="table table-hover table-bordered mb-0">
                <thead><tr>
                  <th>Dup Group</th><th>Beneficiary ID</th><th>List ID</th><th>Source File</th>
                  <th>Full Name</th><th>Birth Date</th><th>Region</th><th>Province</th>
                  <th>City</th><th>Barangay</th><th>Marital Status</th><th>Reason</th>
                </tr></thead>
                <tbody>
                  <?php foreach ($allFlagged as $r): ?>
                  <tr>
                    <td><?= htmlspecialchars($r['Dup Group'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Beneficiary ID'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['List ID'] ?? '') ?></td>
                    <td><?= htmlspecialchars(basename($r['Source File'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($r['Full Name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Birth Date'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Region'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Province'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['City'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Barangay'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Marital Status'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Reason'] ?? '') ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <button class="btn-export" onclick="exportTableToCSV('flagged_records.csv')">
              ⬇ Download Flagged Records
            </button>
          <?php endif; ?>
        </div></div>
      <?php endif; ?>
    </div>
  </section>
</div>

<script>
function downloadCSV(csv, filename) {
  const blob = new Blob([csv], {type: "text/csv"});
  const link = document.createElement("a");
  link.download = filename;
  link.href = URL.createObjectURL(blob);
  link.style.display = "none";
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

function exportTableToCSV(filename) {
  const rows = document.querySelectorAll("#flaggedTable tr");
  if (!rows.length) { alert("No flagged records to export."); return; }
  const csv = Array.from(rows).map(r =>
    Array.from(r.querySelectorAll("td,th"))
      .map(td => `"${td.innerText.replace(/"/g,'""')}"`).join(",")
  ).join("\n");
  downloadCSV(csv, filename);
}
</script>

<?php include("./includes/footer.php"); ?>
