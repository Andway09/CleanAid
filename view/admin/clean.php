<?php
session_start();
include('../../dB/config.php');

if (!isset($_SESSION['user_id'])) {
  header("Location: ../../login.php");
  exit();
}

$userId = (int)$_SESSION['user_id'];
$currentLists = $_SESSION['uploaded_lists'] ?? [];

// 🧩 Step 1: Fallback - if no session list IDs, load the most recent upload for this user
if (empty($currentLists)) {
  $stmtLast = $conn->prepare("
    SELECT list_id
    FROM beneficiarylist
    WHERE user_id = ?
    ORDER BY date_submitted DESC
    LIMIT 1
  ");
  $stmtLast->bind_param('i', $userId);
  $stmtLast->execute();
  $resLast = $stmtLast->get_result();
  if ($resLast && $resLast->num_rows > 0) {
    $row = $resLast->fetch_assoc();
    $currentLists = [(int)$row['list_id']];
    $_SESSION['uploaded_lists'] = $currentLists; // keep it stored for next refresh
  }
}

// 🧩 Step 2: Query the parquet files
$parquetFiles = [];
if (!empty($currentLists)) {
  $placeholders = implode(',', array_fill(0, count($currentLists), '?'));
  $query = "
    SELECT pf.file_name, bl.date_submitted
    FROM parquet_files pf
    INNER JOIN beneficiarylist bl ON pf.list_id = bl.list_id
    WHERE pf.list_id IN ($placeholders)
    ORDER BY pf.id DESC
  ";

  $stmt = $conn->prepare($query);
  $types = str_repeat('i', count($currentLists));
  $stmt->bind_param($types, ...$currentLists);
  $stmt->execute();
  $result = $stmt->get_result();

  while ($row = $result->fetch_assoc()) {
    $parquetFiles[] = $row;
  }
}
?>

<?php include("./includes/header.php"); ?>
<?php include("./includes/topbar.php"); ?>
<?php include("./includes/sidebar.php"); ?>

<main class="main bg-body-tertiary" style="min-height: 100vh;">
  <section class="container py-5">
    <h2 class="fw-bold">Scan Data</h2>
    <p class="text-muted">Run data cleansing to detect duplicates and inconsistencies in your uploaded data.</p>

    <div class="card shadow-sm border-0 rounded-4 p-4 mb-4 w-100">
      <h5 class="fw-semibold mb-3">Uploaded Parquet Files</h5>

      <?php if (!empty($parquetFiles)): ?>
        <ul class="list-group list-group-flush mb-3">
          <?php foreach ($parquetFiles as $row): ?>
            <li class="list-group-item d-flex align-items-center">
              <img src="https://cdn-icons-png.flaticon.com/512/4725/4725976.png"
                   alt="parquet icon" width="24" class="me-2">
              <div>
                <strong><?= htmlspecialchars($row['file_name']) ?></strong><br>
                <small class="text-muted">
                  Uploaded: <?= htmlspecialchars($row['date_submitted']) ?>
                </small>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>

<div class="text-muted small">
  These Parquet files will be scanned for duplicate entries.
        </div>

        <div class="text-center mt-4">
          <button type="button" class="btn btn-success px-4 rounded-pill" onclick="startCleaning()">
            Start Cleaning
          </button>
        </div>

      <?php else: ?>
        <div class="alert alert-warning mb-0">
          No Parquet files found. Please upload CSV/XLSX first.
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<!-- Cleaning Overlay -->
<div id="cleaningOverlay">
  <div class="loader-container">
    <div class="spinner"></div>
    <p class="loading-text">Starting scanning...</p>
    <div class="progress-wrapper">
      <div id="cleanProgressBar"></div>
    </div>
    <p id="cleanProgressPercent">0%</p>
  </div>
</div>

<style>
#cleaningOverlay {
  position: fixed;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: rgba(255, 255, 255, 0.95);
  display: none;
  justify-content: center;
  align-items: center;
  z-index: 9999;
  flex-direction: column;
}
.loader-container { text-align: center; max-width: 400px; width: 100%; }
.spinner {
  width: 60px; height: 60px;
  border: 6px solid #ddd; border-top: 6px solid #198754;
  border-radius: 50%; animation: spin 1s linear infinite; margin: auto;
}
.loading-text { margin: 15px 0; font-size: 18px; color: #333; font-weight: 500; }
.progress-wrapper {
  width: 100%; height: 12px; background: #eee; border-radius: 8px;
  overflow: hidden; margin: 10px 0;
}
#cleanProgressBar {
  height: 100%; width: 0%; background: linear-gradient(90deg, #198754, #6bdc8e);
  transition: width 0.3s ease;
}
#cleanProgressPercent { font-size: 14px; font-weight: 600; color: #198754; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<script>
function startCleaning() {
  const overlay = document.getElementById('cleaningOverlay');
  const bar = document.getElementById('cleanProgressBar');
  const percentText = document.getElementById('cleanProgressPercent');
  const messageText = document.querySelector('#cleaningOverlay .loading-text');

  overlay.style.display = 'flex';
  bar.style.width = '0%';
  percentText.innerText = '0%';
  messageText.innerText = 'Starting cleaning...';

  const source = new EventSource('../../controller/clean_process.php');

  source.onmessage = function(event) {
    try {
      const data = JSON.parse(event.data);
      if (data.error) {
        messageText.innerText = '❌ ' + data.error;
        source.close();
        return;
      }
      if (data.progress !== undefined) {
        bar.style.width = data.progress + '%';
        percentText.innerText = data.progress + '%';
      }
      if (data.message) {
        messageText.innerText = data.message;
      }
      if (data.complete) {
        messageText.innerText = '✅ Cleaning complete! Redirecting...';
        bar.style.width = '100%';
        percentText.innerText = '100%';
        setTimeout(() => {
          window.location.href = 'review.php';
        }, 1200);
        source.close();
      }
    } catch (err) {
      console.error('Invalid SSE data:', event.data);
    }
  };

  source.onerror = function() {
    messageText.innerText = '⚠️ Connection lost or server error.';
    source.close();
  };
}
</script>

<?php include("./includes/footer.php"); ?>