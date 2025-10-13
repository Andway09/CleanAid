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

    return $data['table'] ?? $data;
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
   6) Main processing (ensure variables are ALWAYS defined)
------------------------------------------------------------------------ */
$overall = [
  'total_records' => 0,
  'exact_duplicates_count' => 0,
  'fuzzy_duplicates_count' => 0,
  'sounds_like_count' => 0,
];

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
        $perList[] = [
          'list_id'=>$lid,
          'fileName'=>$fileName,
          'error'=>null,
          'summary'=>['total_records'=>count($rows)]
        ];
    } catch (Throwable $e) {
        $perList[] = [
          'list_id'=>$lid,
          'fileName'=>$fileName,
          'error'=>$e->getMessage(),
          'summary'=>['total_records'=>count($rows)]
        ];
    }
}

$data = !empty($filePaths) ? run_python_analysis_with_files($filePaths) : [];
if (is_array($data) && !isset($data['error'])) {
    $allFlagged = array_values(array_filter($data, fn($r) => !empty($r['Reason'])));

    // Save to DB (optional)
    save_flagged_rows_grouped($conn, $allFlagged);
} else {
    if (!empty($filePaths)) { // analysis attempted but failed
        $perList[] = ['list_id'=>0,'fileName'=>'Combined','error'=>$data['error'] ?? 'Analysis failed','summary'=>['total_records'=>0]];
    }
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
   7) Compute summary stats safely
------------------------------------------------------------------------ */
foreach ($allFlagged as $r) {
    $reason = strtolower($r['Reason'] ?? '');
    if (str_contains($reason, 'exact duplicate')) $overall['exact_duplicates_count']++;
    if (str_contains($reason, 'possible duplicate')) $overall['fuzzy_duplicates_count']++;
    if (str_contains($reason, 'sounds-like duplicate')) $overall['sounds_like_count']++;
}

// ✅ Save summary for Dashboard
$_SESSION['latest_summary'] = [
  'total_flagged'       => count($allFlagged ?? []),
  'exact_duplicates'    => $overall['exact_duplicates_count'] ?? 0,
  'possible_duplicates' => $overall['fuzzy_duplicates_count'] ?? 0,
  'sounds_like'         => $overall['sounds_like_count'] ?? 0
];

/* -----------------------------------------------------------------------
   8) Render Page (UI / Aesthetics)
------------------------------------------------------------------------ */
include("./includes/header.php");
include("./includes/topbar.php");
include("./includes/sidebar.php");
?>
<style>
/* 🌿 General Layout */
.content-header { display: none !important; }

.content-wrapper {
  margin-left: 250px; /* stays beside sidebar */
  padding: 0;
  background: url('../../assets/img/bg-login.png') no-repeat center center fixed;
  background-size: cover;
  min-height: 100vh;
}

@media (max-width: 991px) {
  .content-wrapper { margin-left: 0; padding: 15px; }
}

/* 🧭 Review Page Container */
.review-page {
  max-width: 1200px;
  margin-left: 40px; /* keep it aligned left */
  padding: 100px 40px 40px;
}

/* 🏷️ Page Title */
.page-title {
  font-size: 2rem;
  font-weight: 700;
  color: #1e293b;
  text-align: left;
  margin-top: 10px;
  margin-bottom: 30px;
  letter-spacing: 0.5px;
  text-shadow: 0 2px 6px rgba(255,255,255,0.9);
}

/* 📦 White Card */
.ca-card {
  background: #fff;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  box-shadow: 0 3px 8px rgba(0,0,0,0.06);
  margin-bottom: 25px;
  transition: all 0.2s ease;
  text-align: left;
}
.ca-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.ca-body { padding: 25px 30px; }

/* ⚠️ Empty Message */
.empty-message {
  color: #664D03;
  background: #fff3cd;
  padding: 18px 20px;
  border-radius: 8px;
  border: 1px solid #f3f4f6;
  text-align: left; /* keep text left */
  box-shadow: inset 0 0 4px rgba(0,0,0,0.03);
}

/* 💠 Chips & Badges */
.chip {
  display: inline-block;
  font-size: 0.8rem;
  background: #e0e7ff;
  color: #3730a3;
  padding: 5px 10px;
  border-radius: 999px;
  font-weight: 600;
}
.badge-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.badge {
  background: #f3f4f6;
  color: #111827;
  border: 1px solid #e5e7eb;
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 0.8rem;
}

/* 📊 Table Styling */
.table-wrap {
  width: 100%;
  overflow-x: auto;
  background: rgba(255, 255, 255, 0.97);
  border-radius: 10px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  padding: 10px;
}

.table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
  table-layout: auto;
}

.table th, .table td {
  padding: 10px 14px;
  text-align: left;
  border-bottom: 1px solid #e5e7eb;
  white-space: nowrap;
}

.table thead th {
  background: #e0e7ff;
  color: #1e3a8a;
  font-weight: 600;
  position: sticky;
  top: 0;
  z-index: 1;
}

.table tbody tr:hover {
  background: #f3f4f6;
  transition: background 0.2s ease;
}

/* 🎨 Buttons */
.btn-export {
  background: #2563eb;
  color: #fff;
  border: none;
  padding: 9px 14px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.85rem;
  transition: background 0.2s ease;
}
.btn-export:hover { background: #1e40af; }

/* 🎛️ Filter Buttons */
.filter-group {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
}
.filter-btn {
  padding: 6px 14px;
  border: 1px solid #c7d2fe;
  background: #eef2ff;
  color: #3730a3;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s ease;
}
.filter-btn:hover { background: #c7d2fe; }
.filter-btn.active {
  background: #3730a3;
  color: #fff;
  border-color: #3730a3;
}
</style>



<div class="content-wrapper">
  <section class="content">
    <div class="container-fluid review-page">
      <div class="page-title">Review Summary</div>

      <?php if (empty($lists)): ?>
  <div class="ca-card">
    <div class="ca-body">
      <div class="empty-message">
        No uploaded lists found or cleaning not yet run.
      </div>
    </div>
  </div>
<?php else: ?>


        <div class="ca-card"><div class="ca-body">
          <div class="d-flex align-items-center mb-3" style="gap:8px;">
            <span class="chip">Summary</span>
            <small>Across <?= count($lists) ?> list(s)</small>
          </div>
          <ul class="mb-3" style="line-height:1.6;">
            <li><strong>Total Records:</strong> <?= (int)($overall['total_records'] ?? 0) ?></li>
            <li><strong>Exact Duplicates:</strong> <?= (int)($overall['exact_duplicates_count'] ?? 0) ?></li>
            <li><strong>Possible Duplicates:</strong> <?= (int)($overall['fuzzy_duplicates_count'] ?? 0) ?></li>
            <li><strong>Sounds-Like Duplicates:</strong> <?= (int)($overall['sounds_like_count'] ?? 0) ?></li>
          </ul>
          <div class="badge-list">
            <?php foreach (($perList ?? []) as $pl): ?>
              <span class="badge" title="List ID: <?= (int)($pl['list_id'] ?? 0) ?>">
                <?= htmlspecialchars($pl['fileName'] ?? 'Unknown') ?><?= !empty($pl['error']) ? ' — error' : '' ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div></div>

        <div class="ca-card"><div class="ca-body">
          <div class="d-flex flex-wrap align-items-center justify-content-between mb-3" style="gap:10px;">
            <div class="d-flex align-items-center" style="gap:8px;">
              <span class="chip">Flagged Records</span>
              <small>All Lists</small>
            </div>

            <?php if (!empty($allFlagged)): ?>
              <button class="btn-export" onclick="exportTableToCSV('flagged_records.csv')">
                Download Flagged Records
              </button>
            <?php endif; ?>
          </div>

          <!-- 🌈 Filter Buttons -->
          <div class="filter-group">
            <button class="filter-btn active" data-filter="all" onclick="applyFilter(this)">All</button>
            <button class="filter-btn" data-filter="exact" onclick="applyFilter(this)">Exact Duplicate</button>
            <button class="filter-btn" data-filter="possible" onclick="applyFilter(this)">Possible Duplicate</button>
            <button class="filter-btn" data-filter="sounds" onclick="applyFilter(this)">Sounds-like Duplicate</button>
            <button class="filter-btn" data-filter="missing" onclick="applyFilter(this)">Missing Data</button>
          </div>

          <?php if (empty($allFlagged)): ?>
            <div class="alert-success alert-tight mt-3">No issues found 🎉</div>
          <?php else: ?>
            <div class="table-wrap mt-3">
              <table id="flaggedTable" class="table table-hover table-bordered mb-0">
                <thead>
                  <tr>
                    <th>Dup Group</th>
                    <th>Beneficiary ID</th>
                    <th>List ID</th>
                    <th>Source File</th>
                    <th>First Name</th>
                    <th>Middle Name</th>
                    <th>Last Name</th>
                    <th>Ext</th>
                    <th>Birth Date</th>
                    <th>Region</th>
                    <th>Province</th>
                    <th>City</th>
                    <th>Barangay</th>
                    <th>Marital Status</th>
                    <th>Reason</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (($allFlagged ?? []) as $r): ?>
                  <tr>
                    <td><?= htmlspecialchars($r['Dup Group'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Beneficiary ID'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['List ID'] ?? '') ?></td>
                    <td><?= htmlspecialchars(basename($r['Source File'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($r['First Name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Middle Name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Last Name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Ext'] ?? '') ?></td>
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
          <?php endif; ?>
        </div></div>
      <?php endif; ?>
    </div>
  </section>
</div>

<script>
function downloadCSV(csv, filename) {
  const blob = new Blob([csv], { type: "text/csv" });
  const link = document.createElement("a");
  link.download = filename;
  link.href = URL.createObjectURL(blob);
  link.click();
}

function exportTableToCSV(filename) {
  const rows = document.querySelectorAll("#flaggedTable tr");
  if (!rows.length) return alert("No flagged records to export.");
  const csv = Array.from(rows).map(r =>
    Array.from(r.querySelectorAll("td,th"))
      .map(td => `"${td.innerText.replace(/"/g,'""')}"`)
      .join(",")
  ).join("\n");
  downloadCSV(csv, filename);
}

function applyFilter(button) {
  document.querySelectorAll(".filter-btn").forEach(btn => btn.classList.remove("active"));
  button.classList.add("active");

  const filter = button.dataset.filter;
  const rows = document.querySelectorAll("#flaggedTable tbody tr");

  rows.forEach(row => {
    const reasonCell = row.querySelector("td:last-child");
    const reason = reasonCell ? reasonCell.textContent.toLowerCase() : "";
    let show = false;

    if (filter === "all") show = true;
    else if (filter === "exact" && reason.includes("exact duplicate")) show = true;
    else if (filter === "possible" && reason.includes("possible duplicate")) show = true;
    else if (filter === "sounds" && reason.includes("sounds-like duplicate")) show = true;
    else if (filter === "missing" && reason.includes("missing")) show = true;

    row.style.display = show ? "" : "none";
  });
}
</script>

<?php include("./includes/footer.php"); ?>
