  <?php
  session_start();

  // Restrict access to admin only
  if (!isset($_SESSION['auth']) || $_SESSION['role'] !== 'admin') {
      $_SESSION['message'] = "Unauthorized access. Please login.";
      $_SESSION['code'] = "warning";
      header("Location: ../login.php");
      exit();
  }

  // Disable caching
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

  include("../../dB/config.php");
  include("./includes/header.php");
  include("./includes/topbar.php");
  include("./includes/sidebar.php");
  ?>

  <main id="main" class="main flex-grow-1">
    <div class="container-fluid">

      <div class="pagetitle">
        <h1>Admin Dashboard</h1>
        <nav>
          <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
            <li class="breadcrumb-item active">Dashboard</li>
          </ol>
        </nav>
      </div>

      <section class="dashboard">
        <div class="row g-4">

          <!-- Welcome Card -->
          <div class="col-lg-12">
            <div class="card shadow-sm">
              <div class="card-body">
                <h5 class="card-title">Welcome, <?= htmlspecialchars($_SESSION['authUser']['fullName']) ?>!</h5>
                <p class="text-muted">This is the DSWD CleanAid admin dashboard. Use the sidebar to navigate through system tools such as data upload, data scanning, and review.</p>
              </div>
            </div>
          </div>

          <!-- Recent Uploads -->
          <div class="col-lg-6">
            <div class="card shadow-sm">
              <div class="card-body">
                <h5 class="card-title">Recent Uploads</h5>
             <ul class="list-group list-group-flush" id="recent-uploads-list">
  <?php
  $recentUploads = $conn->query("
      SELECT fileName, MAX(date_submitted) AS date_submitted, status 
      FROM beneficiarylist 
      GROUP BY fileName 
      ORDER BY date_submitted DESC 
      LIMIT 5
  ");
  if ($recentUploads && $recentUploads->num_rows > 0) {
    while ($upload = $recentUploads->fetch_assoc()) {
      $badge = match ($upload['status']) {
        'completed' => 'success',
        'pending' => 'warning',
        'error' => 'danger',
        default => 'secondary'
      };
      echo "<li class='list-group-item d-flex justify-content-between align-items-start'>
              <div>
                <strong>" . htmlspecialchars($upload['fileName']) . "</strong><br>
                <small>" . date("M j, Y g:i A", strtotime($upload['date_submitted'])) . "</small>
              </div>
              <span class='badge bg-$badge mt-1 text-uppercase'>" . $upload['status'] . "</span>
            </li>";
    }
  } else {
    echo "<li class='list-group-item text-muted'>No recent uploads found.</li>";
  }
  ?>
</ul>

              </div>
            </div>
          </div>

          <!-- Cleansing Summary -->
          
<div class="col-lg-6">
  <div class="card shadow-sm">
    <div class="card-body">
      <h5 class="card-title">Cleansing Summary</h5>
      <?php
      // 1) Detect the correct "reason" column in duplicaterecord
      $reasonCol = null;
      $cRes = $conn->query("SHOW COLUMNS FROM duplicaterecord");
      if ($cRes) {
        while ($col = $cRes->fetch_assoc()) {
          $fname = strtolower($col['Field']);
          // common possibilities
          if (in_array($fname, ['flagged_reason','reason','flag_reason','remarks','note'])) {
            $reasonCol = $col['Field']; // preserve exact casing
            break;
          }
        }
        $cRes->free();
      }

      if (!$reasonCol) {
        // Fail fast with a clear message so you can check your schema.
        echo "<p class='text-danger mb-0'>Could not find a reason column in <code>duplicaterecord</code>. 
              Expected one of: <code>flagged_reason</code>, <code>reason</code>, <code>flag_reason</code>, <code>remarks</code>, <code>note</code>.</p>";
      } else {
        // 2) Build summary with flexible, case-insensitive matching
        $sql = "
          SELECT
            COUNT(*) AS total_issues,
            SUM(CASE WHEN LOWER($reasonCol) LIKE 'exact duplicate%' THEN 1 ELSE 0 END)      AS exact_cnt,
            SUM(CASE WHEN LOWER($reasonCol) LIKE 'possible duplicate%' THEN 1 ELSE 0 END)   AS possible_cnt,
            SUM(CASE 
                  WHEN LOWER($reasonCol) LIKE 'sounds-like%' 
                    OR LOWER($reasonCol) LIKE 'sounds like%' 
                    OR LOWER($reasonCol) LIKE 'phonetic%' 
                    OR LOWER($reasonCol) LIKE '%sound%like%' 
                  THEN 1 ELSE 0 END
            ) AS sound_cnt
          FROM duplicaterecord
        ";
        $summaryQuery = $conn->query($sql);

        if ($summaryQuery && $summary = $summaryQuery->fetch_assoc()):
      ?>
          <ul class="mb-0">
            <li><strong>Total Flagged Records:</strong> <?= (int)$summary['total_issues'] ?></li>
            <li><strong>Exact Duplicates:</strong> <?= (int)$summary['exact_cnt'] ?></li>
            <li><strong>Possible Duplicates:</strong> <?= (int)$summary['possible_cnt'] ?></li>
            <li><strong>Sounds-Like Duplicates:</strong> <?= (int)$summary['sound_cnt'] ?></li>
          </ul>
      <?php
        else:
          echo "<p class='text-muted mb-0'>No cleansing data available yet.</p>";
        endif;
      }
      ?>
    </div>
  </div>
</div>


        </div>
      </section>
    </div>
  </main>

  <?php include("./includes/footer.php"); ?>
