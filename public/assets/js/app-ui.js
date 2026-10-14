(function () {
  'use strict';
  var parser = new DOMParser(), pendingNavigation, mutation = Promise.resolve(), busyForms = new WeakSet();
  var direct = /\/(?:download|bulk-download|preview|thumbnail|thumb|avatar|info|text)(?:\/|$)|\/upload\/(?:init|chunk|complete|cancel)$/;
  var reverseTranslations;
  var renderedUrl = location.href, savedScroll = new Map(), savedQueries = new Map();
  function t(text) {
    var catalogs = window.CloudTranslations || {}, locale = document.documentElement.lang || 'en';
    if (catalogs[locale]?.[text]) return catalogs[locale][text];
    if (!reverseTranslations) {
      reverseTranslations = {};
      Object.keys(catalogs.id || {}).forEach(function (source) { reverseTranslations[catalogs.id[source]] = source; });
    }
    var source = reverseTranslations[text];
    return source ? catalogs[locale]?.[source] || source : text;
  }
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
  function pagePath(path) { return path.replace(/^\/index\.php(?:\/|$)/, '/'); }
  function eligible(target) { return target && target.origin === location.origin && !direct.test(target.pathname) && !/\/s\/[^/]+$/.test(target.pathname); }
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
        headers: Object.assign({'X-Requested-With': 'XMLHttpRequest', 'X-Cloud-Frame': '1', 'X-Cloud-Context': location.pathname + location.search}, options.headers || {})}));
      token(response.headers.get('X-CSRF-TOKEN'));
      if (!response.headers.get('content-type')?.includes('text/html')) {
        throw new Error(response.status === 403 ? 'Your session or security token expired. Please try again.' : 'The request could not be completed.');
      }
      var html = await response.text();
      if (response.status >= 400) throw new Error(response.status === 403 ? 'You do not have permission to perform this action.' : 'The server could not complete the request.');
      return {document: parser.parseFromString(html, 'text/html'), url: response.url || target, mutation: options.method === 'POST'};
    } finally { clearTimeout(timeout); }
  }
  async function install(result, push, languageChange, shouldInstall) {
    var next = result.document.querySelector('#cloud-shell'), current = document.getElementById('cloud-shell');
    if (!next || !current) throw new Error('The page could not be loaded. Please try again.');
    // Bootstrap callbacks still reference their instance until the transition ends.
    await Promise.all(Array.from(current.querySelectorAll('.modal, .offcanvas')).map(function (el) {
      var instance = window.bootstrap?.Modal.getInstance(el) || window.bootstrap?.Offcanvas.getInstance(el);
      if (!instance?._isTransitioning && !el.classList.contains('showing') && !el.classList.contains('hiding')) return Promise.resolve();
      return new Promise(function (resolve) {
        var timer = setTimeout(done, 500);
        function done() { clearTimeout(timer); ['shown.bs.modal','hidden.bs.modal','shown.bs.offcanvas','hidden.bs.offcanvas'].forEach(function (event) { el.removeEventListener(event, done); }); resolve(); }
        ['shown.bs.modal','hidden.bs.modal','shown.bs.offcanvas','hidden.bs.offcanvas'].forEach(function (event) { el.addEventListener(event, done, {once:true}); });
      });
    }));
    if (shouldInstall && !shouldInstall()) return;
    if (!current.isConnected) return;
    var position = {x: window.scrollX, y: window.scrollY};
    savedScroll.set(renderedUrl, position);
    var previousUrl = url(renderedUrl), nextUrl = url(result.url || location.href);
    if (previousUrl) savedQueries.set(pagePath(previousUrl.pathname), previousUrl.search);
    if (!result.mutation && !languageChange && nextUrl && nextUrl.href !== renderedUrl && savedScroll.has(nextUrl.href)) position = savedScroll.get(nextUrl.href);
    var innerScroll = Array.from(current.querySelectorAll('.table-responsive, .pc-sidebar, .dropdown-notification')).map(function (el) { return {x: el.scrollLeft, y: el.scrollTop}; });
    var selected = Array.from(current.querySelectorAll('[data-select-item]:checked')).map(function (el) { return el.id; });
    var bulkActions = Array.from(current.querySelectorAll('[data-selection-form]')).map(function (form) { return {id:form.id, value:form.elements.bulk_action.value}; });
    var values = [], open = [], preservedModals = [];
    if (languageChange) {
      document.querySelectorAll('.modal.show').forEach(function (m) { open.push(m.id); preservedModals.push(m); });
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
    preservedModals.forEach(function (modal) {
      var replacement = document.getElementById(modal.id);
      if (replacement) {
        modal.classList.remove('show'); modal.style.display = 'none'; modal.removeAttribute('aria-modal'); modal.setAttribute('aria-hidden', 'true');
        replacement.replaceWith(modal);
      }
    });
    document.documentElement.lang = result.document.documentElement.lang || 'en';
    document.title = result.document.title || document.title;
    if (push && result.url !== location.href) history.pushState({cloud: true}, '', result.url);
    else if (!push && !languageChange && result.url !== location.href) history.replaceState({cloud: true}, '', result.url);
    renderedUrl = location.href;
    if (/\/(?:login|register)\/?$/.test(nextUrl?.pathname || '')) { savedScroll.clear(); savedQueries.clear(); }
    localStorage.setItem('cloud-locale', document.documentElement.lang);
    init(); window.cloudDriveInit?.();
    bulkActions.forEach(function (saved) { var form = document.getElementById(saved.id); if (form) form.elements.bulk_action.value = saved.value; });
    selected.forEach(function (id) { var box = document.getElementById(id); if (box && !box.disabled) box.checked = true; });
    window.cloudActionsInit?.();
    if (languageChange) {
      values.forEach(function (saved) {
        var el = saved.id ? document.getElementById(saved.id) : Array.from(next.querySelectorAll('[name]')).find(function (v) { return v.name === saved.name && v.form?.getAttribute('action') === saved.form; });
        if (el) { el.value = saved.value; el.checked = saved.checked; }
      });
      open.forEach(function (id) { var el = document.getElementById(id); if (el && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).show(); });
    }
    Array.from(next.querySelectorAll('.table-responsive, .pc-sidebar, .dropdown-notification')).forEach(function (el, i) { if (innerScroll[i]) { el.scrollLeft = innerScroll[i].x; el.scrollTop = innerScroll[i].y; } });
    window.scrollTo({left:position.x, top:position.y, behavior:'instant'});
    requestAnimationFrame(function () { if (next.isConnected) window.scrollTo({left:position.x, top:position.y, behavior:'instant'}); });
    if (languageChange) window.cloudActionsInit?.();
    next.querySelectorAll('.alert-success, .alert-danger').forEach(function (el) { if (!el.closest('.modal')) toast(el.textContent.trim(), el.classList.contains('alert-danger')); });
    document.dispatchEvent(new CustomEvent('cloud:updated'));
  }
  async function navigate(target, push) {
    if (pendingNavigation) pendingNavigation.abort('superseded');
    var controller = new AbortController(); pendingNavigation = controller; loading(true);
    try { await install(await request(target, {controller: controller}), push !== false, false, function () { return pendingNavigation === controller && !controller.signal.aborted; }); }
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
    var data = new FormData(form); if (submitter?.name) data.set(submitter.name, submitter.value);
    mutation = mutation.catch(function () {}).then(async function () {
      var csrf = document.querySelector('input[name="csrf_test_name"]'); if (csrf) data.set(csrf.name, csrf.value);
      if (/\/language\/?$/.test(target.pathname)) data.set('return_to', location.pathname + location.search);
      loading(true);
      try { await install(await request(target, {method: 'POST', body: data, timeout: Array.from(data.values()).some(function (value) { return value instanceof File && value.size > 0; }) ? 600000 : 30000}), true, /\/language\/?$/.test(target.pathname)); }
      catch (error) { toast(error.name === 'AbortError' ? 'The request timed out. Check the result before trying again.' : error.message, true); }
      finally { formBusy(form, false); busyForms.delete(form); loading(false); }
    });
  }
  function init() {
    document.querySelectorAll('img').forEach(function (img) { img.decoding = 'async'; if (!img.closest('.pc-header')) img.loading = 'lazy'; });
    document.querySelectorAll('.loader-bg').forEach(function (el) { el.style.display = 'none'; });
    window.feather?.replace();
    document.querySelectorAll('[data-bs-toggle="dropdown"][data-bs-boundary="viewport"]').forEach(function (el) {
      window.bootstrap?.Dropdown.getOrCreateInstance(el, {popperConfig:{strategy:'fixed'}});
    });
    translate(document.getElementById('cloud-shell'));
  }
  function translate(root) {
    if (!root) return;
    root.querySelectorAll?.('[data-i18n-template]').forEach(function (el) { if (el.dataset.i18nTemplate) el.textContent = t(el.dataset.i18nTemplate).replace('{0}', el.dataset.i18nValue || ''); });
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT), nodes = [], node;
    while ((node = walker.nextNode())) nodes.push(node);
    nodes.forEach(function (text) {
      if (text.parentElement?.closest('[data-user-content], [data-i18n-template], script, style, textarea, .file-name, .drive-item-name, .drive-grid-name, .upload-file-name, #details-name, #details-owner, #drive-preview-title, #drive-preview-text')) return;
      var source = text.textContent.trim(), translated = t(source);
      if (translated !== source) text.textContent = text.textContent.replace(source, translated);
    });
    root.querySelectorAll?.('[placeholder], [title], [aria-label]').forEach(function (el) {
      ['placeholder', 'title', 'aria-label'].forEach(function (attr) { if (el.hasAttribute(attr)) el.setAttribute(attr, t(el.getAttribute(attr))); });
    });
  }
  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    var link = event.target.closest('a[href]');
    if (!link || link.closest('[data-drive-preview]') || link.hasAttribute('download') || link.dataset.noAjax !== undefined || link.classList.contains('disabled') || link.target && link.target !== '_self') return;
    var href = link.getAttribute('href'); if (!href || href[0] === '#' || link.hasAttribute('data-bs-toggle')) return;
    if (link.hasAttribute('data-cloud-back') && history.state?.cloud && history.length > 1) { event.preventDefault(); history.back(); return; }
    var target = url(link.href); if (!eligible(target)) return;
    if (link.closest('.pc-sidebar') && !target.search && savedQueries.has(pagePath(target.pathname))) target.search = savedQueries.get(pagePath(target.pathname));
    if (pagePath(target.pathname) === pagePath(location.pathname) && target.search) {
      var params = new URLSearchParams(location.search);
      target.searchParams.forEach(function (value, key) { params.set(key, value); }); target.search = params.toString();
    }
    event.preventDefault(); navigate(target.toString(), true);
  });
  document.addEventListener('submit', function (event) {
    if (event.defaultPrevented) return;
    var form = event.target, target = url(form.action || location.href);
    var avatarMutation = target?.origin === location.origin && (form.method || 'get').toLowerCase() === 'post' && /\/settings\/avatar(?:\/delete)?\/?$/.test(target.pathname);
    if ((!eligible(target) && !avatarMutation) || form.dataset.noAjax !== undefined || form.id === 'upload-form') return;
    event.preventDefault();
    if ((form.method || 'get').toLowerCase() === 'get') {
      var params = new URLSearchParams(pagePath(target.pathname) === pagePath(location.pathname) ? location.search : target.search);
      var changed = false;
      new FormData(form).forEach(function (value, key) {
        if ((params.get(key) || '') !== String(value) && form.elements.namedItem(key)?.type !== 'hidden') changed = true;
        params.set(key, value);
      });
      if (changed) params.delete('page');
      target.search = params.toString(); navigate(target.toString(), true); return;
    }
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
  history.scrollRestoration = 'manual';
  new MutationObserver(function (entries) {
    entries.forEach(function (entry) { entry.addedNodes.forEach(function (node) { if (node.nodeType === 1) translate(node); }); });
  }).observe(document.documentElement, {childList: true, subtree: true});
  document.addEventListener('DOMContentLoaded', init);
})();
