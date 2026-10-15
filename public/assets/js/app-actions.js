(function () {
  'use strict';
  var pollTimer, polling = false;
  function boxes(form) { return Array.from(document.querySelectorAll('[data-select-item][form="' + form.id + '"]')); }
  function selection(form) {
    var action = form.elements.bulk_action.value;
    var eligible = boxes(form).filter(function (box) {
      box.disabled = !(box.dataset.selectActions || '').split(' ').includes(action);
      if (box.disabled) box.checked = false;
      return !box.disabled;
    });
    var count = eligible.filter(function (box) { return box.checked; }).length;
    var all = form.querySelector('[data-select-all]');
    all.disabled = !eligible.length; all.checked = eligible.length > 0 && count === eligible.length;
    all.indeterminate = count > 0 && count < eligible.length;
    form.querySelector('[data-selected-count]').textContent = CloudApp.t('{0} selected').replace('{0}', count);
    var submit = form.querySelector('[data-selection-submit]');
    submit.disabled = count === 0 || form.getAttribute('aria-busy') === 'true';
    if (form.dataset.downloadAction) {
      submit.textContent = CloudApp.t(action === 'download' ? 'Download ZIP' : 'Apply');
      submit.classList.toggle('btn-outline-primary', action === 'download');
      submit.classList.toggle('btn-outline-danger', action !== 'download');
    }
  }
  document.addEventListener('change', function (event) {
    var el = event.target;
    var form = el.dataset.selectAll ? document.getElementById(el.dataset.selectAll) : el.form;
    if (!form?.hasAttribute('data-selection-form')) return;
    if (el.dataset.selectAll) boxes(form).forEach(function (box) { if (!box.disabled) box.checked = el.checked; });
    selection(form);
  });
  document.addEventListener('click', function (event) {
    var clear = event.target.closest('[data-select-clear]');
    if (!clear) return;
    var form = document.getElementById(clear.dataset.selectClear);
    boxes(form).forEach(function (box) { box.checked = false; }); selection(form);
  });
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.dataset.downloadAction || form.elements.bulk_action.value !== 'download') return;
    event.preventDefault(); event.stopImmediatePropagation();
    var data = new FormData(form);
    if (!data.getAll('selected[]').length) return;
    var download = document.createElement('form');
    download.action = form.dataset.downloadAction; download.method = 'post'; download.target = '_blank'; download.hidden = true;
    data.forEach(function (value, name) {
      var input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; download.appendChild(input);
    });
    document.body.appendChild(download); download.submit(); download.remove();
  }, true);
  async function pollDelivery() {
    if (polling || document.hidden) return;
    var elements = Array.from(document.querySelectorAll('[data-delivery-id][data-delivery-pending="1"]'));
    if (!elements.length) return;
    polling = true;
    var abort = new AbortController(), timer = setTimeout(function () { abort.abort(); }, 15000);
    try {
      var query = new URLSearchParams(); elements.forEach(function (el) { query.append('selected[]', el.dataset.deliveryId); });
      var response = await fetch(new URL('admin/announcements/delivery?' + query, location.origin + '/'), {credentials:'same-origin', signal:abort.signal, headers:{'Accept':'application/json'}});
      if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Delivery status unavailable.');
      var body = await response.json();
      elements.forEach(function (el) {
        if (!el.isConnected) return;
        var value = body.items[el.dataset.deliveryId]; if (!value) return;
        el.textContent = CloudApp.t('Email delivery') + ': ' + CloudApp.t(value.status) + ' (' + value.sent + '/' + value.total + ')';
        if (value.retrying) el.textContent += ' — ' + CloudApp.t('Retry scheduled');
        el.dataset.deliveryPending = value.queued + value.processing > 0 ? '1' : '0';
        el.classList.toggle('text-danger', value.failed > 0);
        el.title = value.error ? CloudApp.t(value.error) : '';
      });
    } catch (_) {
      elements.forEach(function (el) { if (el.isConnected) el.title = CloudApp.t('Delivery status unavailable. Retrying.'); });
    } finally { clearTimeout(timer); polling = false; schedule(); }
  }
  function schedule() {
    clearTimeout(pollTimer);
    if (document.querySelector('[data-delivery-pending="1"]')) pollTimer = setTimeout(pollDelivery, 10000);
  }
  function init() { document.querySelectorAll('[data-selection-form]').forEach(selection); schedule(); }
  window.cloudActionsInit = init;
  document.addEventListener('DOMContentLoaded', init);
  document.addEventListener('cloud:updated', init);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) schedule(); });
})();
