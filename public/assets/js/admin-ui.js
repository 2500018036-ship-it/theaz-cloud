(function () {
  'use strict';

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

  function hasAdminSegment(pathname) {
    return pathname.split('/').filter(Boolean).some(function (segment) {
      return segment.toLowerCase() === 'admin';
    });
  }

  function isAdminUrl(url) {
    return url && url.origin === window.location.origin && hasAdminSegment(url.pathname);
  }

  function setBusy(isBusy) {
    document.body.classList.toggle('drive-busy', isBusy);
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
    var nextDocument = parser.parseFromString(html, 'text/html');
    var nextContent = nextDocument.querySelector('.pc-content');
    var currentContent = document.querySelector('.pc-content');

    if (!nextContent || !currentContent) {
      window.location.href = targetUrl;
      return;
    }

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
      window.history.pushState({ admin: true }, '', targetUrl);
    }

    if (window.feather && typeof window.feather.replace === 'function') {
      window.feather.replace();
    }

    window.scrollTo(0, 0);
  }

  function handleHtmlResponse(response, fallbackUrl, pushState) {
    var responseUrl = urlFor(response.url || fallbackUrl);
    if (!isAdminUrl(responseUrl)) {
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
    setBusy(true);

    return fetch(targetUrl, {
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (response) {
        return handleHtmlResponse(response, targetUrl, pushState);
      })
      .catch(function () {
        showToast('The admin page could not be loaded.');
      })
      .finally(function () {
        setBusy(false);
      });
  }

  function currentActionUrl(form) {
    return urlFor(form.getAttribute('action') || window.location.href);
  }

  function submitForm(form, actionUrl) {
    if (form.dataset.adminConfirm && !window.confirm(form.dataset.adminConfirm)) {
      return Promise.resolve();
    }

    toArray(form.querySelectorAll('button[type="submit"]')).forEach(function (button) {
      button.disabled = true;
    });

    setBusy(true);

    return fetch(actionUrl.toString(), {
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
        showToast('The admin action could not be completed.');
        toArray(form.querySelectorAll('button[type="submit"]')).forEach(function (button) {
          button.disabled = false;
        });
      })
      .finally(function () {
        setBusy(false);
      });
  }

  document.addEventListener('click', function (event) {
    var link = event.target.closest('a[href]');
    if (!link || link.classList.contains('disabled')) {
      return;
    }

    if (link.target && link.target !== '_self') {
      return;
    }

    var href = link.getAttribute('href');
    if (!href || href.charAt(0) === '#') {
      return;
    }

    var targetUrl = urlFor(link.href);
    if (!isAdminUrl(targetUrl)) {
      return;
    }

    event.preventDefault();
    navigateTo(targetUrl.toString(), true);
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || form.tagName !== 'FORM') {
      return;
    }

    var actionUrl = currentActionUrl(form);
    if (!isAdminUrl(actionUrl)) {
      return;
    }

    event.preventDefault();

    var method = (form.getAttribute('method') || 'GET').toUpperCase();
    if (method === 'GET') {
      var params = new URLSearchParams(new FormData(form));
      actionUrl.search = params.toString();
      navigateTo(actionUrl.toString(), true);
      return;
    }

    submitForm(form, actionUrl);
  });

  window.addEventListener('popstate', function () {
    var targetUrl = urlFor(window.location.href);
    if (isAdminUrl(targetUrl)) {
      navigateTo(targetUrl.toString(), false);
    }
  });
})();
