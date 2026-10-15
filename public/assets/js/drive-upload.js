(function () {
  'use strict';
  var entries = [], queue = [], active = null, panel = null, collapsed = false, needsRefresh = false;
  var csrf = { name: '', hash: '' };
  var labels = {
    pending: 'Pending', initializing: 'Initializing…', uploading: 'Uploading…',
    assembling: 'Processing…', zipping: 'Creating ZIP…', extracting: 'Extracting…', completed: 'Completed', failed: 'Failed', cancelled: 'Cancelled'
  };
  function t(value) { return window.CloudApp ? window.CloudApp.t(value) : value; }
  function message(value, args) {
    return t(value).replace(/\{(\d+)\}/g, function (_, index) { return args[index]; });
  }
  function bytes(value) {
    if (value >= 1073741824) return (value / 1073741824).toFixed(2) + ' GB';
    if (value >= 1048576) return (value / 1048576).toFixed(2) + ' MB';
    if (value >= 1024) return (value / 1024).toFixed(2) + ' KB';
    return Math.round(value) + ' B';
  }
  function duration(value) {
    value = Math.max(1, Math.ceil(value));
    if (value >= 3600) return Math.floor(value / 3600) + ' ' + t('hr') + ' ' + Math.ceil(value % 3600 / 60) + ' ' + t('min');
    if (value >= 60) return Math.floor(value / 60) + ' ' + t('min') + ' ' + value % 60 + ' ' + t('sec');
    return value + ' ' + t('sec');
  }
  function terminal(entry) { return ['completed', 'failed', 'cancelled'].indexOf(entry.status) !== -1; }
  function rememberCSRF() {
    var input = document.querySelector('input[name^="csrf"]');
    if (input) csrf = { name: input.name, hash: input.value };
  }
  function updateCSRF(name, hash) {
    if (!name || !hash) return;
    csrf = { name: name, hash: hash };
    document.querySelectorAll('input').forEach(function (input) { if (input.name === name) input.value = hash; });
  }
  function formData(values) {
    rememberCSRF();
    var data = new FormData();
    Object.keys(values).forEach(function (key) { data.append(key, values[key]); });
    if (csrf.name) data.append(csrf.name, csrf.hash);
    return data;
  }

  // XHR reports progress inside each chunk, including on slow connections.
  function request(entry, target, data, progress) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      entry.xhr = xhr;
      xhr.open('POST', target);
      xhr.timeout = 7200000;
      xhr.withCredentials = true;
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('Accept', 'application/json');
      if (progress) xhr.upload.addEventListener('progress', function (event) {
        if (event.lengthComputable && !entry.cancelled) { progress(event.loaded / event.total); render(); }
      });
      xhr.onload = function () {
        window.CloudApp?.token(xhr.getResponseHeader('X-CSRF-TOKEN'));
        var payload;
        try { payload = JSON.parse(xhr.responseText); }
        catch (_) { reject(new Error(t('Your session or security token expired. Please try again.'))); return; }
        updateCSRF(payload.csrf_name, payload.csrf_hash);
        if (xhr.status < 200 || xhr.status >= 300 || !payload.success) {
          reject(new Error(payload.error || t('The action could not be completed.'))); return;
        }
        resolve(payload);
      };
      xhr.onerror = function () { reject(new Error(t('Upload failed. Check your connection and retry.'))); };
      xhr.ontimeout = function () { reject(new Error(t('The request timed out. Please try again.'))); };
      xhr.onabort = function () { reject(new Error(t('Cancelled'))); };
      xhr.onloadend = function () { if (entry.xhr === xhr) entry.xhr = null; };
      xhr.send(data);
    });
  }

  async function transfer(entry) {
    entry.started = Date.now();
    entry.baseline = entry.loaded;
    if (entry.kind === 'zip') return uploadBundle(entry);
    if (entry.kind === 'extract') {
      entry.status = 'extracting'; render();
      return request(entry, entry.url, formData({ target_folder_id: entry.parentId }));
    }
    var file = entry.file;
    if (file.size <= entry.chunkSize) {
      entry.loaded = 0; entry.baseline = 0; entry.status = 'uploading'; render();
      await request(entry, entry.url, formData({ 'files[]': file, parent_id: entry.parentId, context: entry.context }), function (ratio) {
        entry.loaded = Math.min(file.size, ratio * file.size);
        if (ratio >= 1) entry.status = 'assembling';
      });
      return;
    }
    if (!entry.session) {
      entry.status = 'initializing'; render();
      var session = await request(entry, entry.url + '/init', formData({
        file_name: file.name, file_size: file.size, parent_id: entry.parentId, mime_type: file.type || ''
      }));
      entry.session = session;
      entry.chunk = 0;
    }
    while (entry.chunk < entry.session.total_chunks) {
      if (entry.cancelled) return;
      var start = entry.chunk * entry.session.chunk_size;
      var end = Math.min(start + entry.session.chunk_size, file.size);
      entry.loaded = start; entry.status = 'uploading'; render();
      var data = formData({ upload_id: entry.session.upload_id, chunk_index: entry.chunk });
      data.append('chunk', file.slice(start, end), 'chunk');
      await request(entry, entry.url + '/chunk', data, function (ratio) { entry.loaded = start + (end - start) * ratio; });
      entry.chunk++;
      entry.loaded = end;
    }
    if (entry.cancelled) return;
    entry.status = 'assembling'; render();
    await request(entry, entry.url + '/complete', formData({ upload_id: entry.session.upload_id }));
  }

  async function uploadBundle(entry) {
    if (!entry.batch) {
      entry.status = 'initializing'; render();
      entry.batch = await request(entry, entry.url + '/batch/init', formData({ parent_id: entry.parentId,
        files: JSON.stringify(entry.files.map(function (file) { return { name: file.name, size: file.size }; })) }));
      entry.name = entry.batch.archive_name;
      entry.batchFile = 0; entry.batchChunk = 0; entry.stagedBytes = 0; entry.ackBytes = 0;
    }
    while (entry.batchFile < entry.files.length) {
      if (entry.cancelled) return;
      var file = entry.files[entry.batchFile], session = entry.batch.sessions[entry.batchFile];
      while (entry.batchChunk < session.total_chunks) {
        if (entry.cancelled) return;
        var start = entry.batchChunk * session.chunk_size, end = Math.min(start + session.chunk_size, file.size);
        entry.status = 'uploading'; render();
        var data = formData({ upload_id: session.upload_id, chunk_index: entry.batchChunk });
        data.append('chunk', file.slice(start, end), 'chunk');
        await request(entry, entry.url + '/chunk', data, function (ratio) { entry.loaded = entry.stagedBytes + start + (end - start) * ratio; });
        entry.batchChunk++; entry.loaded = entry.stagedBytes + end; entry.ackBytes = entry.loaded;
      }
      if (entry.cancelled) return;
      entry.status = 'assembling'; render();
      await request(entry, entry.url + '/batch/stage', formData({ batch_id: entry.batch.batch_id, upload_id: session.upload_id }));
      entry.stagedBytes += file.size; entry.batchFile++; entry.batchChunk = 0;
    }
    entry.status = 'zipping'; render();
    return request(entry, entry.url + '/batch/complete', formData({ batch_id: entry.batch.batch_id }));
  }

  async function processQueue() {
    if (active) return;
    var entry = queue.shift();
    if (!entry) {
      render();
      if (needsRefresh && /\/drive(?:\/|$)/.test(location.pathname)) {
        needsRefresh = false;
        window.driveRefresh?.();
      }
      return;
    }
    active = entry;
    try {
      var result = await transfer(entry);
      entry.status = entry.cancelled ? 'cancelled' : 'completed';
      if (!entry.cancelled) {
        entry.loaded = entry.file ? entry.file.size : 0;
        entry.session = null;
        needsRefresh = true;
        if (result && entry.kind === 'extract') {
          entry.folderUrl = result.folder_url;
          window.CloudApp?.toast(result.message || 'Archive extracted.');
        }
      }
    } catch (error) {
      entry.status = entry.cancelled ? 'cancelled' : 'failed';
      entry.error = entry.cancelled ? '' : error.message;
      // A chunk retry starts from the last acknowledged byte, not the last progress event.
      if (entry.session) entry.loaded = entry.chunk * entry.session.chunk_size;
      if (entry.batch) entry.loaded = entry.ackBytes;
    } finally {
      if (entry.cancelled && entry.session) {
        try { await request(entry, entry.url + '/cancel', formData({ upload_id: entry.session.upload_id })); }
        catch (_) { /* Expired sessions are also cleaned up on the server. */ }
        entry.session = null; entry.chunk = 0; entry.loaded = 0;
      }
      if (entry.cancelled && entry.batch) {
        try { await request(entry, entry.url + '/batch/cancel', formData({ batch_id: entry.batch.batch_id })); }
        catch (_) { /* Server expiry cleanup also removes unfinished bundles. */ }
        entry.batch = null; entry.loaded = 0;
      }
      active = null; render(); processQueue();
    }
  }

  function retry(entry) {
    if (!terminal(entry) || entry.status === 'completed' || entry === active) return;
    entry.cancelled = false; entry.error = ''; entry.status = 'pending';
    queue.push(entry); render(); processQueue();
  }
  function cancel(entry) {
    if (terminal(entry) || ['assembling', 'zipping', 'extracting'].indexOf(entry.status) !== -1) return;
    entry.cancelled = true;
    if (entry === active) entry.xhr?.abort();
    else { queue = queue.filter(function (item) { return item !== entry; }); entry.status = 'cancelled'; }
    render();
  }

  function ensurePanel() {
    if (panel) return;
    panel = document.createElement('section');
    panel.id = 'drive-transfer-panel'; panel.className = 'drive-transfer-panel card';
    panel.setAttribute('aria-label', t('Transfers'));
    panel.innerHTML = '<div class="drive-transfer-header">' +
      '<strong class="transfer-title"></strong><div class="d-flex gap-1">' +
      '<button type="button" class="btn btn-sm btn-light-secondary transfer-toggle" aria-controls="drive-transfer-list"></button>' +
      '<button type="button" class="btn btn-sm btn-light-secondary transfer-close"><i class="ti ti-x"></i></button></div></div>' +
      '<div class="drive-transfer-summary"><div class="d-flex justify-content-between gap-2 small mb-1"><span class="transfer-count" role="status"></span><strong class="transfer-percent"></strong></div>' +
      '<div class="progress" style="height:6px"><div class="progress-bar transfer-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"></div></div>' +
      '<div class="transfer-estimate small text-muted mt-1"></div></div>' +
      '<div id="drive-transfer-list" class="drive-transfer-list"></div>' +
      '<button type="button" class="btn btn-sm btn-link transfer-clear"></button>';
    // Outside cloud-shell: SPA page replacements cannot remove the running transfers.
    document.body.appendChild(panel);
    panel.querySelector('.transfer-toggle').onclick = function () { collapsed = !collapsed; render(); };
    panel.querySelector('.transfer-close').onclick = function () {
      if (active || queue.length) return;
      entries = []; panel.remove(); panel = null;
    };
    panel.querySelector('.transfer-clear').onclick = function () {
      entries = entries.filter(function (entry) {
        if (!terminal(entry) || entry === active) return true;
        entry.row.remove(); return false;
      });
      render();
    };
  }

  function buildRow(entry) {
    var row = document.createElement('div'); row.className = 'upload-file-item';
    row.innerHTML = '<div class="d-flex justify-content-between align-items-start gap-2 mb-1">' +
      '<span class="upload-file-name text-truncate fw-semibold small" data-user-content></span><span class="upload-percent small fw-semibold"></span></div>' +
      '<div class="progress" style="height:5px"><div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"></div></div>' +
      '<div class="d-flex align-items-center justify-content-between gap-2 mt-1"><span class="upload-status small"></span><div class="d-flex gap-1">' +
      '<button type="button" class="btn btn-sm btn-light-danger upload-cancel"><i class="ti ti-x"></i></button>' +
      '<button type="button" class="btn btn-sm btn-light-warning upload-retry"><i class="ti ti-refresh"></i></button></div></div>' +
      '<div class="upload-metrics small text-muted"></div><div class="upload-error small text-danger" role="status"></div>' +
      '<a class="upload-open small" hidden></a>';
    row.querySelector('.upload-file-name').textContent = entry.name;
    row.querySelector('.upload-cancel').onclick = function () { cancel(entry); };
    row.querySelector('.upload-retry').onclick = function () { retry(entry); };
    entry.row = row; return row;
  }

  function speed(entry) {
    return entry.status === 'uploading' ? Math.max(0, entry.loaded - entry.baseline) / Math.max(0.1, (Date.now() - entry.started) / 1000) : 0;
  }
  function render() {
    if (!panel) return;
    panel.hidden = entries.length === 0;
    var total = 0, loaded = 0, completed = 0;
    entries.forEach(function (entry) {
      if (!entry.row) panel.querySelector('.drive-transfer-list').appendChild(buildRow(entry));
      var row = entry.row, rate = speed(entry), size = entry.file ? entry.file.size : 0;
      row.querySelector('.upload-file-name').textContent = entry.name;
      total += size; loaded += Math.min(size, entry.loaded);
      if (entry.status === 'completed') completed++;
      var percent = entry.status === 'completed' ? 100 : (size ? Math.min(99, Math.floor(entry.loaded / size * 100)) : 0);
      var indeterminate = ['initializing', 'assembling', 'zipping', 'extracting'].indexOf(entry.status) !== -1;
      row.querySelector('.upload-percent').textContent = entry.kind === 'extract' && !terminal(entry) ? '—' : percent + '%';
      var bar = row.querySelector('.progress-bar');
      bar.style.width = (indeterminate ? 100 : percent) + '%';
      bar.classList.toggle('progress-bar-striped', indeterminate);
      bar.classList.toggle('progress-bar-animated', indeterminate);
      bar.classList.toggle('bg-danger', entry.status === 'failed');
      bar.classList.toggle('bg-success', entry.status === 'completed');
      if (indeterminate) bar.removeAttribute('aria-valuenow'); else bar.setAttribute('aria-valuenow', percent);
      bar.setAttribute('aria-label', entry.name + ': ' + t(labels[entry.status]));
      row.querySelector('.upload-status').textContent = t(labels[entry.status]);
      row.querySelector('.upload-error').textContent = entry.error ? t(entry.error) : '';
      var metrics = entry.file ? bytes(entry.loaded) + ' / ' + bytes(size) : t('Extract into a new folder');
      if (entry.kind === 'zip') metrics = message('{0} files in one ZIP', [entry.files.length]) + ' · ' + metrics;
      if (rate > 0 && entry.loaded < size) metrics += ' · ' + bytes(rate) + '/s · ' + message('About {0} left', [duration((size - entry.loaded) / rate)]);
      if (entry.status === 'assembling') metrics += ' · ' + t('Finishing on server…');
      row.querySelector('.upload-metrics').textContent = metrics;
      row.querySelector('.upload-cancel').hidden = terminal(entry) || indeterminate && entry.status !== 'initializing';
      row.querySelector('.upload-cancel').title = t('Cancel');
      row.querySelector('.upload-cancel').setAttribute('aria-label', t('Cancel'));
      row.querySelector('.upload-retry').hidden = !terminal(entry) || entry.status === 'completed' || entry === active;
      row.querySelector('.upload-retry').title = t('Retry');
      row.querySelector('.upload-retry').setAttribute('aria-label', t('Retry'));
      var open = row.querySelector('.upload-open');
      open.hidden = entry.status !== 'completed' || !entry.folderUrl;
      if (entry.folderUrl) open.href = entry.folderUrl;
      open.textContent = t('Open folder');
    });
    var percent = completed === entries.length ? 100 : total ? Math.min(99, Math.floor(loaded / total * 100)) : 0;
    panel.querySelector('.transfer-title').textContent = t('Transfers');
    panel.setAttribute('aria-label', t('Transfers'));
    panel.querySelector('.transfer-count').textContent = message('{0} of {1} tasks completed', [completed, entries.length]);
    panel.querySelector('.transfer-percent').textContent = percent + '%';
    panel.querySelector('.transfer-bar').style.width = percent + '%';
    panel.querySelector('.transfer-bar').setAttribute('aria-valuenow', percent);
    panel.querySelector('.transfer-bar').setAttribute('aria-label', t('Total progress'));
    var remaining = entries.reduce(function (sum, entry) {
      return sum + (!terminal(entry) && entry.file ? entry.file.size - entry.loaded : 0);
    }, 0);
    var rate = active ? speed(active) : 0;
    panel.querySelector('.transfer-estimate').textContent = active && rate > 0 && remaining > 0
      ? bytes(rate) + '/s · ' + message('About {0} left', [duration(remaining / rate)])
      : active ? t(labels[active.status]) : t(completed === entries.length ? 'Completed' : 'Some transfers need attention.');
    panel.querySelector('.drive-transfer-list').hidden = collapsed;
    var toggle = panel.querySelector('.transfer-toggle');
    toggle.textContent = t(collapsed ? 'Show details' : 'Minimize'); toggle.setAttribute('aria-expanded', String(!collapsed));
    panel.querySelector('.transfer-close').disabled = !!active || queue.length > 0;
    panel.querySelector('.transfer-close').setAttribute('aria-label', t('Close'));
    panel.querySelector('.transfer-clear').textContent = t('Clear finished');
    panel.querySelector('.transfer-clear').hidden = !entries.some(function (entry) { return terminal(entry) && entry !== active; });
  }

  function enqueue(entry) {
    entry.status = 'pending'; entry.loaded = 0; entry.error = ''; entry.cancelled = false;
    entries.push(entry); queue.push(entry); ensurePanel(); collapsed = false;
  }
  function hideModal(element) { window.bootstrap?.Modal.getInstance(element)?.hide(); }
  function initUploadManager() {
    rememberCSRF(); render();
    var form = document.getElementById('upload-form');
    if (form && form.dataset.uploadBound !== 'true') {
      form.dataset.uploadBound = 'true'; form.dataset.managed = 'true';
      var input = form.querySelector('#drive-files'), list = form.querySelector('#upload-file-list');
      input.addEventListener('change', function () {
        list.replaceChildren(); list.classList.toggle('d-none', !input.files.length);
        Array.from(input.files).forEach(function (file) {
          var item = document.createElement('div'); item.className = 'small text-truncate';
          item.setAttribute('data-user-content', ''); item.textContent = file.name + ' · ' + bytes(file.size); list.appendChild(item);
        });
      });
      form.addEventListener('submit', function (event) {
        event.preventDefault(); event.stopImmediatePropagation();
        var files = Array.from(input.files || []);
        if (files.length > (Number(form.dataset.autoZipThreshold) || 10)) {
          enqueue({ kind: 'zip', files: files, file: { size: files.reduce(function (sum, file) { return sum + file.size; }, 0) },
            name: message('ZIP upload ({0} files)', [files.length]), url: form.action,
            parentId: form.elements.parent_id.value, context: form.elements.context.value });
        } else files.forEach(function (file) {
          enqueue({ kind: 'upload', file: file, name: file.name, url: form.action,
            parentId: form.elements.parent_id.value, context: form.elements.context.value,
            chunkSize: Number(form.dataset.chunkSize) || 16 * 1024 * 1024 });
        });
        hideModal(form.closest('.modal')); input.value = ''; list.replaceChildren(); list.classList.add('d-none');
        render(); processQueue();
      });
    }
    var extractForm = document.getElementById('extract-form');
    if (extractForm && extractForm.dataset.extractBound !== 'true') {
      extractForm.dataset.extractBound = 'true'; extractForm.dataset.managed = 'true';
      extractForm.addEventListener('submit', function (event) {
        event.preventDefault(); event.stopImmediatePropagation();
        if (!extractForm.dataset.archiveName) return;
        enqueue({ kind: 'extract', name: extractForm.dataset.archiveName, url: extractForm.action,
          parentId: extractForm.elements.target_folder_id.value });
        hideModal(extractForm.closest('.modal')); render(); processQueue();
      });
    }
  }
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-drive-extract]');
    if (!button) return;
    var form = document.getElementById('extract-form');
    if (form) { form.action = button.dataset.action; form.dataset.archiveName = button.dataset.name; }
    var name = document.getElementById('extract-file-name');
    if (name) name.textContent = button.dataset.name;
  });
  window.addEventListener('beforeunload', function (event) {
    if (active || queue.length) { event.preventDefault(); event.returnValue = ''; }
  });
  document.addEventListener('DOMContentLoaded', initUploadManager);
  document.addEventListener('cloud:updated', initUploadManager);
  var previousInit = window._drivePageInit;
  window._drivePageInit = function () { if (typeof previousInit === 'function') previousInit(); initUploadManager(); };
  // Also used by file thumbnails and previews in drive-ui.js.
  var script = document.querySelector('script[src*="assets/js/drive-upload.js"]');
  window.driveBaseUrl = (document.querySelector('base')?.href || (script ? new URL('../../', script.src).href : location.origin)).replace(/\/$/, '');
  setInterval(function () { if (active) render(); }, 1000);
})();
