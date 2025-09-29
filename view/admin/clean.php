<?php
session_start();
include('../../dB/config.php');

$lists = $_SESSION['uploaded_lists'] ?? [];
?>

<?php include("./includes/header.php"); ?>
<?php include("./includes/topbar.php"); ?>
<?php include("./includes/sidebar.php"); ?>

<main class="main bg-body-tertiary" style="min-height: 100vh;">
  <section class="container py-5">
    <h2 class="fw-bold">Clean Data</h2>
    <p class="text-muted">Run data cleansing to detect duplicates and inconsistencies in your uploaded data.</p>

    <!-- Uploaded Files -->
    <div class="card shadow-sm border-0 rounded-4 p-4 mb-4 w-100">
      <h5 class="fw-semibold mb-3">Uploaded Files</h5>

      <?php if (!empty($lists)): ?>
        <ul class="list-group list-group-flush mb-3">
          <?php foreach ($lists as $listId): 
            $res = mysqli_query($conn, "SELECT fileName FROM beneficiarylist WHERE list_id='$listId'");
            $row = mysqli_fetch_assoc($res);
          ?>
            <li class="list-group-item d-flex align-items-center">
              <img src="https://cdn-icons-png.flaticon.com/512/4725/4725976.png" alt="xls icon" width="24" class="me-2">
              <span><?= htmlspecialchars($row['fileName'] ?? "List #$listId") ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="text-muted small">These lists will be scanned for duplicate entries.</div>
      <?php else: ?>
        <div class="alert alert-warning mb-0">No uploaded lists found.</div>
      <?php endif; ?>
    </div>

    <!-- Start Cleaning Button -->
    <?php if (!empty($lists)): ?>
      <div class="text-center">
        <button type="button" class="btn btn-success px-4 rounded-pill" onclick="startCleaning()">
          Start Cleaning
        </button>
      </div>
    <?php endif; ?>
  </section>
</main>

<!-- Cleaning Progress Overlay -->
<div id="cleaningOverlay">
  <div class="loader-container">
    <div class="spinner"></div>
    <p class="loading-text">Starting cleaning...</p>
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

.loader-container {
  text-align: center;
  max-width: 400px;
  width: 100%;
}

.spinner {
  width: 60px;
  height: 60px;
  border: 6px solid #ddd;
  border-top: 6px solid #dc3545; /* 🔴 Bootstrap danger red */
  border-radius: 50%;
  animation: spin 1s linear infinite;
  margin: auto;
}

.loading-text {
  margin: 15px 0;
  font-size: 18px;
  color: #333;
  font-weight: 500;
}

.progress-wrapper {
  width: 100%;
  height: 12px;
  background: #eee;
  border-radius: 8px;
  overflow: hidden;
  margin: 10px 0;
}

#cleanProgressBar {
  height: 100%;
  width: 0%;
  background: linear-gradient(90deg, #dc3545, #ff6b6b); 
  transition: width 0.3s ease;
}

#cleanProgressPercent {
  font-size: 14px;
  font-weight: 600;
  color: #dc3545; /* 🔴 Matches spinner color */
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>

<script>
function startCleaning() {
  document.getElementById('cleaningOverlay').style.display = 'flex';

  // tell PHP to start cleaning
  fetch('../../controller/clean_process.php?start=1')
    .then(() => {
      let progressInterval = setInterval(() => {
        fetch('../../controller/clean_process.php?progress=1')
          .then(res => res.json())
          .then(data => {
            if (!data || typeof data.percent === "undefined") return;

            const percent = data.percent;
            document.getElementById('cleanProgressBar').style.width = percent + "%";
            document.getElementById('cleanProgressPercent').innerText = percent + "%";
            document.querySelector('#cleaningOverlay .loading-text').innerText = data.message;

            if (percent >= 100) {
              clearInterval(progressInterval);
              document.querySelector('#cleaningOverlay .loading-text').innerText = "✅ Cleaning complete! Redirecting...";
              setTimeout(() => {
                window.location.href = "review.php";
              }, 1000);
            }
          })
          .catch(err => console.error("Progress error:", err));
      }, 1000);
    })
    .catch(err => console.error("Start error:", err));
}
</script>

<?php include("./includes/footer.php"); ?>
