const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const styles = fs.readFileSync(path.join(root, 'public/template/dist/assets/css/style.css'), 'utf8')
  + fs.readFileSync(path.join(root, 'public/assets/css/drive-ui.css'), 'utf8');
const photos = [1, 2, 3].map(id => '<article class="drive-photo-card"><a data-drive-preview href="/drive/items/' + id + '/preview"><img alt="Photo ' + id + '" src="/photo/' + id + '"></a><div class="drive-photo-caption">Photo ' + id + '</div></article>').join('');
const html = '<!doctype html><html><head><style>' + styles + '</style></head><body><main style="padding:24px"><h1>Galeri foto</h1><div class="drive-photo-gallery" data-drive-gallery>' + photos + '</div>' +
  '<form id="drive-bulk" data-selection-form data-download-action="/drive/bulk-download" action="/drive/bulk" method="post">' +
  '<input name="csrf_test_name" value="test-token"><select name="bulk_action"><option value="download">Unduh ZIP</option></select>' +
  '<input data-select-all="drive-bulk" type="checkbox"><span data-selected-count></span><button data-selection-submit type="submit">Unduh ZIP</button></form>' +
  '<input data-select-item data-select-actions="download" type="checkbox" form="drive-bulk" name="selected[]" value="1"><input data-select-item data-select-actions="download" type="checkbox" form="drive-bulk" name="selected[]" value="2"></main>' +
  '<div class="modal" id="drive-preview-modal"><div class="modal-dialog"><div class="modal-content"><div class="modal-header">' +
  '<h5 id="drive-preview-title"></h5><span id="drive-preview-meta"></span><button id="gallery-previous">Previous</button><span id="gallery-counter"></span><button id="gallery-next">Next</button><a id="drive-preview-download"></a></div>' +
  '<div class="modal-body"><div id="drive-preview-loading"></div><div id="drive-preview-error" class="d-none"></div><img id="drive-preview-image"></div></div></div></div></body></html>';

(async () => {
  const browser = await chromium.launch({ channel: process.env.TEST_BROWSER_CHANNEL || (process.platform === 'win32' ? 'msedge' : undefined), headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1100, height: 800 } });
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      const match = url.pathname.match(/items\/(\d+)\/preview-info/);
      if (match) {
        const id = Number(match[1]);
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true,
          item: { name: 'Photo ' + id, mime_type: 'image/png', size_bytes: 1024, preview_kind: 'image', preview_url: '/photo/' + id, download_url: '/drive/items/' + id + '/download' } }) });
      }
      if (url.pathname.startsWith('/photo/')) {
        const colors = ['#719cce', '#9ecdad', '#e0b696']; const color = colors[(Number(url.pathname.split('/').pop()) - 1) % 3];
        return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"><rect width="300" height="300" fill="' + color + '"/><circle cx="150" cy="150" r="90" fill="white" opacity=".3"/></svg>' });
      }
      return route.fulfill({ contentType: 'text/html', body: html });
    });
    await page.goto('http://cloud.test/drive?view=gallery');
    await page.evaluate(() => {
      window.CloudApp = { t: value => value, token: () => {} };
      window.bootstrap = { Modal: { getOrCreateInstance: element => ({ show() { element.classList.add('show'); element.style.display = 'block'; } }) } };
      window.submitted = [];
      HTMLFormElement.prototype.submit = function () { window.submitted.push({ url: this.action, target: this.target, values: Array.from(new FormData(this).entries()) }); };
    });
    await page.addScriptTag({ path: path.join(root, 'public/assets/js/drive-ui.js') });
    await page.addScriptTag({ path: path.join(root, 'public/assets/js/app-actions.js') });
    await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
    await page.locator('[data-drive-gallery] a').first().click();
    await page.waitForFunction(() => document.querySelector('#drive-preview-title').textContent === 'Photo 1');
    assert.equal(await page.locator('#gallery-counter').textContent(), '1 / 3');
    assert.ok(await page.locator('#gallery-previous').isDisabled());
    await page.locator('#gallery-next').click();
    await page.waitForFunction(() => document.querySelector('#drive-preview-title').textContent === 'Photo 2');
    await page.keyboard.press('ArrowRight');
    await page.waitForFunction(() => document.querySelector('#drive-preview-title').textContent === 'Photo 3');
    assert.ok(await page.locator('#gallery-next').isDisabled());
    await page.keyboard.press('ArrowLeft');
    await page.waitForFunction(() => document.querySelector('#drive-preview-title').textContent === 'Photo 2');
    await page.evaluate(() => { const modal = document.querySelector('#drive-preview-modal'); modal.style.display = 'none'; modal.classList.remove('show'); document.dispatchEvent(Object.assign(new Event('hidden.bs.modal'), {})); });
    await page.locator('[data-select-all]').check();
    await page.locator('[data-selection-submit]').click();
    const result = await page.evaluate(() => window.submitted[0]);
    assert.ok(result.url.endsWith('/drive/bulk-download')); assert.equal(result.target, '_blank');
    assert.deepEqual(result.values.filter(([key]) => key === 'selected[]').map(([, value]) => value), ['1', '2']);
    assert.equal(result.values.find(([key]) => key === 'csrf_test_name')[1], 'test-token');
    await page.setViewportSize({ width: 390, height: 844 });
    const cards = await page.locator('.drive-photo-card').all();
    for (const card of cards) { const bounds = await card.boundingBox(); assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 390); }
    fs.mkdirSync(path.join(root, 'build/screenshots'), { recursive: true });
    await page.screenshot({ path: path.join(root, 'build/screenshots/gallery-mobile.png') });
    assert.deepEqual(errors, []);
    console.log('PASS: gallery thumbnails, next/previous photos, keyboard navigation, mobile layout and selected ZIP download');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
