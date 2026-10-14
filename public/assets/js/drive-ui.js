(function () {
  'use strict';
  function t(text) { return window.CloudApp ? window.CloudApp.t(text) : text; }

  function timedFetch(target, options) {
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, 30000);
    return window.fetch(target, Object.assign({}, options || {}, { signal: controller.signal }))
      .then(function (response) { window.CloudApp?.token(response.headers.get('X-CSRF-TOKEN')); return response; })
      .finally(function () { clearTimeout(timer); });
  }

  var parser = new DOMParser();

  function toArray(list) {
    return Array.prototype.slice.call(list || []);
  }

  function urlFor(value) {
    try {
      return new URL(value, window.location.href);
    } catch (error) {
      return null;
    }
  }

  function hasDriveSegment(pathname) {
    return pathname.split('/').filter(Boolean).some(function (segment) {
      return segment.toLowerCase() === 'drive';
    });
  }

  function isDriveUrl(url) {
    return url && url.origin === window.location.origin && hasDriveSegment(url.pathname);
  }

  function isDirectFileUrl(url) {
    return /\/drive\/items\/\d+\/(?:download|preview)\/?$/i.test(url.pathname);
  }

  function setBusy(isBusy) {
    document.body.classList.toggle('drive-busy', isBusy);
  }

  function closeOpenModals() {
    if (!window.bootstrap) {
      return;
    }

    toArray(document.querySelectorAll('.modal.show')).forEach(function (modalElement) {
      var modal = bootstrap.Modal.getInstance(modalElement);
      if (modal) {
        modal.hide();
      }
    });

    toArray(document.querySelectorAll('.modal-backdrop')).forEach(function (backdrop) {
      backdrop.remove();
    });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
  }

  function showToast(message, variant) {
    var container = document.querySelector('.drive-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.className = 'toast-container position-fixed top-0 end-0 p-3 drive-toast-container';
      document.body.appendChild(container);
    }

    var toastElement = document.createElement('div');
    toastElement.className = 'toast text-bg-' + (variant || 'danger') + ' border-0';
    toastElement.setAttribute('role', 'alert');
    toastElement.setAttribute('aria-live', 'assertive');
    toastElement.setAttribute('aria-atomic', 'true');
    toastElement.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
    toastElement.querySelector('.toast-body').textContent = message;
    container.appendChild(toastElement);

    if (window.bootstrap) {
      var toast = new bootstrap.Toast(toastElement, { delay: 5000 });
      toastElement.addEventListener('hidden.bs.toast', function () {
        toastElement.remove();
      });
      toast.show();
      return;
    }

    setTimeout(function () {
      toastElement.remove();
    }, 5000);
  }

  function replaceFrame(html, targetUrl, pushState) {
    if (window.CloudApp) return window.CloudApp.navigate(window.location.href, false);
    var nextDocument = parser.parseFromString(html, 'text/html');
    var nextContent = nextDocument.querySelector('.pc-content');
    var currentContent = document.querySelector('.pc-content');

    if (!nextContent || !currentContent) {
      window.location.href = targetUrl;
      return;
    }

    closeOpenModals();

    currentContent.innerHTML = nextContent.innerHTML;

    var currentSidebar = document.querySelector('.pc-sidebar');
    var nextSidebar = nextDocument.querySelector('.pc-sidebar');
    if (currentSidebar && nextSidebar) {
      currentSidebar.innerHTML = nextSidebar.innerHTML;
    }

    if (nextDocument.title) {
      document.title = nextDocument.title;
    }

    if (pushState) {
      window.history.pushState({ drive: true }, '', targetUrl);
    }

    if (window.feather && typeof window.feather.replace === 'function') {
      window.feather.replace();
    }

    initDrivePage();
  }

  function handleHtmlResponse(response, fallbackUrl, pushState) {
    var responseUrl = urlFor(response.url || fallbackUrl);
    if (!isDriveUrl(responseUrl)) {
      window.location.href = response.url || fallbackUrl;
      return Promise.resolve();
    }

    var contentType = response.headers.get('content-type') || '';
    if (contentType.indexOf('text/html') === -1) {
      window.location.href = response.url || fallbackUrl;
      return Promise.resolve();
    }

    return response.text().then(function (html) {
      replaceFrame(html, response.url || fallbackUrl, pushState);
    });
  }

  function navigateTo(targetUrl, pushState) {
    if (window.CloudApp) return window.CloudApp.navigate(targetUrl, pushState);
    setBusy(true);

    return timedFetch(targetUrl, {
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (response) {
        return handleHtmlResponse(response, targetUrl, pushState);
      })
      .catch(function () {
        showToast('The drive page could not be loaded.');
      })
      .finally(function () {
        setBusy(false);
      });
  }

  function setFormBusy(form, isBusy) {
    toArray(form.querySelectorAll('button[type="submit"]')).forEach(function (button) {
      if (isBusy) {
        button.dataset.originalText = button.textContent;
      }

      button.disabled = isBusy;
      if (!isBusy && button.dataset.originalText) {
        button.textContent = button.dataset.originalText;
        delete button.dataset.originalText;
      }
    });
  }

  function submitDriveForm(form, actionUrl) {
    setFormBusy(form, true);
    setBusy(true);

    return timedFetch(actionUrl.toString(), {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (response) {
        return handleHtmlResponse(response, response.url || actionUrl.toString(), true);
      })
      .catch(function () {
        showToast('The action could not be completed.');
        setFormBusy(form, false);
      })
      .finally(function () {
        setBusy(false);
      });
  }

  function submitUploadForm(form, actionUrl) {
    var progress = document.getElementById('upload-progress');
    var progressBar = progress ? progress.querySelector('.progress-bar') : null;
    var submit = document.getElementById('upload-submit');
    var xhr = new XMLHttpRequest();

    if (progress) {
      progress.classList.remove('d-none');
    }

    if (progressBar) {
      progressBar.style.width = '0%';
    }

    if (submit) {
      submit.disabled = true;
      submit.textContent = 'Uploading...';
    }

    setBusy(true);
    xhr.open('POST', actionUrl.toString(), true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.withCredentials = true;

    xhr.upload.addEventListener('progress', function (event) {
      if (!event.lengthComputable || !progressBar) {
        return;
      }

      progressBar.style.width = Math.max(1, Math.round((event.loaded / event.total) * 100)) + '%';
    });

    xhr.addEventListener('load', function () {
      setBusy(false);

      var responseUrl = xhr.responseURL || actionUrl.toString();
      if (xhr.status < 200 || xhr.status >= 400) {
        showToast('Upload failed.');
        if (submit) {
          submit.disabled = false;
          submit.textContent = 'Upload';
        }
        return;
      }

      if (!isDriveUrl(urlFor(responseUrl))) {
        window.location.href = responseUrl;
        return;
      }

      replaceFrame(xhr.responseText, responseUrl, true);
    });

    xhr.addEventListener('error', function () {
      setBusy(false);
      showToast('Upload failed.');
      if (submit) {
        submit.disabled = false;
        submit.textContent = 'Upload';
      }
    });

    xhr.send(new FormData(form));
  }

  function currentActionUrl(form) {
    return urlFor(form.getAttribute('action') || window.location.href);
  }

  function handleGetForm(form, actionUrl) {
    var params = new URLSearchParams(new FormData(form));
    actionUrl.search = params.toString();
    navigateTo(actionUrl.toString(), true);
  }

  function cloneCSRFInput() {
    var source = document.querySelector('#share-form input[name^="csrf"]') || document.querySelector('input[name^="csrf"]');
    return source ? source.cloneNode(true) : null;
  }

  function hiddenInput(name, value) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value || '';
    return input;
  }

  function fillRenameModal(button) {
    var form = document.getElementById('rename-form');
    var input = document.getElementById('rename-name');
    if (form) {
      form.action = button.dataset.action || '';
    }
    if (input) {
      input.value = button.dataset.name || '';
    }
  }

  function fillTargetModal(button) {
    var form = document.getElementById('target-form');
    var title = document.getElementById('target-title');
    if (form) {
      form.action = button.dataset.action || '';
    }
    if (title) {
      title.textContent = t(button.dataset.title || 'Move');
    }
  }

  function fillConfirmModal(button) {
    var form = document.getElementById('confirm-form');
    var title = document.getElementById('confirm-title');
    var message = document.getElementById('confirm-message');
    var submit = document.getElementById('confirm-submit');

    if (form) {
      form.action = button.dataset.action || '';
    }
    if (title) {
      title.textContent = t(button.dataset.title || 'Confirm');
    }
    if (message) {
      message.dataset.i18nTemplate = button.dataset.messageTemplate || '';
      message.dataset.i18nValue = button.dataset.messageName || '';
      message.textContent = button.dataset.messageTemplate ? t(button.dataset.messageTemplate).replace('{0}', button.dataset.messageName || '') : t(button.dataset.message || 'Continue?');
    }
    if (submit) {
      submit.textContent = t(button.dataset.submitLabel || 'Confirm');
      submit.className = 'btn ' + (button.dataset.submitClass || 'btn-danger');
    }
  }

  function fillDetailsPanel(button) {
    var imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    var previewTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv', 'text/markdown', 'application/json', 'audio/mpeg', 'video/mp4'];

    ['name', 'type', 'size', 'updated', 'owner', 'mime', 'extension'].forEach(function (key) {
      var target = document.getElementById('details-' + key);
      if (target) target.textContent = button.dataset[key] || '-';
    });

    var itemId = button.dataset.id || '';
    var mime = (button.dataset.mime || '').toLowerCase();
    var isImage = imageTypes.indexOf(mime) !== -1;
    var isPreviewable = previewTypes.indexOf(mime) !== -1 || mime.indexOf('image/') === 0 || mime.indexOf('text/') === 0;
    var isFile = (button.dataset.type || '').toLowerCase() === 'file';

    // Thumbnail
    var thumb = document.getElementById('details-thumb');
    if (thumb) {
      if (isImage && itemId) {
        thumb.src = window.driveBaseUrl + '/drive/items/' + itemId + '/thumb';
        thumb.classList.remove('d-none');
        thumb.onerror = function () { thumb.classList.add('d-none'); };
      } else {
        thumb.classList.add('d-none');
        thumb.src = '';
      }
    }

    // Download button
    var dlBtn = document.getElementById('details-download');
    if (dlBtn) {
      if (isFile && itemId) {
        dlBtn.href = window.driveBaseUrl + '/drive/items/' + itemId + '/download';
        dlBtn.classList.remove('d-none');
      } else {
        dlBtn.classList.add('d-none');
      }
    }

    // Preview button
    var pvBtn = document.getElementById('details-preview');
    if (pvBtn) {
      if (isFile && isPreviewable && itemId) {
        pvBtn.href = window.driveBaseUrl + '/drive/items/' + itemId + '/preview';
        pvBtn.classList.remove('d-none');
      } else {
        pvBtn.classList.add('d-none');
      }
    }
  }

  function formatBytes(bytes) {
    bytes = Number(bytes || 0);
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' B';
  }

  function previewInfoUrl(previewUrl) {
    var url = urlFor(previewUrl);
    if (!url) return null;
    url.pathname = url.pathname.replace(/\/preview\/?$/i, '/preview-info');
    return url;
  }

  function clearPreviewModal() {
    ['drive-preview-image', 'drive-preview-frame', 'drive-preview-text', 'drive-preview-audio', 'drive-preview-video', 'drive-preview-unsupported', 'drive-preview-error'].forEach(function (id) {
      var element = document.getElementById(id);
      if (element) element.classList.add('d-none');
    });

    ['drive-preview-frame', 'drive-preview-audio', 'drive-preview-video', 'drive-preview-image'].forEach(function (id) {
      var element = document.getElementById(id);
      if (element) element.removeAttribute('src');
    });

    var text = document.getElementById('drive-preview-text');
    if (text) text.textContent = '';

    var error = document.getElementById('drive-preview-error');
    if (error) error.textContent = '';
  }

  function setPreviewLoading(isLoading) {
    var loading = document.getElementById('drive-preview-loading');
    if (loading) loading.classList.toggle('d-none', !isLoading);
  }

  function showPreviewError(message) {
    message = t(message);
    clearPreviewModal();
    setPreviewLoading(false);
    var error = document.getElementById('drive-preview-error');
    if (error) {
      error.textContent = message || 'Preview could not be loaded.';
      error.classList.remove('d-none');
    }
  }

  function renderPreview(item) {
    clearPreviewModal();
    setPreviewLoading(false);

    var title = document.getElementById('drive-preview-title');
    var meta = document.getElementById('drive-preview-meta');
    var download = document.getElementById('drive-preview-download');

    if (title) title.textContent = item.name || 'Preview';
    if (meta) meta.textContent = [item.mime_type || 'Unknown type', formatBytes(item.size_bytes)].join(' - ');
    if (download) {
      download.href = item.download_url || '#';
      download.classList.toggle('d-none', !item.download_url);
    }

    if (item.preview_kind === 'image') {
      var image = document.getElementById('drive-preview-image');
      if (image) {
        image.alt = item.name || '';
        image.src = item.preview_url;
        image.classList.remove('d-none');
      }
      return;
    }

    if (item.preview_kind === 'pdf') {
      var frame = document.getElementById('drive-preview-frame');
      if (frame) {
        frame.src = item.preview_url;
        frame.classList.remove('d-none');
      }
      return;
    }

    if (item.preview_kind === 'audio') {
      var audio = document.getElementById('drive-preview-audio');
      if (audio) {
        audio.src = item.preview_url;
        audio.classList.remove('d-none');
      }
      return;
    }

    if (item.preview_kind === 'video') {
      var video = document.getElementById('drive-preview-video');
      if (video) {
        video.src = item.preview_url;
        video.classList.remove('d-none');
      }
      return;
    }

    if (item.preview_kind === 'text') {
      timedFetch(item.text_url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (!payload.success) {
            showPreviewError(payload.error || 'Text preview could not be loaded.');
            return;
          }

          var text = document.getElementById('drive-preview-text');
          if (text) {
            text.textContent = payload.content + (payload.truncated ? '\n\n[Preview truncated]' : '');
            text.classList.remove('d-none');
          }
        })
        .catch(function () {
          showPreviewError('Text preview could not be loaded.');
        });
      return;
    }

    var unsupported = document.getElementById('drive-preview-unsupported');
    if (unsupported) unsupported.classList.remove('d-none');
  }

  function openDrivePreview(link) {
    var modalElement = document.getElementById('drive-preview-modal');
    var infoUrl = previewInfoUrl(link.href);

    if (!modalElement || !infoUrl) {
      window.open(link.href, '_blank', 'noopener');
      return;
    }

    clearPreviewModal();
    setPreviewLoading(true);

    var title = document.getElementById('drive-preview-title');
    var meta = document.getElementById('drive-preview-meta');
    var download = document.getElementById('drive-preview-download');
    if (title) title.textContent = 'Preview';
    if (meta) meta.textContent = '';
    if (download) download.classList.add('d-none');

    if (window.bootstrap) {
      new bootstrap.Modal(modalElement).show();
    }

    timedFetch(infoUrl.toString(), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload.success) {
          showPreviewError(payload.error || 'Preview could not be loaded.');
          return;
        }

        renderPreview(payload.item || {});
      })
      .catch(function () {
        showPreviewError('Preview could not be loaded.');
      });
  }

  function fillShareModal(button) {
    var form = document.getElementById('share-form');
    var name = document.getElementById('share-file-name');
    var recipient = document.getElementById('share-recipient');
    var permission = document.getElementById('share-permission');
    var submit = document.getElementById('share-submit');
    var list = document.getElementById('share-recipient-list');
    var count = document.getElementById('share-count');
    var shares = [];

    if (form) {
      form.action = button.dataset.action || '';
    }
    if (name) {
      name.textContent = button.dataset.name || '';
    }
    if (recipient) {
      recipient.value = '';
    }
    if (permission) {
      permission.value = 'viewer';
    }
    if (submit) {
      submit.disabled = false;
      submit.textContent = 'Share';
    }

    try {
      shares = JSON.parse(button.dataset.shares || '[]');
    } catch (error) {
      shares = [];
    }

    if (count) {
      count.textContent = shares.length;
    }

    if (!list) {
      return;
    }

    list.innerHTML = '';

    if (shares.length === 0) {
      var empty = document.createElement('div');
      empty.className = 'text-muted small';
      empty.textContent = 'No users have direct access yet.';
      list.appendChild(empty);
      return;
    }

    shares.forEach(function (share) {
      var row = document.createElement('div');
      row.className = 'border rounded p-2';

      var header = document.createElement('div');
      header.className = 'd-flex align-items-start justify-content-between gap-2 mb-2';

      var label = document.createElement('div');
      label.className = 'text-truncate';
      label.innerHTML = '<div class="fw-semibold text-truncate"></div><div class="text-muted small text-truncate"></div>';
      label.querySelector('.fw-semibold').textContent = share.username || 'User';
      label.querySelector('.text-muted').textContent = share.email || '';

      var badge = document.createElement('span');
      badge.className = 'badge bg-light-primary text-primary';
      badge.textContent = share.permission || 'viewer';

      header.appendChild(label);
      header.appendChild(badge);
      row.appendChild(header);

      var controls = document.createElement('div');
      controls.className = 'd-flex flex-wrap gap-2';

      var updateForm = document.createElement('form');
      updateForm.method = 'post';
      updateForm.action = share.update_url || '';
      updateForm.className = 'd-flex gap-2 flex-grow-1';
      var updateCsrf = cloneCSRFInput();
      if (updateCsrf) updateForm.appendChild(updateCsrf);
      updateForm.appendChild(hiddenInput('parent_id', document.querySelector('#share-form input[name="parent_id"]')?.value || ''));
      updateForm.appendChild(hiddenInput('context', document.querySelector('#share-form input[name="context"]')?.value || 'drive'));

      var select = document.createElement('select');
      select.name = 'permission';
      select.className = 'form-select form-select-sm';
      ['viewer', 'editor'].forEach(function (value) {
        var option = document.createElement('option');
        option.value = value;
        option.textContent = value.charAt(0).toUpperCase() + value.slice(1);
        option.selected = share.permission === value;
        select.appendChild(option);
      });
      updateForm.appendChild(select);

      var save = document.createElement('button');
      save.type = 'submit';
      save.className = 'btn btn-sm btn-light-primary';
      save.textContent = t('Save');
      updateForm.appendChild(save);

      var revokeForm = document.createElement('form');
      revokeForm.method = 'post';
      revokeForm.action = share.revoke_url || '';
      var revokeCsrf = cloneCSRFInput();
      if (revokeCsrf) revokeForm.appendChild(revokeCsrf);
      revokeForm.appendChild(hiddenInput('parent_id', document.querySelector('#share-form input[name="parent_id"]')?.value || ''));
      revokeForm.appendChild(hiddenInput('context', document.querySelector('#share-form input[name="context"]')?.value || 'drive'));

      var revoke = document.createElement('button');
      revoke.type = 'submit';
      revoke.className = 'btn btn-sm btn-light-danger';
      revoke.textContent = t('Revoke');
      revokeForm.appendChild(revoke);

      controls.appendChild(updateForm);
      controls.appendChild(revokeForm);
      row.appendChild(controls);
      list.appendChild(row);
    });
  }

  function initDrivePage() {
    var activeModal = document.getElementById('drive-page-state')?.dataset.activeModal || '';
    if (activeModal && window.bootstrap) {
      var activeModalElement = document.getElementById(activeModal);
      if (activeModalElement) {
        new bootstrap.Modal(activeModalElement).show();
      }
    }
    // Let external modules hook into SPA page init
    if (typeof window._drivePageInit === 'function') {
      window._drivePageInit();
    }
  }

  document.addEventListener('click', function (event) {
    var renameButton = event.target.closest('[data-drive-rename]');
    if (renameButton) {
      fillRenameModal(renameButton);
      return;
    }

    var targetButton = event.target.closest('[data-drive-target]');
    if (targetButton) {
      fillTargetModal(targetButton);
      return;
    }

    var confirmButton = event.target.closest('[data-drive-confirm]');
    if (confirmButton) {
      fillConfirmModal(confirmButton);
      return;
    }

    var detailsButton = event.target.closest('[data-drive-details]');
    if (detailsButton) {
      fillDetailsPanel(detailsButton);
      // Close dropdown/modal that triggered it, then open offcanvas
      var panel = document.getElementById('details-panel');
      if (panel && window.bootstrap) {
        var existing = bootstrap.Offcanvas.getInstance(panel);
        if (existing) existing.hide();
        new bootstrap.Offcanvas(panel).show();
      }
      return;
    }

    var shareButton = event.target.closest('[data-drive-share]');
    if (shareButton) {
      fillShareModal(shareButton);
      return;
    }

    var previewLink = event.target.closest('[data-drive-preview]');
    if (previewLink) {
      event.preventDefault();
      openDrivePreview(previewLink);
      return;
    }

    if (window.CloudApp) return;
    var link = event.target.closest('a[href]');
    if (!link || link.classList.contains('disabled')) {
      return;
    }

    if (link.target && link.target !== '_self') {
      return;
    }

    if (link.hasAttribute('download')) {
      return;
    }

    var href = link.getAttribute('href');
    if (!href || href.charAt(0) === '#') {
      return;
    }

    var targetUrl = urlFor(link.href);
    if (!isDriveUrl(targetUrl) || isDirectFileUrl(targetUrl)) {
      return;
    }

    event.preventDefault();
    navigateTo(targetUrl.toString(), true);
  });

  document.addEventListener('submit', function (event) {
    if (window.CloudApp) return;
    var form = event.target;
    if (!form || form.tagName !== 'FORM') {
      return;
    }

    var actionUrl = currentActionUrl(form);
    if (!isDriveUrl(actionUrl)) {
      return;
    }

    event.preventDefault();

    var method = (form.getAttribute('method') || 'GET').toUpperCase();
    if (method === 'GET') {
      handleGetForm(form, actionUrl);
      return;
    }

    if (form.id === 'upload-form') {
      if (form.dataset.managed === 'true') return;
      submitUploadForm(form, actionUrl);
      return;
    }

    submitDriveForm(form, actionUrl);
  });

  window.addEventListener('popstate', function () {
    if (window.CloudApp) return;
    var targetUrl = urlFor(window.location.href);
    if (isDriveUrl(targetUrl)) {
      navigateTo(targetUrl.toString(), false);
    }
  });

  window.cloudDriveInit = initDrivePage;
  window.driveNavigate = navigateTo;
  window.driveRefresh = function () {
    return navigateTo(window.location.href.split('#')[0], false);
  };

  document.addEventListener('DOMContentLoaded', initDrivePage);

  document.addEventListener('hidden.bs.modal', function (event) {
    if (event.target && event.target.id === 'drive-preview-modal') {
      clearPreviewModal();
    }
  });
})();
