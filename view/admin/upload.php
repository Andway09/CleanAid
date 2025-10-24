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
  // ===== Config =====
  const MAX_PER_FILE_MB = 50;                     
  const MAX_FILES = 20;                           
  const ALLOWED_EXTS = ['csv','xls','xlsx'];      
  const BYTES_PER_MB = 1024 * 1024;

  // ===== Elements =====
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

  // ===== Helpers =====
  function extOf(name) { return (name.split('.').pop() || '').toLowerCase(); }

  function validateFiles(files) {
    const errors = [];
    if (files.length > MAX_FILES) {
      errors.push(`You selected ${files.length} files. Maximum allowed is ${MAX_FILES}.`);
    }
    const names = new Set();
    for (const f of files) {
      const ext = extOf(f.name);
      if (!ALLOWED_EXTS.includes(ext)) {
        errors.push(`${f.name}: unsupported type ".${ext}". Allowed: ${ALLOWED_EXTS.join(', ')}`);
      }
      if (f.size > MAX_PER_FILE_MB * BYTES_PER_MB) {
        errors.push(`${f.name}: exceeds ${MAX_PER_FILE_MB} MB.`);
      }
      const key = f.name.toLowerCase();
      if (names.has(key)) {
        errors.push(`${f.name}: duplicate file name in this batch.`);
      }
      names.add(key);
    }
    return errors;
  }

  function showErrors(errors) {
    if (!errors.length) return;
    alert('Upload blocked:\n\n' + errors.map(e => '• ' + e).join('\n'));
  }

  // ===== Dropzone interactions =====
  dropZone.addEventListener('click', () => fileInput.click());

  dropZone.addEventListener('dragover', e => {
    e.preventDefault();
    dropZone.classList.add('bg-light');
  });

  dropZone.addEventListener('dragleave', e => {
    e.preventDefault();
    dropZone.classList.remove('bg-light');
  });

  dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('bg-light');

    const incoming = Array.from(e.dataTransfer.files);
    const errors = validateFiles(incoming);
    if (errors.length) return showErrors(errors);

    selectedFiles = [...selectedFiles, ...incoming];
    renderFilePreview();
  });

  fileInput.addEventListener('change', () => {
    const incoming = Array.from(fileInput.files);
    const errors = validateFiles(incoming);
    if (errors.length) return showErrors(errors);

    selectedFiles = [...selectedFiles, ...incoming];
    renderFilePreview();
  });

  // ===== Submit =====
  document.getElementById('uploadForm').addEventListener('submit', function (e) {
    e.preventDefault();

    if (selectedFiles.length === 0) {
      alert("❌ No files selected.");
      return;
    }

    const errors = validateFiles(selectedFiles);
    if (errors.length) return showErrors(errors);

    // Show overlay
    loadingOverlay.style.display = 'flex';
    progressBar.style.width = '0%';
    progressPercent.innerText = '0%';
    loadingText.innerText = 'Uploading...';

    const formData = new FormData();
    selectedFiles.forEach(file => formData.append('file[]', file));
    formData.append('csrf_token', csrfToken);

    const xhr = new XMLHttpRequest();

    // --- Realistic progress ---
    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) {
        // Cap at 99% to reserve last part for server processing
        const percent = Math.min(Math.round((e.loaded / e.total) * 100), 99);
        progressBar.style.width = percent + "%";
        progressPercent.innerText = percent + "%";
      }
    });

    xhr.onloadstart = function() {
      loadingText.innerText = 'Uploading...';
    };

    xhr.onload = function () {
      // Simulate server processing visually
      loadingText.innerText = 'Processing on server...';
      progressBar.style.width = '100%';
      progressPercent.innerText = '100%';

      setTimeout(() => {
        try {
          const resp = JSON.parse(xhr.responseText || '{}');
          if (xhr.status === 200 && resp.ok) {
            loadingText.innerText = "✅ Upload complete! Redirecting...";
            setTimeout(() => {
              window.location.href = resp.redirect || "../admin/clean.php";
            }, 800);
          } else {
            const msg = resp.error || "Upload failed.";
            alert("❌ " + msg);
            loadingOverlay.style.display = 'none';
          }
        } catch (_e) {
          if (xhr.status === 200) {
            loadingText.innerText = "✅ Upload complete! Redirecting...";
            setTimeout(() => {
              window.location.href = "../admin/clean.php";
            }, 800);
          } else {
            alert("❌ Upload failed.");
            loadingOverlay.style.display = 'none';
          }
        }
      }, 500); // small delay for smooth UX
    };

    xhr.onerror = function () {
      alert("⚠️ Upload error.");
      loadingOverlay.style.display = 'none';
    };

    xhr.open('POST', '../../controller/upload_process.php', true);
    xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    xhr.send(formData);
  });

  // ===== Render preview =====
  function renderFilePreview() {
    const dt = new DataTransfer();
    selectedFiles.forEach(file => dt.items.add(file));
    fileInput.files = dt.files;

    uploadBtn.disabled = selectedFiles.length === 0;

    if (selectedFiles.length > 0) {
      dropZone.style.display = 'none';
      previewContainer.style.display = 'flex';
    } else {
      dropZone.style.display = 'block';
      previewContainer.style.display = 'none';
    }

    previewContainer.innerHTML = '';
    selectedFiles.forEach((file, index) => {
      const ext = extOf(file.name);
      const card = document.createElement('div');
      card.className = 'file-card';
      card.innerHTML = `
        <img src="${getFileIcon(ext)}" alt="${ext}" />
        <div class="filename" title="${file.name}">${file.name.length > 18 ? file.name.slice(0, 15) + '...' : file.name}</div>
        <div class="small text-muted">${(file.size / (1024*1024)).toFixed(2)} MB</div>
        <button class="remove-btn" aria-label="Remove" onclick="removeFile(${index})">&times;</button>
      `;
      previewContainer.appendChild(card);
    });

    const addCard = document.createElement('label');
    addCard.className = 'add-btn-card';
    addCard.innerHTML = '+';
    addCard.onclick = () => fileInput.click();
    previewContainer.appendChild(addCard);
  }

  // expose for inline onclick
  window.removeFile = function(index) {
    selectedFiles.splice(index, 1);
    renderFilePreview();
  }

  function getFileIcon(ext) {
    switch ((ext || '').toLowerCase()) {
      case 'csv':
        return 'https://cdn-icons-png.flaticon.com/512/9496/9496460.png';
      case 'xls':
        return 'https://cdn-icons-png.flaticon.com/512/9496/9496456.png';
      case 'xlsx':
        return 'https://cdn-icons-png.flaticon.com/512/9496/9496502.png';
      default:
        return 'https://cdn-icons-png.flaticon.com/512/2991/2991122.png';
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
