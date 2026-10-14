(function () {
  'use strict';
  var parser = new DOMParser(), pendingNavigation, mutation = Promise.resolve(), busyForms = new WeakSet();
  var direct = /\/(?:download|preview|thumbnail|thumb|avatar|info|text)(?:\/|$)|\/upload\/(?:init|chunk|complete|cancel)$/;
  function t(text) { return (window.CloudTranslations || {})[document.documentElement.lang || 'en']?.[text] || text; }
  function token(hash) {
    if (!hash) return;
    document.querySelectorAll('input[name="csrf_test_name"]').forEach(function (input) { input.value = hash; });
  }
  function toast(message, danger) {
    var box = document.createElement('div');
    box.className = 'alert cloud-toast ' + (danger ? 'alert-danger' : 'alert-success');
    box.setAttribute('role', 'alert'); box.textContent = t(message); document.body.appendChild(box);
    setTimeout(function () { box.remove(); }, 6000);
  }
  function url(value) { try { return new URL(value, location.href); } catch (_) { return null; } }
  function eligible(target) { return target && target.origin === location.origin && !direct.test(target.pathname); }
  function loading(value) {
    document.body.classList.toggle('cloud-loading', value);
    document.getElementById('cloud-shell')?.setAttribute('aria-busy', String(value));
  }
  async function request(target, options) {
    options = options || {};
    var controller = options.controller || new AbortController();
    var timeout = setTimeout(function () { controller.abort('timeout'); }, options.timeout || 30000);
    try {
      var response = await fetch(target, Object.assign({}, options, {signal: controller.signal, credentials: 'same-origin',
        headers: Object.assign({'X-Requested-With': 'XMLHttpRequest', 'X-Cloud-Frame': '1'}, options.headers || {})}));
      token(response.headers.get('X-CSRF-TOKEN'));
      if (!response.headers.get('content-type')?.includes('text/html')) {
        throw new Error(response.status === 403 ? 'Your session or security token expired. Please try again.' : 'The request could not be completed.');
      }
      var html = await response.text();
      if (response.status >= 400) throw new Error(response.status === 403 ? 'You do not have permission to perform this action.' : 'The server could not complete the request.');
      return {document: parser.parseFromString(html, 'text/html'), url: response.url || target};
    } finally { clearTimeout(timeout); }
  }
  function install(result, push, languageChange) {
    var next = result.document.querySelector('#cloud-shell'), current = document.getElementById('cloud-shell');
    if (!next || !current) throw new Error('The page could not be loaded. Please try again.');
    var values = [], open = [];
    if (languageChange) {
      document.querySelectorAll('.modal.show').forEach(function (m) { open.push(m.id); });
      current.querySelectorAll('input:not([type="hidden"]):not([type="file"]), textarea, select').forEach(function (el) {
        values.push({id: el.id, name: el.name, form: el.form?.getAttribute('action'), value: el.value, checked: el.checked});
      });
    }
    document.querySelectorAll('.modal.show, .offcanvas.show').forEach(function (el) {
      window.bootstrap?.Modal.getInstance(el)?.dispose(); window.bootstrap?.Offcanvas.getInstance(el)?.dispose();
    });
    document.querySelectorAll('.modal-backdrop, .offcanvas-backdrop').forEach(function (el) { el.remove(); });
    document.body.classList.remove('modal-open'); document.body.style.removeProperty('overflow'); document.body.style.removeProperty('padding-right');
    current.replaceWith(next);
    document.documentElement.lang = result.document.documentElement.lang || 'en';
    document.title = result.document.title || document.title;
    if (push && result.url !== location.href) history.pushState({cloud: true}, '', result.url);
    else if (!push && !languageChange && result.url !== location.href) history.replaceState({cloud: true}, '', result.url);
    localStorage.setItem('cloud-locale', document.documentElement.lang);
    init(); window.cloudDriveInit?.();
    if (languageChange) {
      values.forEach(function (saved) {
        var el = saved.id ? document.getElementById(saved.id) : Array.from(next.querySelectorAll('[name]')).find(function (v) { return v.name === saved.name && v.form?.getAttribute('action') === saved.form; });
        if (el) { el.value = saved.value; el.checked = saved.checked; }
      });
      open.forEach(function (id) { var el = document.getElementById(id); if (el && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).show(); });
    } else window.scrollTo(0, 0);
    document.dispatchEvent(new CustomEvent('cloud:updated'));
  }
  async function navigate(target, push) {
    if (pendingNavigation) pendingNavigation.abort('superseded');
    var controller = new AbortController(); pendingNavigation = controller; loading(true);
    try { install(await request(target, {controller: controller}), push !== false, false); }
    catch (error) { if (controller.signal.reason !== 'superseded') toast(navigator.onLine ? error.message === 'Failed to fetch' ? 'Connection failed. Please try again.' : error.name === 'AbortError' ? 'The request timed out. Please try again.' : error.message : 'You are offline. Check your connection.', true); }
    finally { if (pendingNavigation === controller) { pendingNavigation = null; loading(false); } }
  }
  function formBusy(form, value) {
    form.setAttribute('aria-busy', String(value));
    form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) {
      button.disabled = value;
      if (value) { var spinner = document.createElement('span'); spinner.className = 'spinner-border spinner-border-sm me-2 cloud-spinner'; spinner.setAttribute('aria-hidden', 'true'); button.prepend(spinner); }
      else button.querySelectorAll('.cloud-spinner').forEach(function (s) { s.remove(); });
    });
  }
  function submit(form, target, submitter) {
    if (busyForms.has(form)) return;
    busyForms.add(form); formBusy(form, true);
    mutation = mutation.catch(function () {}).then(async function () {
      var data = new FormData(form); if (submitter?.name) data.set(submitter.name, submitter.value);
      var csrf = document.querySelector('input[name="csrf_test_name"]'); if (csrf) data.set(csrf.name, csrf.value);
      if (/\/language\/?$/.test(target.pathname)) data.set('return_to', location.pathname + location.search);
      loading(true);
      try { install(await request(target, {method: 'POST', body: data, timeout: Array.from(data.values()).some(function (value) { return value instanceof File && value.size > 0; }) ? 600000 : 30000}), true, /\/language\/?$/.test(target.pathname)); }
      catch (error) { toast(error.name === 'AbortError' ? 'The request timed out. Check the result before trying again.' : error.message, true); }
      finally { formBusy(form, false); busyForms.delete(form); loading(false); }
    });
  }
  function init() {
    document.querySelectorAll('img').forEach(function (img) { img.decoding = 'async'; if (!img.closest('.pc-header')) img.loading = 'lazy'; });
    document.querySelectorAll('.loader-bg').forEach(function (el) { el.style.display = 'none'; });
    window.feather?.replace();
    translate(document.getElementById('cloud-shell'));
  }
  function translate(root) {
    if (!root || document.documentElement.lang !== 'id') return;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT), nodes = [], node;
    while ((node = walker.nextNode())) nodes.push(node);
    nodes.forEach(function (text) {
      if (text.parentElement?.closest('[data-user-content], script, style, textarea, option[value], .file-name, .drive-item-name')) return;
      var source = text.textContent.trim(), translated = t(source);
      if (translated !== source) text.textContent = text.textContent.replace(source, translated);
    });
  }
  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    var link = event.target.closest('a[href]');
    if (!link || link.closest('[data-drive-preview]') || link.hasAttribute('download') || link.dataset.noAjax !== undefined || link.classList.contains('disabled') || link.target && link.target !== '_self') return;
    var href = link.getAttribute('href'); if (!href || href[0] === '#' || link.hasAttribute('data-bs-toggle')) return;
    var target = url(link.href); if (!eligible(target)) return;
    event.preventDefault(); navigate(target.toString(), true);
  });
  document.addEventListener('submit', function (event) {
    if (event.defaultPrevented) return;
    var form = event.target, target = url(form.action || location.href);
    if (!eligible(target) || form.dataset.noAjax !== undefined || form.id === 'upload-form') return;
    event.preventDefault();
    if ((form.method || 'get').toLowerCase() === 'get') { target.search = new URLSearchParams(new FormData(form)).toString(); navigate(target.toString(), true); return; }
    var confirmText = form.dataset.adminConfirm || form.dataset.confirm;
    if (confirmText && !window.confirm(t(confirmText))) return;
    submit(form, target, event.submitter);
  });
  document.addEventListener('click', function (event) {
    if (event.target.closest('#sidebar-hide, #mobile-collapse')) {
      event.preventDefault(); event.stopImmediatePropagation(); var sidebar = document.querySelector('.pc-sidebar');
      if (innerWidth < 1025) {
        sidebar?.classList.toggle('mob-sidebar-active');
        sidebar?.querySelector('.pc-menu-overlay')?.remove();
        if (sidebar?.classList.contains('mob-sidebar-active')) {
          var overlay = document.createElement('div'); overlay.className = 'pc-menu-overlay';
          overlay.addEventListener('click', function () { sidebar.classList.remove('mob-sidebar-active'); overlay.remove(); });
          sidebar.appendChild(overlay);
        }
      } else sidebar?.classList.toggle('pc-sidebar-hide');
    }
  }, true);
  window.addEventListener('popstate', function () { navigate(location.href, false); });
  window.CloudApp = {navigate: navigate, request: request, toast: toast, t: t, token: token, translate: translate};
  new MutationObserver(function (entries) {
    entries.forEach(function (entry) { entry.addedNodes.forEach(function (node) { if (node.nodeType === 1) translate(node); }); });
  }).observe(document.documentElement, {childList: true, subtree: true});
  document.addEventListener('DOMContentLoaded', init);
})();
