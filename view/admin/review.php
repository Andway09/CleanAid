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
   2) Load flagged results directly from DB (instant load)
------------------------------------------------------------------------ */
$allFlagged = [];
$overall = [
  'total_records' => 0,
  'exact_duplicates_count' => 0,
  'fuzzy_duplicates_count' => 0,
  'sounds_like_count' => 0
];

$query = "
  SELECT d.flagged_reason AS Reason,
         b.beneficiary_id AS `Beneficiary ID`,
         b.list_id AS `List ID`,
         CONCAT_WS(' ', b.first_name, b.middle_name, b.last_name, b.ext_name) AS `Full Name`,
         b.birth_date AS `Birth Date`,
         b.region AS `Region`,
         b.province AS `Province`,
         b.city AS `City`,
         b.barangay AS `Barangay`,
         b.marital_status AS `Marital Status`,
         l.fileName AS `Source File`
  FROM duplicaterecord d
  JOIN beneficiary b ON b.beneficiary_id = d.beneficiary_id
  LEFT JOIN beneficiarylist l ON b.list_id = l.list_id
  ORDER BY d.processing_id DESC, b.beneficiary_id ASC
  LIMIT 5000
";

$res = $conn->query($query);
if ($res && $res->num_rows > 0) {
    $allFlagged = $res->fetch_all(MYSQLI_ASSOC);
    $overall['total_records'] = count($allFlagged);

    foreach ($allFlagged as $r) {
        $reason = strtolower($r['Reason'] ?? '');
        if (str_contains($reason, 'exact duplicate')) $overall['exact_duplicates_count']++;
        elseif (str_contains($reason, 'possible duplicate')) $overall['fuzzy_duplicates_count']++;
        elseif (str_contains($reason, 'sounds-like')) $overall['sounds_like_count']++;
    }
}

include("./includes/header.php");
include("./includes/topbar.php");
include("./includes/sidebar.php");
?>

<style>
/* --- Layout fixes --- */
.content-header { display: none !important; }
.content-wrapper {
  margin-left: 250px;
  padding: 20px;
  background: url('../../assets/img/bg-login.png') no-repeat center center fixed;
  min-height: 100vh;
  box-sizing: border-box;
  background-size: cover;
  position: relative;
}
@media (min-width: 992px) { .content-wrapper { margin-top: 60px; } }
@media (max-width: 991px) { .content-wrapper { margin-left: 0; padding: 15px; } }

/* --- Review page container --- */
.review-page { max-width: 1200px; margin: 0 auto; padding: 20px 15px 30px; }

/* --- Card styling --- */
.ca-card {
  border: 1px solid #e5e7eb; border-radius: 10px; background: #fff;
  margin-bottom: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
.ca-body { padding: 18px; }

/* --- Chips, badges, and summary --- */
.chip {
  display: inline-block; font-size: 0.8rem; background: #f3f4f6;
  color: #374151; padding: 5px 10px; border-radius: 999px; font-weight: 500;
}
.badge-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.badge {
  background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe;
  border-radius: 999px; padding: 3px 8px; font-size: 0.75rem;
}

/* --- Table styling --- */
.table-wrap { border: 1px solid #e5e7eb; border-radius: 8px; overflow: auto; margin-top: 12px; }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td {
  padding: 0.75rem 1rem; font-size: 0.9rem; vertical-align: middle; white-space: nowrap;
}
.table thead th { background: #f9fafb; position: sticky; top: 0; z-index: 1; }

/* --- Alerts --- */
.alert-tight { padding: 12px; margin: 10px 0; border-radius: 6px; }
.alert-success { background: #d1fae5; color: #065f46; }

/* --- Buttons --- */
.btn-export {
  background: #2563eb; color: #fff; border: none;
  padding: 10px 16px; border-radius: 6px; cursor: pointer;
  font-size: 0.85rem; margin-top: 12px; transition: background 0.2s ease;
}
.btn-export:hover { background: #1d4ed8; }

/* --- General utility --- */
.page-title { font-size: 1.4rem; font-weight: 600; margin-bottom: 10px; color: #111827; }
</style>

<div class="content-wrapper">
  <section class="content">
    <div class="container-fluid review-page">
      <div class="page-title fw-bold mb-3">Review Summary</div>

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
                <th>Beneficiary ID</th><th>List ID</th><th>Source File</th>
                <th>Full Name</th><th>Birth Date</th><th>Region</th>
                <th>Province</th><th>City</th><th>Barangay</th>
                <th>Marital Status</th><th>Reason</th>
              </tr></thead>
              <tbody>
                <?php foreach ($allFlagged as $r): ?>
                <tr>
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
