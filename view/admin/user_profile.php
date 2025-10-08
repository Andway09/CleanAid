<?php
session_start();
include("../../dB/config.php");
include("./includes/header.php");
include("./includes/topbar.php");
include("./includes/sidebar.php");

if (!isset($_SESSION['user_id'])) {
    echo "User not logged in.";
    exit;
}

$userId = $_SESSION['user_id'];

$query = "SELECT name, email, password FROM user WHERE user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
} else {
    echo "User not found.";
    exit;
}

// Handle password change form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $newPassword = trim($_POST['new_password']);

    // ✅ Validate password rules: at least 8 chars, start with capital, at least 1 number
    if (!preg_match('/^[A-Z](?=.*\d)[A-Za-z\d]{7,}$/', $newPassword)) {
        $error = "Password must be at least 8 characters, start with a capital letter, and contain at least one number.";
    } else {
        // ⚠️ Currently plain text storage (not secure!)
        $updateQuery = $conn->prepare("UPDATE user SET password = ? WHERE user_id = ?");
        $updateQuery->bind_param("si", $newPassword, $userId);

        if ($updateQuery->execute()) {
            $success = "Password updated successfully.";
            $user['password'] = $newPassword;
        } else {
            $error = "Failed to update password.";
        }
    }
}
?>

<main id="main" class="main">

  <div class="pagetitle">
    <h1>My Profile</h1>
    <nav>
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
        <li class="breadcrumb-item active">Profile</li>
      </ol>
    </nav>
  </div>

  <section class="profile">
    <div class="card">
      <div class="card-body pt-4">
        <h5 class="card-title">User Information</h5>

        <?php if (isset($error)): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php elseif (isset($success)): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <!-- User Info -->
        <form>
          <div class="row mb-3">
            <label class="col-md-4 col-lg-3 col-form-label">Full Name</label>
            <div class="col-md-8 col-lg-9">
              <input type="text" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" disabled>
            </div>
          </div>

          <div class="row mb-3">
            <label class="col-md-4 col-lg-3 col-form-label">Email</label>
            <div class="col-md-8 col-lg-9">
              <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled>
            </div>
          </div>

          <div class="row mb-3">
            <label class="col-md-4 col-lg-3 col-form-label">Password</label>
            <div class="col-md-8 col-lg-9 input-group">
              <input type="password" class="form-control" id="viewPassword" value="<?= htmlspecialchars($user['password']) ?>" disabled>
              <button type="button" class="btn btn-outline-secondary" onclick="togglePassword()" title="Show/Hide Password">
                <i class="bi bi-eye" id="toggleIcon"></i>
              </button>
            </div>
          </div>
        </form>

        <hr>

        <!-- Change Password -->
        <h5 class="card-title">Change Password</h5>
        <form method="POST">
          <div class="row mb-1">
            <label for="new_password" class="col-md-4 col-lg-3 col-form-label">New Password</label>
            <div class="col-md-8 col-lg-9">
              <div class="input-group">
                <input type="password" 
                       class="form-control" 
                       id="new_password" 
                       name="new_password" 
                       required
                       pattern="^[A-Z](?=.*\d)[A-Za-z\d]{7,}$"
                       title="Password must be at least 8 characters, start with a capital letter, and contain at least one number.">
                <button type="button" class="btn btn-outline-secondary" onclick="toggleNewPassword()" title="Show/Hide New Password">
                  <i class="bi bi-eye" id="newToggleIcon"></i>
                </button>
              </div>

              <!-- ✅ Password Rule Note Below Input -->
              <small class="text-muted d-block mt-1">
                <i class="bi bi-info-circle me-1"></i>
                Password must be at least <strong>8 characters long</strong>, 
                start with a <strong>capital letter</strong>, 
                and contain at least <strong>one number</strong>. 
                <span class="text-danger">No special characters allowed.</span>
              </small>
            </div>
          </div>

          <div class="text-end mt-3">
            <button type="submit" class="btn btn-primary">Update Password</button>
          </div>
        </form>

      </div>
    </div>
  </section>

</main>

<script>
  function togglePassword() {
    const pass = document.getElementById('viewPassword');
    const icon = document.getElementById('toggleIcon');
    if (pass.type === 'password') {
      pass.type = 'text';
      icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
      pass.type = 'password';
      icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
  }

  function toggleNewPassword() {
    const newPass = document.getElementById('new_password');
    const newIcon = document.getElementById('newToggleIcon');
    if (newPass.type === 'password') {
      newPass.type = 'text';
      newIcon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
      newPass.type = 'password';
      newIcon.classList.replace('bi-eye-slash', 'bi-eye');
    }
  }
</script>

<?php include("./includes/footer.php"); ?>
