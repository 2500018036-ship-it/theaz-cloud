(function () {
  'use strict';
  function t(text) { return window.CloudApp ? window.CloudApp.t(text) : text; }

  var CHUNK_SIZE = 2 * 1024 * 1024; // Must match Config\CloudDrive::chunkSizeBytes

  // ---- Helpers ----

  function formatBytes(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' B';
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  function getCSRF() {
    var input = document.querySelector('#upload-form input[name^="csrf"]');
    if (input) return { name: input.name, hash: input.value };
    return { name: '', hash: '' };
  }

  function updateCSRF(name, hash) {
    if (!name || !hash) return;
    var inputs = document.querySelectorAll('input[name="' + name + '"]');
    for (var i = 0; i < inputs.length; i++) inputs[i].value = hash;
  }

  function postJSON(url, formData) {
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, 600000);
    return fetch(url, {
      signal: controller.signal,
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (res) {
      return res.json().then(function (data) {
        if (data.csrf_name && data.csrf_hash) updateCSRF(data.csrf_name, data.csrf_hash);
        if (!data.success) throw new Error(data.error || 'Request failed');
        return data;
      });
    }).finally(function () { clearTimeout(timer); });
  }

  function appendCSRF(fd) {
    var csrf = getCSRF();
    if (csrf.name) fd.append(csrf.name, csrf.hash);
  }

  // ---- Status UI helpers ----

  var statusLabels = {
    pending: 'Pending', initializing: 'Initializing…', uploading: 'Uploading…',
    assembling: 'Processing…', completed: 'Completed', failed: 'Failed', cancelled: 'Cancelled'
  };
  var statusClasses = {
    pending: 'bg-light-secondary', initializing: 'bg-light-info', uploading: 'bg-light-primary',
    assembling: 'bg-light-info', completed: 'bg-light-success', failed: 'bg-light-danger', cancelled: 'bg-light-warning'
  };

  function setStatus(el, status, detail) {
    var badge = el.querySelector('.upload-status');
    if (!badge) return;
    badge.className = 'badge upload-status ' + (statusClasses[status] || 'bg-light-secondary');
    badge.textContent = t(statusLabels[status] || status);
    if (detail) badge.title = detail;
  }

  function setProgress(el, pct) {
    var bar = el.querySelector('.progress-bar');
    if (bar) bar.style.width = Math.min(100, pct) + '%';
  }

  function toggleBtn(el, cls, show) {
    var btn = el.querySelector(cls);
    if (btn) btn.classList.toggle('d-none', !show);
  }

  // ---- Simple (single-request) upload ----

  function uploadSimple(file, parentId, el, onDone) {
    var fd = new FormData();
    fd.append('files[]', file);
    fd.append('parent_id', parentId);
    appendCSRF(fd);

    var xhr = new XMLHttpRequest();
    el._xhr = xhr;

    setStatus(el, 'uploading');
    toggleBtn(el, '.upload-cancel', true);

    xhr.timeout = 600000;
    xhr.open('POST', window.driveBaseUrl + '/drive/upload');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.withCredentials = true;

    xhr.upload.addEventListener('progress', function (e) {
      if (e.lengthComputable) setProgress(el, Math.round((e.loaded / e.total) * 100));
    });

    xhr.addEventListener('load', function () {
      toggleBtn(el, '.upload-cancel', false);
      var resultDocument = new DOMParser().parseFromString(xhr.responseText, 'text/html');
      window.CloudApp?.token(xhr.getResponseHeader('X-CSRF-TOKEN'));
      var failed = resultDocument.querySelector('.alert-danger') || /\/login\/?$/.test(new URL(xhr.responseURL || window.location.href).pathname);
      if (xhr.status >= 200 && xhr.status < 400 && !failed) {
        setStatus(el, 'completed');
        setProgress(el, 100);
        // Try to extract CSRF from HTML response
        try {
          var doc = new DOMParser().parseFromString(xhr.responseText, 'text/html');
          var ci = doc.querySelector('input[name^="csrf"]');
          if (ci) updateCSRF(ci.name, ci.value);
        } catch (e) { /* ignore */ }
      } else {
        setStatus(el, 'failed');
        toggleBtn(el, '.upload-retry', true);
        window.CloudApp?.toast(failed?.textContent?.trim() || 'The action could not be completed.', true);
      }
      onDone();
    });

    xhr.addEventListener('error', function () {
      toggleBtn(el, '.upload-cancel', false);
      setStatus(el, 'failed');
      toggleBtn(el, '.upload-retry', true);
      onDone();
    });

    xhr.addEventListener('timeout', function () { toggleBtn(el, '.upload-cancel', false); setStatus(el, 'failed', t('The request timed out. Please try again.')); toggleBtn(el, '.upload-retry', true); onDone(); });
    xhr.addEventListener('abort', function () {
      setStatus(el, 'cancelled');
      toggleBtn(el, '.upload-cancel', false);
      toggleBtn(el, '.upload-retry', true);
      onDone();
    });

    // Bind cancel
    el.querySelector('.upload-cancel').onclick = function () { xhr.abort(); };

    // Bind retry
    el.querySelector('.upload-retry').onclick = function () {
      toggleBtn(el, '.upload-retry', false);
      setProgress(el, 0);
      uploadSimple(file, parentId, el, onDone);
    };

    xhr.send(fd);
  }

  // ---- Chunked upload ----

  function uploadChunked(file, parentId, el, onDone) {
    setStatus(el, 'initializing');

    var fd = new FormData();
    fd.append('file_name', file.name);
    fd.append('file_size', file.size);
    fd.append('parent_id', parentId);
    fd.append('mime_type', file.type || '');
    appendCSRF(fd);

    postJSON(window.driveBaseUrl + '/drive/upload/init', fd)
      .then(function (data) {
        sendChunks(file, data.upload_id, data.total_chunks, data.chunk_size, parentId, el, onDone);
      })
      .catch(function (err) {
        setStatus(el, 'failed', err.message);
        toggleBtn(el, '.upload-retry', true);
        el.querySelector('.upload-retry').onclick = function () {
          toggleBtn(el, '.upload-retry', false);
          setProgress(el, 0);
          uploadChunked(file, parentId, el, onDone);
        };
        onDone();
      });
  }

  function sendChunks(file, uploadId, totalChunks, chunkSize, parentId, el, onDone) {
    var cancelled = false;
    var currentChunk = 0;

    setStatus(el, 'uploading');
    toggleBtn(el, '.upload-cancel', true);

    el.querySelector('.upload-cancel').onclick = function () {
      cancelled = true;
      toggleBtn(el, '.upload-cancel', false);
      setStatus(el, 'cancelled');
      toggleBtn(el, '.upload-retry', true);

      // Best-effort cancel on server
      var cfd = new FormData();
      cfd.append('upload_id', uploadId);
      appendCSRF(cfd);
      postJSON(window.driveBaseUrl + '/drive/upload/cancel', cfd).catch(function () {});

      el.querySelector('.upload-retry').onclick = function () {
        toggleBtn(el, '.upload-retry', false);
        setProgress(el, 0);
        uploadChunked(file, parentId, el, onDone);
      };
      onDone();
    };

    function next() {
      if (cancelled) return;
      if (currentChunk >= totalChunks) {
        completeUpload(uploadId, el, onDone);
        return;
      }

      var start = currentChunk * chunkSize;
      var end = Math.min(start + chunkSize, file.size);
      var blob = file.slice(start, end);

      var cfd = new FormData();
      cfd.append('upload_id', uploadId);
      cfd.append('chunk_index', currentChunk);
      cfd.append('chunk', blob, 'chunk');
      appendCSRF(cfd);

      postJSON(window.driveBaseUrl + '/drive/upload/chunk', cfd)
        .then(function () {
          currentChunk++;
          setProgress(el, Math.round((currentChunk / totalChunks) * 100));
          next();
        })
        .catch(function (err) {
          if (cancelled) return;
          setStatus(el, 'failed', err.message);
          toggleBtn(el, '.upload-cancel', false);
          toggleBtn(el, '.upload-retry', true);
          // Retry resumes from current chunk
          el.querySelector('.upload-retry').onclick = function () {
            toggleBtn(el, '.upload-retry', false);
            setStatus(el, 'uploading');
            toggleBtn(el, '.upload-cancel', true);
            next();
          };
        });
    }

    next();
  }

  function completeUpload(uploadId, el, onDone) {
    setStatus(el, 'assembling');
    toggleBtn(el, '.upload-cancel', false);

    var fd = new FormData();
    fd.append('upload_id', uploadId);
    appendCSRF(fd);

    postJSON(window.driveBaseUrl + '/drive/upload/complete', fd)
      .then(function () {
        setStatus(el, 'completed');
        setProgress(el, 100);
        onDone();
      })
      .catch(function (err) {
        setStatus(el, 'failed', err.message);
        toggleBtn(el, '.upload-retry', true);
        el.querySelector('.upload-retry').onclick = function () {
          toggleBtn(el, '.upload-retry', false);
          completeUpload(uploadId, el, onDone);
        };
        onDone();
      });
  }

  // ---- File list UI ----

  function buildFileItem(file, index) {
    var div = document.createElement('div');
    div.className = 'upload-file-item border rounded p-2 mb-2';
    div.dataset.fileIndex = index;
    div.innerHTML =
      '<div class="d-flex align-items-center justify-content-between mb-1">' +
        '<div class="text-truncate fw-semibold small me-2">' + escapeHtml(file.name) + '</div>' +
        '<div class="d-flex align-items-center gap-1 flex-shrink-0">' +
          '<span class="badge bg-light-secondary upload-status">Pending</span>' +
          '<button type="button" class="btn btn-sm p-0 px-1 btn-light-danger upload-cancel d-none" title="Cancel"><i class="ti ti-x f-14"></i></button>' +
          '<button type="button" class="btn btn-sm p-0 px-1 btn-light-warning upload-retry d-none" title="Retry"><i class="ti ti-refresh f-14"></i></button>' +
        '</div>' +
      '</div>' +
      '<div class="progress" style="height:4px"><div class="progress-bar" style="width:0%"></div></div>' +
      '<div class="text-muted small mt-1">' + formatBytes(file.size) + '</div>';
    return div;
  }

  // ---- Main wiring ----

  function initUploadManager() {
    var fileInput = document.getElementById('drive-files');
    var fileList = document.getElementById('upload-file-list');
    var form = document.getElementById('upload-form');
    if (!fileInput || !form) return;

    // Mark form as managed so drive-ui.js skips it
    form.dataset.managed = 'true';

    // Build file list on selection
    fileInput.addEventListener('change', function () {
      if (!fileList) return;
      fileList.innerHTML = '';
      if (fileInput.files.length === 0) { fileList.classList.add('d-none'); return; }
      fileList.classList.remove('d-none');
      for (var i = 0; i < fileInput.files.length; i++) {
        fileList.appendChild(buildFileItem(fileInput.files[i], i));
      }
    });

    // Override submit
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();

      if (!fileInput.files || fileInput.files.length === 0) return;

      var parentInput = form.querySelector('input[name="parent_id"]');
      var parentId = parentInput ? parentInput.value : '';
      var submitBtn = document.getElementById('upload-submit');
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Uploading…'; }

      var items = fileList ? fileList.querySelectorAll('.upload-file-item') : [];
      var queue = [];
      for (var i = 0; i < fileInput.files.length; i++) {
        queue.push({ file: fileInput.files[i], el: items[i] });
      }

      var completed = 0;
      var total = queue.length;

      function onFileDone() {
        completed++;
        if (completed >= total) {
          if (submitBtn) { submitBtn.textContent = t('Done'); submitBtn.disabled = false; }
          // Refresh drive content after short delay.
          setTimeout(function () {
            if (typeof window.driveRefresh === 'function') {
              window.driveRefresh();
            } else {
              window.location.href = window.location.href.split('#')[0];
            }
          }, 600);
        }
      }

      // Upload sequentially to avoid CSRF conflicts
      function processQueue(idx) {
        if (idx >= queue.length) return;
        var entry = queue[idx];
        var afterThis = function () {
          onFileDone();
          processQueue(idx + 1);
        };
        if (entry.file.size > CHUNK_SIZE) {
          uploadChunked(entry.file, parentId, entry.el, afterThis);
        } else {
          uploadSimple(entry.file, parentId, entry.el, afterThis);
        }
      }

      processQueue(0);
    });
  }

  // Set base URL for fetch calls
  window.driveBaseUrl = (function () {
    var base = document.querySelector('base');
    if (base && base.href) return base.href.replace(/\/$/, '');
    return window.location.origin;
  })();

  // Init on DOMContentLoaded and after SPA navigation
  document.addEventListener('DOMContentLoaded', initUploadManager);

  // Re-init when drive-ui.js calls initDrivePage after SPA navigation
  var origInit = window._drivePageInit;
  window._drivePageInit = function () {
    if (typeof origInit === 'function') origInit();
    initUploadManager();
  };
})();
