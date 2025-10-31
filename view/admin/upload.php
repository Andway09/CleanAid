  <?php
  session_start();

  // ✅ Enforce login (adjust to your auth flow)
  if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
  }

  // ✅ CSRF token
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }

  include("./includes/header.php");
  include("./includes/topbar.php");
  include("./includes/sidebar.php");
  ?>

  <main class="main bg-body-tertiary" style="min-height: 100vh;">
    <section class="container py-5">
      <h2 class="fw-bold">Data Upload</h2>
      <p class="text-muted">Upload your file/s for beneficiary data processing</p>

      <form id="uploadForm" autocomplete="off">
        <!-- CSRF (sent via JS/Fetch) -->
        <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

        <!-- Dropzone -->
        <div id="dropZone" class="border rounded-4 text-center p-5 shadow-sm"
            style="border: 2px dashed #ccc; cursor: pointer;">
          <input type="file" id="fileInput" name="file[]" accept=".csv,.xls,.xlsx" multiple hidden>
          <div class="display-1 text-muted">+</div>
          <p class="text-muted">Click or drag & drop file/s here</p>
        </div>

        <!-- Preview Container -->
        <div id="previewContainer" class="file-preview-row mt-4" style="display: none;"></div>

        <div class="text-center mt-4">
          <button type="submit" class="btn btn-primary px-5" id="uploadBtn" disabled>Start Upload</button>
        </div>
      </form>
    </section>
  </main>

  <!-- Fullscreen Loading Overlay -->
  <div id="loadingOverlay">
    <div class="loader-container">
      <div class="spinner"></div>
      <p class="loading-text">Preparing upload...</p>
      <div class="progress-wrapper">
        <div id="progressBar"></div>
      </div>
      <p id="progressPercent">0%</p>
    </div>
  </div>

  <script>
  const MAX_PER_FILE_MB = 50;
  const MAX_FILES = 20;
  const ALLOWED_EXTS = ['csv', 'xls', 'xlsx'];
  const BYTES_PER_MB = 1024 * 1024;

  const dropZone = document.getElementById('dropZone');
  const fileInput = document.getElementById('fileInput');
  const previewContainer = document.getElementById('previewContainer');
  const uploadBtn = document.getElementById('uploadBtn');
  const loadingOverlay = document.getElementById('loadingOverlay');
  const progressBar = document.getElementById('progressBar');
  const progressPercent = document.getElementById('progressPercent');
  const loadingText = document.querySelector('.loading-text');
  const csrfToken = document.getElementById('csrfToken').value;

  let selectedFiles = [];

  function extOf(name) { return (name.split('.').pop() || '').toLowerCase(); }

  function validateFiles(files) {
    const errors = [];
    if (files.length > MAX_FILES) errors.push(`Too many files (max ${MAX_FILES}).`);
    for (const f of files) {
      const ext = extOf(f.name);
      if (!ALLOWED_EXTS.includes(ext)) errors.push(`${f.name}: invalid type.`);
      if (f.size > MAX_PER_FILE_MB * BYTES_PER_MB) errors.push(`${f.name}: too large.`);
    }
    return errors;
  }

  function showErrors(errors) {
    if (errors.length) alert(errors.join("\n"));
  }

  dropZone.addEventListener('click', () => fileInput.click());
  dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('bg-light'); });
  dropZone.addEventListener('dragleave', e => { e.preventDefault(); dropZone.classList.remove('bg-light'); });
  dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('bg-light');
    const files = Array.from(e.dataTransfer.files);
    const errors = validateFiles(files);
    if (errors.length) return showErrors(errors);
    selectedFiles = [...selectedFiles, ...files];
    renderFilePreview();
  });

  fileInput.addEventListener('change', () => {
    const files = Array.from(fileInput.files);
    const errors = validateFiles(files);
    if (errors.length) return showErrors(errors);
    selectedFiles = [...selectedFiles, ...files];
    renderFilePreview();
  });

  document.getElementById('uploadForm').addEventListener('submit', e => {
    e.preventDefault();
    if (selectedFiles.length === 0) return alert("❌ No files selected.");

    loadingOverlay.style.display = 'flex';
    progressBar.style.width = '0%';
    progressPercent.innerText = '0%';
    loadingText.innerText = 'Uploading...';

    const formData = new FormData();
    selectedFiles.forEach(f => formData.append('file[]', f));
    formData.append('csrf_token', csrfToken);

    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', e => {
      if (e.lengthComputable) {
        let percent = Math.round((e.loaded / e.total) * 50); // cap at 90% until backend finishes
        progressBar.style.width = percent + '%';
        progressPercent.innerText = percent + '%';
      }
    });

    // Simulated backend processing progress (after upload complete)
    function simulateProcessingProgress() {
      let current = parseInt(progressPercent.innerText);
      const timer = setInterval(() => {
        if (current >= 98) return clearInterval(timer);
        current++;
        progressBar.style.width = current + '%';
        progressPercent.innerText = current + '%';
      }, 500);
      return timer;
    }

    let processingTimer = null;

    xhr.onreadystatechange = function () {
      if (xhr.readyState === XMLHttpRequest.LOADING && !processingTimer) {
        // Upload complete, waiting for server processing
        loadingText.innerText = 'Processing on server...';
        processingTimer = simulateProcessingProgress();
      }
    };

    xhr.onload = function () {
      clearInterval(processingTimer);
      try {
        const resp = JSON.parse(xhr.responseText || '{}');
        if (xhr.status === 200 && resp.ok) {
          progressBar.style.width = '100%';
          progressPercent.innerText = '100%';
          loadingText.innerText = "✅ Upload complete! Redirecting...";
          setTimeout(() => window.location.href = resp.redirect || "../admin/clean.php", 800);
        } else {
          throw new Error(resp.error || "Upload failed");
        }
      } catch (err) {
        alert("❌ " + err.message);
        loadingOverlay.style.display = 'none';
      }
    };

    xhr.onerror = function () {
      clearInterval(processingTimer);
      alert("⚠️ Upload error.");
      loadingOverlay.style.display = 'none';
    };

    xhr.open('POST', '../../controller/upload_process.php', true);
    xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    xhr.send(formData);
  });

  function renderFilePreview() {
    uploadBtn.disabled = selectedFiles.length === 0;
    previewContainer.style.display = selectedFiles.length ? 'flex' : 'none';
    dropZone.style.display = selectedFiles.length ? 'none' : 'block';
    previewContainer.innerHTML = '';
    selectedFiles.forEach((file, i) => {
      const div = document.createElement('div');
      div.className = 'file-card';
      div.innerHTML = `
        <img src="${getFileIcon(extOf(file.name))}" alt="">
        <div>${file.name}</div>
        <button class="remove-btn" onclick="removeFile(${i})">&times;</button>
      `;
      previewContainer.appendChild(div);
    });
  }

  window.removeFile = function (i) {
    selectedFiles.splice(i, 1);
    renderFilePreview();
  };

  function getFileIcon(ext) {
    switch (ext) {
      case 'csv': return 'https://cdn-icons-png.flaticon.com/512/9496/9496460.png';
      case 'xls': return 'https://cdn-icons-png.flaticon.com/512/9496/9496456.png';
      case 'xlsx': return 'https://cdn-icons-png.flaticon.com/512/9496/9496502.png';
      default: return 'https://cdn-icons-png.flaticon.com/512/2991/2991122.png';
    }
  }
</script>

  <style>
    /* File preview styles */
    .file-preview-row {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
      justify-content: start;
      padding: 20px;
      border: 1px solid #ccc;
      border-radius: 15px;
      background: #fff;
    }
    .file-card {
      width: 140px;
      padding: 12px 10px;
      background: #eaf6ff;
      text-align: center;
      border-radius: 10px;
      position: relative;
    }
    .file-card img {
      width: 40px;
      height: 40px;
      margin-bottom: 6px;
    }
    .filename {
      font-size: 12px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .remove-btn {
      position: absolute;
      top: 4px;
      right: 6px;
      background: red;
      color: white;
      border: none;
      font-size: 14px;
      width: 20px;
      height: 20px;
      border-radius: 50%;
      cursor: pointer;
    }
    .add-btn-card {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 140px;
      height: 112px;
      background: #f0f0f0;
      border-radius: 10px;
      border: 2px dashed #ccc;
      font-size: 30px;
      color: #888;
      cursor: pointer;
    }
    .add-btn-card:hover {
      background: #e9e9e9;
    }

    /* Loading overlay styles */
    #loadingOverlay {
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
      border: 6px solid #ddd; border-top: 6px solid #007bff;
      border-radius: 50%; animation: spin 1s linear infinite; margin: auto;
    }
    .loading-text { margin: 15px 0; font-size: 18px; color: #333; font-weight: 500; }
    .progress-wrapper {
      width: 100%; height: 12px; background: #eee; border-radius: 8px; overflow: hidden; margin: 10px 0;
    }
    #progressBar {
      height: 100%; width: 0%; background: linear-gradient(90deg, #007bff, #00c6ff); transition: width 0.3s ease;
    }
    #progressPercent { font-size: 14px; font-weight: 600; color: #007bff; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>

  <?php include("./includes/footer.php"); ?>
