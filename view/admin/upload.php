<?php
session_start(); ?>

<?php include("./includes/header.php"); ?>
<?php include("./includes/topbar.php"); ?>
<?php include("./includes/sidebar.php"); ?>

<main class="main bg-body-tertiary" style="min-height: 100vh;">
  <section class="container py-5">
    <h2 class="fw-bold">Data Upload</h2>
    <p class="text-muted">Upload your file/s for beneficiary data processing</p>

    <form id="uploadForm">
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
  const dropZone = document.getElementById('dropZone');
  const fileInput = document.getElementById('fileInput');
  const previewContainer = document.getElementById('previewContainer');
  const uploadBtn = document.getElementById('uploadBtn');
  const loadingOverlay = document.getElementById('loadingOverlay');
  const progressBar = document.getElementById('progressBar');
  const progressPercent = document.getElementById('progressPercent');
  const loadingText = document.querySelector('.loading-text');

  let selectedFiles = [];

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
    selectedFiles = [...selectedFiles, ...Array.from(e.dataTransfer.files)];
    renderFilePreview();
  });

  fileInput.addEventListener('change', () => {
    selectedFiles = [...selectedFiles, ...Array.from(fileInput.files)];
    renderFilePreview();
  });

  document.getElementById('uploadForm').addEventListener('submit', function (e) {
    e.preventDefault();

    if (selectedFiles.length === 0) {
      alert("❌ No files selected.");
      return;
    }

    // Show loading overlay
    loadingOverlay.style.display = 'flex';
    progressBar.style.width = '0%';
    progressPercent.innerText = '0%';
    loadingText.innerText = 'Uploading...';

    const formData = new FormData();
    selectedFiles.forEach(file => formData.append('file[]', file));

    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) {
        const percent = Math.round((e.loaded / e.total) * 100);
        progressBar.style.width = percent + "%";
        progressPercent.innerText = percent + "%";
      }
    });

    xhr.onload = function () {
      if (xhr.status === 200) {
        loadingText.innerText = "✅ Upload complete! Redirecting...";
        setTimeout(() => {
          window.location.href = "../admin/clean.php";
        }, 1000);
      } else {
        alert("❌ Upload failed.");
        loadingOverlay.style.display = 'none';
      }
    };

    xhr.onerror = function () {
      alert("⚠️ Upload error.");
      loadingOverlay.style.display = 'none';
    };

    xhr.open('POST', '../../controller/upload_process.php', true);
    xhr.send(formData);
  });

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
      const ext = file.name.split('.').pop();
      const card = document.createElement('div');
      card.className = 'file-card';
      card.innerHTML = `
        <img src="${getFileIcon(ext)}" alt="${ext}" />
        <div class="filename" title="${file.name}">${file.name.slice(0, 15)}...</div>
        <button class="remove-btn" onclick="removeFile(${index})">&times;</button>
      `;
      previewContainer.appendChild(card);
    });

    const addCard = document.createElement('label');
    addCard.className = 'add-btn-card';
    addCard.innerHTML = '+';
    addCard.onclick = () => fileInput.click();
    previewContainer.appendChild(addCard);
  }

  function removeFile(index) {
    selectedFiles.splice(index, 1);
    renderFilePreview();
  }

  function getFileIcon(ext) {
    switch (ext.toLowerCase()) {
      case 'csv':
        return 'https://cdn-icons-png.flaticon.com/512/9496/9496460.png';
      case 'xls':
        return 'https://cdn-icons-png.flaticon.com/512/9496/9496456.png'
      case 'xlsx':
        return 'https://cdn-icons-png.flaticon.com/512/9496/9496502.png';
      default:
        return 'https://cdn-icons-png.flaticon.com/512/2991/2991122.png';
    }
  }
</script>

<style>
  /* File preview styles (same as before) */
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
    width: 100px;
    padding: 15px 10px;
    background: #e0f3e0;
    text-align: center;
    border-radius: 10px;
    position: relative;
  }
  .file-card img {
    width: 40px;
    height: 40px;
    margin-bottom: 5px;
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
    width: 100px;
    height: 80px;
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
  .loader-container {
    text-align: center;
    max-width: 400px;
    width: 100%;
  }
  .spinner {
    width: 60px;
    height: 60px;
    border: 6px solid #ddd;
    border-top: 6px solid #007bff;
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
  #progressBar {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #007bff, #00c6ff);
    transition: width 0.3s ease;
  }
  #progressPercent {
    font-size: 14px;
    font-weight: 600;
    color: #007bff;
  }
  @keyframes spin {
    to { transform: rotate(360deg); }
  }
</style>

<?php include("./includes/footer.php"); ?>
