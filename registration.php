<?php 
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">

  <title>Register | CleanAid</title>

  <!-- Favicons -->
  <link href="assets/img/logo.png" rel="icon">

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css?family=Open+Sans|Nunito|Poppins" rel="stylesheet">

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

  <!-- Custom CSS -->
  <link href="assets/css/style.css" rel="stylesheet">
</head>

<body class="d-flex align-items-center justify-content-center min-vh-100">

  <div class="d-flex register-card flex-md-row flex-column shadow rounded overflow-hidden">
    <!-- Left Section -->
    <div class="left col-md-6 text-center bg-light d-flex align-items-center justify-content-center p-4">
      <img src="assets/img/logo.png" alt="CleanAid Logo" class="img-fluid" style="max-height: 200px;">
    </div>

    <!-- Right Section -->
    <div class="right col-md-6 p-4">
      <h3 class="fw-bold text-center mb-4">Sign up</h3>
      <form action="./controller/registration.php" method="POST" class="needs-validation" novalidate>
        
        <!-- Name -->
        <div class="mb-3">
          <input 
            type="text" 
            class="form-control" 
            name="name" 
            placeholder="Full Name" 
            required
            value="<?php echo isset($_SESSION['old_name']) ? htmlspecialchars($_SESSION['old_name']) : ''; ?>">
          <div class="invalid-feedback">Please enter your full name.</div>
        </div>

        <!-- Email -->
        <div class="mb-3">
          <input 
            type="email" 
            class="form-control" 
            name="email" 
            placeholder="Email" 
            required
            value="<?php echo isset($_SESSION['old_email']) ? htmlspecialchars($_SESSION['old_email']) : ''; ?>">
          <div class="invalid-feedback">Please enter a valid email address.</div>
        </div>

        <!-- Password -->
        <div class="mb-2 position-relative">
          <input 
            type="password" 
            class="form-control" 
            id="password" 
            name="password" 
            placeholder="Password" 
            required
            pattern="^(?=.*[A-Z])(?=.*[0-9])[A-Za-z0-9]{8,}$"
            title="Password must be at least 8 characters long, contain an uppercase letter and a number, and no special characters."
            value="<?php echo isset($_SESSION['old_password']) ? htmlspecialchars($_SESSION['old_password']) : ''; ?>">
          <i class="bi bi-eye-slash position-absolute top-50 end-0 translate-middle-y me-3" 
             id="togglePassword" style="cursor: pointer;"></i>
          <div class="invalid-feedback">Please enter a valid password.</div>
        </div>

        <!-- ✅ Password Rule Note -->
        <p class="small text-muted mb-3" style="margin-top: -4px;">
          <i class="bi bi-info-circle"></i> 
          Password must be at least <strong>8 characters</strong> long, contain at least one 
          <strong>uppercase letter</strong> and one <strong>number</strong>, 
          <span class="text-danger">no special characters allowed</span>.
        </p>

        <!-- Confirm Password -->
        <div class="mb-3 position-relative">
          <input 
            type="password" 
            class="form-control" 
            id="cpassword" 
            name="cpassword" 
            placeholder="Confirm Password" 
            required
            pattern="^(?=.*[A-Z])(?=.*[0-9])[A-Za-z0-9]{8,}$"
            title="Password must be at least 8 characters long, contain an uppercase letter and a number, and no special characters."
            value="<?php echo isset($_SESSION['old_cpassword']) ? htmlspecialchars($_SESSION['old_cpassword']) : ''; ?>">
          <i class="bi bi-eye-slash position-absolute top-50 end-0 translate-middle-y me-3" 
             id="toggleCPassword" style="cursor: pointer;"></i>
          <div class="invalid-feedback">Please confirm your password.</div>
        </div>

        <!-- Submit -->
        <div class="d-grid">
          <button type="submit" class="btn btn-danger" name="registration">Sign up</button>
        </div>

        <p class="text-center mt-3 small">
          Already have an account?
          <a href="./login.php">Login</a>
        </p>
      </form>
    </div>
  </div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <!-- ✅ Show/Hide Password Script -->
  <script>
    const togglePassword = document.querySelector('#togglePassword');
    const password = document.querySelector('#password');
    const toggleCPassword = document.querySelector('#toggleCPassword');
    const cpassword = document.querySelector('#cpassword');

    togglePassword.addEventListener('click', function () {
      const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
      password.setAttribute('type', type);
      this.classList.toggle('bi-eye');
      this.classList.toggle('bi-eye-slash');
    });

    toggleCPassword.addEventListener('click', function () {
      const type = cpassword.getAttribute('type') === 'password' ? 'text' : 'password';
      cpassword.setAttribute('type', type);
      this.classList.toggle('bi-eye');
      this.classList.toggle('bi-eye-slash');
    });
  </script>

  <?php if (isset($_SESSION['message']) && $_SESSION['code'] != ''): ?>
    <script>
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: '<?php echo $_SESSION['code']; ?>',
        title: '<?php echo $_SESSION['message']; ?>',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
    </script>
    <?php
    unset($_SESSION['message'], $_SESSION['code']);
    ?>
  <?php endif; ?>

</body>
</html>

<?php
// ✅ Clear old values AFTER displaying them
unset($_SESSION['old_name'], $_SESSION['old_email'], $_SESSION['old_password'], $_SESSION['old_cpassword']);
?>
