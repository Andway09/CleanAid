<?php
session_start();
include __DIR__ . '/../dB/config.php';

if (!isset($_GET['token']) || empty($_GET['token'])) {
    $_SESSION['message'] = 'Reset token is missing.';
    $_SESSION['code'] = 'error';
    header('Location: ../login.php');
    exit();
}

$token = $_GET['token'];

$stmt = $conn->prepare("SELECT * FROM user WHERE reset_token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user || strtotime($user['token_expiry']) < time()) {
    $_SESSION['message'] = 'Invalid or expired reset token.';
    $_SESSION['code'] = 'error';
    header('Location: ../login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (empty($new_password) || empty($confirm_password)) {
        $_SESSION['message'] = 'Both password fields are required.';
        $_SESSION['code'] = 'error';
    } elseif ($new_password !== $confirm_password) {
        $_SESSION['message'] = 'Passwords do not match.';
        $_SESSION['code'] = 'error';
    } else {
        // ⚠️ NOTE: Store password as plain text (NOT RECOMMENDED)
        // ✅ Ideally, use password_hash($new_password, PASSWORD_DEFAULT)
        $update = $conn->prepare("UPDATE user SET password = ?, reset_token = NULL, token_expiry = NULL WHERE user_id = ?");
        $update->bind_param("si", $new_password, $user['user_id']);
        $update->execute();

        $_SESSION['message'] = 'Password has been successfully updated.';
        $_SESSION['code'] = 'success';
        header('Location: ../login.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reset Password | CleanAid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="../assets/img/logo.png" rel="icon">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    body {
      background: url('../assets/img/bg-login.png') no-repeat center center fixed;
      background-size: cover;
      display: flex;
      align-items: center;
      justify-content: center;
      height: 100vh;
      margin: 0;
      font-family: "Poppins", Arial, sans-serif;
    }

    .card {
      padding: 35px 30px;
      background-color: #fff;
      border-radius: 14px;
      box-shadow: 0 4px 18px rgba(0, 0, 0, 0.1);
      max-width: 380px;
      width: 100%;
      text-align: center;
    }

    /* ✅ Centered logo */
    .card img {
      display: block;
      margin: 0 auto 20px auto;
      width: 85px;
    }

    .card h4 {
      font-weight: 700;
      color: #222;
      margin-bottom: 20px;
    }

    .form-control {
      padding-right: 40px;
    }

    .position-relative i {
      cursor: pointer;
      color: #888;
    }

    /* ✅ Password rule note (gray info icon) */
    .password-note {
      font-size: 13px;
      color: #6c757d;
      text-align: left;
      margin-top: 6px;
      margin-bottom: 14px;
      line-height: 1.5;
    }

    .password-note i {
      color: #6c757d; /* gray, not blue */
      margin-right: 5px;
    }

    .password-note strong {
      color: #000;
    }

    .btn-danger {
      font-weight: 600;
      padding: 10px;
      border-radius: 8px;
    }

    .card a {
      font-size: 14px;
      color: #0d6efd;
      text-decoration: none;
    }

    .card a:hover {
      text-decoration: underline;
    }
  </style>
</head>
<body>

  <div class="card">
    <img src="../assets/img/logo.png" alt="CleanAid Logo">
    <h4>Set New Password</h4>

    <form method="POST" novalidate>
      <!-- New Password -->
      <div class="mb-2 position-relative">
        <input 
          type="password" 
          name="new_password" 
          id="new_password" 
          class="form-control" 
          placeholder="New Password" 
          required
          pattern="^(?=.*[A-Z])(?=.*[0-9])[A-Za-z0-9]{8,}$"
          title="Password must be at least 8 characters long, contain an uppercase letter and a number, and no special characters.">
        <i class="bi bi-eye-slash position-absolute top-50 end-0 translate-middle-y me-3" id="toggleNewPassword"></i>
      </div>

      <!-- ✅ Password Rule Note -->
      <div class="password-note">
        <i class="bi bi-info-circle"></i>
        Password must be at least <strong>8 characters</strong> long, contain at least one 
        <strong>uppercase letter</strong> and one <strong>number</strong>, 
        <span class="text-danger">no special characters allowed.</span>
      </div>

      <!-- Confirm Password -->
      <div class="mb-3 position-relative">
        <input 
          type="password" 
          name="confirm_password" 
          id="confirm_password" 
          class="form-control" 
          placeholder="Confirm Password" 
          required
          pattern="^(?=.*[A-Z])(?=.*[0-9])[A-Za-z0-9]{8,}$"
          title="Password must be at least 8 characters long, contain an uppercase letter and a number, and no special characters.">
        <i class="bi bi-eye-slash position-absolute top-50 end-0 translate-middle-y me-3" id="toggleConfirmPassword"></i>
      </div>

      <div class="d-grid mt-3">
        <button type="submit" class="btn btn-danger">Reset Password</button>
      </div>
    </form>

    <div class="mt-3">
      <a href="../login.php"><i class="bi bi-arrow-left"></i> Back to Login</a>
    </div>
  </div>

  <!-- ✅ Show / Hide Password Script -->
  <script>
    const toggleNewPassword = document.querySelector('#toggleNewPassword');
    const newPassword = document.querySelector('#new_password');
    const toggleConfirmPassword = document.querySelector('#toggleConfirmPassword');
    const confirmPassword = document.querySelector('#confirm_password');

    toggleNewPassword.addEventListener('click', function () {
      const type = newPassword.getAttribute('type') === 'password' ? 'text' : 'password';
      newPassword.setAttribute('type', type);
      this.classList.toggle('bi-eye');
      this.classList.toggle('bi-eye-slash');
    });

    toggleConfirmPassword.addEventListener('click', function () {
      const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
      confirmPassword.setAttribute('type', type);
      this.classList.toggle('bi-eye');
      this.classList.toggle('bi-eye-slash');
    });
  </script>

  <?php if (isset($_SESSION['message']) && $_SESSION['code']): ?>
  <script>
    Swal.fire({
      icon: '<?= $_SESSION['code'] ?>',
      title: '<?= $_SESSION['message'] ?>',
      toast: true,
      position: 'top-end',
      showConfirmButton: false,
      timer: 3000,
      timerProgressBar: true
    });
  </script>
  <?php unset($_SESSION['message'], $_SESSION['code']); endif; ?>

</body>
</html>
