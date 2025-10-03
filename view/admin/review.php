<?php
// view/admin/review.php
session_start();
require_once __DIR__ . '/../../dB/config.php';

/* -----------------------------------------------------------------------
   1) Collect selected list IDs from previous step (or POST)
------------------------------------------------------------------------ */
if (!empty($_POST['lists']) && is_array($_POST['lists'])) {
    $_SESSION['uploaded_lists'] = array_values(
        array_filter($_POST['lists'], fn($v) => ctype_digit((string)$v))
    );
}
$lists = $_SESSION['uploaded_lists'] ?? [];

/* -----------------------------------------------------------------------
   2) Helpers to fetch data from DB
------------------------------------------------------------------------ */
function fetch_rows_for_list(mysqli $conn, int $listId): array {
    $stmt = $conn->prepare("
        SELECT beneficiary_id, list_id, first_name, last_name, middle_name, ext_name,
               birth_date, region, province, city, barangay, marital_status
        FROM beneficiary
        WHERE list_id = ?
        ORDER BY beneficiary_id ASC
    ");
    $stmt->bind_param("i", $listId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
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
   3) Write each list to a TEMP CSV file (headers match clean_data.py)
------------------------------------------------------------------------ */
function write_temp_csv_for_list(array $rows, string $displayFileName, string $tempDir): string {
    $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $displayFileName);
    $path = rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;

    $headers = [
        'Beneficiary ID','List ID','First Name','Middle Name','Last Name','Ext',
        'Birth Date','Region','Province','City','Barangay','Marital Status'
    ];

    $fp = fopen($path, 'w');
    if ($fp === false) {
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
            $r['marital_status'] ?? '',
        ]);
    }
    fclose($fp);
    return $path;
}

/* -----------------------------------------------------------------------
   4) Run Python analyzer with CSV files
------------------------------------------------------------------------ */
function run_python_analysis_with_files(array $filePaths): array {
    if (empty($filePaths)) return [];

    $scriptPath = __DIR__ . "/../../clean_data.py";
    $cmd = ['python3', $scriptPath];
    foreach ($filePaths as $p) $cmd[] = $p;

    $escaped = array_map('escapeshellarg', $cmd);
    $command = implode(' ', $escaped);

    $spec = [ 0=>["pipe","r"], 1=>["pipe","w"], 2=>["pipe","w"] ];
    $proc = proc_open($command, $spec, $pipes);
    if (!is_resource($proc)) return ["error" => "Failed to execute analysis script."];

    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);

    $data = json_decode($out, true);
    if ($code !== 0 || !$data || isset($data['error'])) {
        return ["error" => $data['error'] ?? ("Analysis failed: " . trim($err ?: $out))];
    }
    return $data;
}

/* -----------------------------------------------------------------------
   5) Save flagged rows to DB
------------------------------------------------------------------------ */
function save_flagged_rows_grouped(mysqli $conn, array $flaggedRows): void {
    if (empty($flaggedRows)) return;

    $byList = [];
    foreach ($flaggedRows as $r) {
        if (empty($r['List ID']) || empty($r['Beneficiary ID']) || empty($r['Reason'])) continue;
        $lid = (int)$r['List ID'];
        $byList[$lid][] = $r;
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

        $del = $conn->prepare("DELETE FROM duplicaterecord WHERE processing_id = ?");
        $del->bind_param("i", $processingId);
        $del->execute();
        $del->close();

        $ins = $conn->prepare("INSERT INTO duplicaterecord (beneficiary_id, processing_id, flagged_reason, status) VALUES (?,?,?,?)");
        foreach ($rows as $r) {
            $status = 'unresolved';
            $bid = (int)$r['Beneficiary ID'];
            $reason = (string)$r['Reason'];
            $ins->bind_param("iiss", $bid, $processingId, $reason, $status);
            $ins->execute();
        }
        $ins->close();
    }
}

/* -----------------------------------------------------------------------
   6) MAIN PROCESS
------------------------------------------------------------------------ */
$overall = ['total_records' => 0];
$allFlagged = [];
$perList = [];
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cleanaid_' . uniqid();
@mkdir($tempDir, 0777, true);

$filePaths = [];
$fileNameMap = [];

foreach ($lists as $listIdRaw) {
    if (!ctype_digit((string)$listIdRaw)) continue;
    $listId   = (int)$listIdRaw;
    $fileName = fetch_filename_for_list($conn, $listId);
    $rows     = fetch_rows_for_list($conn, $listId);

    $overall['total_records'] += count($rows);

    try {
        $csvPath = write_temp_csv_for_list($rows, $fileName, $tempDir);
        $filePaths[] = $csvPath;
        $fileNameMap[$csvPath] = $fileName;
        $perList[] = ['list_id'=>$listId, 'fileName'=>$fileName, 'error'=>null, 'summary'=>['total_records'=>count($rows)]];
    } catch (Throwable $e) {
        $perList[] = ['list_id'=>$listId, 'fileName'=>$fileName, 'error'=>$e->getMessage(), 'summary'=>['total_records'=>count($rows)]];
    }
}

$data = [];
if (!empty($filePaths)) {
    $data = run_python_analysis_with_files($filePaths);
}

if (is_array($data) && !isset($data['error'])) {
    $allFlagged = array_values(array_filter($data, fn($r) => !empty($r['Reason'])));
    save_flagged_rows_grouped($conn, $allFlagged);
} else {
    $perList[] = ['list_id'=>0,'fileName'=>'Combined','error'=>$data['error'] ?? 'Analysis failed.','summary'=>['total_records'=>0]];
}

foreach ($filePaths as $p) { @unlink($p); }
@rmdir($tempDir);

/* -----------------------------------------------------------------------
   6.1) Compute Summary Counts from $allFlagged
------------------------------------------------------------------------ */
$overall['exact_duplicates_count'] = 0;
$overall['fuzzy_duplicates_count'] = 0;
$overall['sounds_like_count'] = 0;

foreach ($allFlagged as $row) {
    $reason = strtolower($row['Reason'] ?? '');
    if (strpos($reason, 'exact duplicate') !== false) {
        $overall['exact_duplicates_count']++;
    }
    if (strpos($reason, 'possible duplicate') !== false) {
        $overall['fuzzy_duplicates_count']++;
    }
    if (strpos($reason, 'sounds-like duplicate') !== false) {
        $overall['sounds_like_count']++;
    }
}

/* -----------------------------------------------------------------------
   7) Render Page
------------------------------------------------------------------------ */
include("./includes/header.php");
include("./includes/topbar.php");
include("./includes/sidebar.php");
?>
<style>
.content-header { display:none!important; }
.review-page { max-width: 1280px; padding: 20px 15px 30px; margin: 0 auto; }
.ca-card { border:1px solid #e5e7eb; border-radius:10px; background:#fff; margin-bottom:15px; }
.ca-body { padding:18px; }
.chip { display:inline-block; font-size:.8rem; background:#f3f4f6; color:#374151; padding:5px 10px; border-radius:999px; }
.table-wrap { border:1px solid #e5e7eb; border-radius:8px; overflow:auto; margin-top:12px; }
.table th, .table td { padding:.75rem 1rem; font-size:.9rem; vertical-align: middle; white-space:nowrap; }
.table thead th { background:#f9fafb; position:sticky; top:0; z-index:1; }
.badge-list { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
.badge { background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; border-radius:999px; padding:3px 8px; font-size:.75rem; }
.alert-tight { padding:12px; margin:10px 0; border-radius:6px; }
.alert-success { background:#d1fae5; color:#065f46; }
.btn-export { background:#2563eb; color:#fff; border:none; padding:10px 16px; border-radius:6px; cursor:pointer; font-size:.85rem; margin-top:12px; }
.btn-export:hover { background:#1d4ed8; }
</style>

<div class="content-wrapper">
  <section class="content">
    <div class="container-fluid review-page">
      <div class="page-title">Review Summary (All Lists)</div>

      <?php if (empty($lists)): ?>
        <div class="ca-card"><div class="ca-body">No uploaded lists found or processing failed.</div></div>
      <?php else: ?>

        <!-- ✅ SUMMARY -->
        <div class="ca-card">
          <div class="ca-body">
            <div class="d-flex align-items-center mb-2" style="gap:8px;">
              <span class="chip">Summary</span>
              <small>Combined across <?= count($lists) ?> list(s)</small>
            </div>
            <ul class="mb-2">
              <li><strong>Total Records Processed:</strong> <?= (int)($overall['total_records'] ?? 0) ?></li>
              <li><strong>Exact Duplicates:</strong> <?= (int)($overall['exact_duplicates_count'] ?? 0) ?></li>
              <li><strong>Possible Duplicates:</strong> <?= (int)($overall['fuzzy_duplicates_count'] ?? 0) ?></li>
              <li><strong>Sounds-Like Duplicates:</strong> <?= (int)($overall['sounds_like_count'] ?? 0) ?></li>
            </ul>
            <div class="badge-list">
              <?php foreach ($perList as $pl): ?>
                <span class="badge" title="List ID: <?= (int)$pl['list_id'] ?>">
                  <?= htmlspecialchars($pl['fileName']) ?>
                  <?php if (!empty($pl['error'])): ?> — error<?php endif; ?>
                </span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- ✅ FLAGGED TABLE -->
        <div class="ca-card">
          <div class="ca-body">
            <div class="d-flex align-items-center mb-3" style="gap:8px;">
              <span class="chip">Flagged Records</span>
              <small>Combined</small>
            </div>

            <?php if (empty($allFlagged)): ?>
              <div class="alert-success alert-tight">No issues found 🎉</div>
            <?php else: ?>
              <div class="table-wrap">
                <table id="flaggedTable" class="table table-hover table-bordered align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Dup Group</th>
                      <th>Beneficiary ID</th>
                      <th>List ID</th>
                      <th>Source File</th>
                      <th>Full Name</th>
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
            <?php endif; ?>

            <!-- ✅ EXPORT BUTTON -->
            <button class="btn-export" onclick="exportTableToCSV('flagged_records.csv')">Download All Flagged Records</button>
          </div>
        </div>

      <?php endif; ?>
    </div>
  </section>
</div>

<script>
// ✅ CSV Export
function downloadCSV(csv, filename) {
    var csvFile = new Blob([csv], {type: "text/csv"});
    var downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}
function exportTableToCSV(filename) {
    var csv = [];
    var rows = document.querySelectorAll("#flaggedTable tr");
    if (rows.length === 0) {
        alert("No flagged records available.");
        return;
    }
    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll("td, th");
        for (var j = 0; j < cols.length; j++) {
            row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
        }
        csv.push(row.join(","));
    }
    downloadCSV(csv.join("\n"), filename);
}
</script>

<?php include("./includes/footer.php"); ?>
