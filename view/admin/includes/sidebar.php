<!-- Sidebar -->
<aside id="sidebar" class="sidebar bg-white border-end shadow-sm">

  <ul class="sidebar-nav list-unstyled mb-0" id="sidebar-nav">

    <!-- Section: MAIN -->
    <li class="sidebar-section">Main</li>
    <li class="nav-item">
      <a href="dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">
        <i class="bi bi-house-door me-2"></i> Dashboard
      </a>
    </li>

    <!-- Section: Data Management -->
    <li class="sidebar-section mt-4">Data Management</li>
    <li class="nav-item">
      <a href="upload.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'upload.php' ? 'active' : '' ?>">
        <i class="bi bi-upload me-2"></i> Upload Data
      </a>
    </li>
    <li class="nav-item">
      <a href="clean.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'clean.php' ? 'active' : '' ?>">
        <i class="bi bi-upc-scan me-2"></i> Scan Data
      </a>
    </li>
    <li class="nav-item">
      <a href="review.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'review.php' ? 'active' : '' ?>">
        <i class="bi bi-flag me-2"></i> Review Issues
      </a>
    </li>

    <!-- Section: Settings -->
    <li class="sidebar-section mt-4">Settings</li>
    <li class="nav-item">
    
    </li>
    <li class="nav-item">
      <a href="user_profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'user_profile.php' ? 'active' : '' ?>">
        <i class="bi bi-person me-2"></i> My Profile
      </a>
    </li>
  </ul>
</aside>
