// Run with Node and Playwright available in NODE_PATH. All requests use test fixtures.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'public/assets/js/drive-upload.js'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'public/template/dist/assets/css/style.css'), 'utf8')
  + fs.readFileSync(path.join(root, 'public/assets/css/drive-ui.css'), 'utf8');
const markup = '<!DOCTYPE html><html lang="en"><head><style>' + styles + '</style></head><body>' +
  '<main id="cloud-shell"><h1 style="padding:24px">My Drive</h1><div id="upload-modal" class="modal" style="display:none">' +
  '<form id="upload-form" action="http://cloud.test/drive/upload" data-chunk-size="4">' +
  '<input name="csrf_test_name" value="token"><input name="parent_id" value="7"><input name="context" value="drive">' +
  '<input type="file" id="drive-files" multiple><div id="upload-file-list"></div></form></div>' +
  '<button data-drive-extract data-action="http://cloud.test/drive/items/23/extract" data-name="bundle.zip">Extract ZIP</button>' +
  '<div id="extract-modal"><form id="extract-form"><input name="target_folder_id" value="7"></form><p id="extract-file-name"></p></div></main></body></html>';

async function setup(browser) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: markup }));
  await page.goto('http://cloud.test/drive');
  await page.evaluate(() => {
    window.requests = []; window.refreshes = 0; window.hiddenModals = 0;
    window.bootstrap = { Modal: { getInstance: () => ({ hide() { window.hiddenModals++; document.querySelector('#upload-modal').style.display = 'none'; } }) } };
    window.CloudApp = { t: value => value, toast: () => {}, token: () => {} };
    window.driveRefresh = () => window.refreshes++;
    window.XMLHttpRequest = class {
      constructor() { this.upload = new EventTarget(); }
      open(method, url) { this.url = url; }
      setRequestHeader() {}
      getResponseHeader() { return null; }
      send(data) { this.data = data; window.requests.push(this); }
      abort() { this.onabort(); this.onloadend(); }
      progress(loaded, total) { this.upload.dispatchEvent(new ProgressEvent('progress', { lengthComputable: true, loaded, total })); }
      respond(payload, status = 200) {
        this.status = status; this.responseText = JSON.stringify({ csrf_name: 'csrf_test_name', csrf_hash: 'fresh-token', ...payload });
        this.onload(); this.onloadend();
      }
    };
  });
  await page.addScriptTag({ content: source });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
  return page;
}
async function upload(page, files) {
  await page.locator('#drive-files').setInputFiles(files.map(([name, body]) => ({ name, mimeType: 'text/plain', buffer: Buffer.from(body) })));
  await page.evaluate(() => document.querySelector('#upload-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
}
async function requestAt(page, index, suffix) {
  await page.waitForFunction(count => window.requests.length > count, index);
  const url = await page.evaluate(index => window.requests[index].url, index);
  assert.ok(url.endsWith(suffix), url + ' should end in ' + suffix);
}
async function respond(page, index, payload, status) {
  await page.evaluate(({ index, payload, status }) => window.requests[index].respond(payload, status), { index, payload, status: status || 200 });
}
async function status(page, index, expected) {
  await page.waitForFunction(({ index, expected }) => document.querySelectorAll('.upload-status')[index]?.textContent === expected, { index, expected });
}

(async () => {
  const browser = await chromium.launch({ channel: process.env.TEST_BROWSER_CHANNEL || (process.platform === 'win32' ? 'msedge' : undefined), headless: true });
  try {
    let page = await setup(browser);
    await upload(page, [['large.txt', '0123456789']]);
    await requestAt(page, 0, '/init');
    await respond(page, 0, { success: true, upload_id: 'session', chunk_size: 4, total_chunks: 3 });
    await requestAt(page, 1, '/chunk');
    await page.waitForTimeout(200);
    await page.evaluate(() => window.requests[1].progress(2, 4));
    assert.equal(await page.locator('.upload-percent').textContent(), '20%');
    assert.match(await page.locator('.upload-metrics').textContent(), /\/s · About .* left/);
    assert.equal(await page.evaluate(() => window.hiddenModals), 1);
    assert.ok(await page.locator('#drive-transfer-panel').isVisible());
    await page.locator('.transfer-toggle').click();
    assert.ok(!await page.locator('.drive-transfer-list').isVisible());
    assert.equal(await page.locator('.transfer-percent').textContent(), '20%');
    await page.locator('.transfer-toggle').click();
    await page.evaluate(() => {
      document.querySelector('#cloud-shell').innerHTML = '<h1>Another folder</h1><input name="csrf_test_name" value="navigation-token">';
      history.pushState({}, '', '/drive?folder=9'); document.dispatchEvent(new Event('cloud:updated'));
    });
    assert.ok(await page.locator('#drive-transfer-panel').isVisible());
    assert.equal(await page.evaluate(() => window.requests[0].data.get('parent_id')), '7');
    await respond(page, 1, { success: true }); await requestAt(page, 2, '/chunk');
    await respond(page, 2, { success: false, error: 'Connection lost' }, 422);
    await status(page, 0, 'Failed');
    assert.equal(await page.evaluate(() => window.refreshes), 0);
    await page.locator('.upload-retry').click(); await requestAt(page, 3, '/chunk');
    assert.equal(await page.evaluate(() => window.requests[3].data.get('chunk_index')), '1');
    await respond(page, 3, { success: true }); await requestAt(page, 4, '/chunk');
    await respond(page, 4, { success: true }); await requestAt(page, 5, '/complete');
    assert.equal(await page.locator('.upload-percent').textContent(), '99%');
    await page.waitForTimeout(100);
    await respond(page, 5, { success: true }); await status(page, 0, 'Completed');
    assert.equal(await page.locator('.upload-percent').textContent(), '100%');
    assert.equal(await page.evaluate(() => window.refreshes), 1);
    await page.close();
    console.log('PASS: live chunk progress, ETA, minimize, page navigation, resume, server completion');

    page = await setup(browser);
    await upload(page, [['first.txt', 'one'], ['second.txt', 'two'], ['queued.txt', 'end']]);
    await requestAt(page, 0, '/upload');
    await page.locator('.upload-cancel').nth(2).click(); await status(page, 2, 'Cancelled');
    await page.locator('.upload-cancel').nth(0).click(); await status(page, 0, 'Cancelled');
    await requestAt(page, 1, '/upload');
    assert.equal(await page.evaluate(() => window.requests[1].data.get('files[]').name), 'second.txt');
    await respond(page, 1, { success: false, error: 'Storage quota is full.' }, 422); await status(page, 1, 'Failed');
    await page.locator('.upload-retry').nth(1).click(); await requestAt(page, 2, '/upload');
    await page.evaluate(() => window.requests[2].progress(3, 3));
    await status(page, 1, 'Processing…');
    await respond(page, 2, { success: true }); await status(page, 1, 'Completed');
    assert.equal(await page.evaluate(() => window.requests.length), 3);
    await page.locator('.transfer-clear').click();
    assert.equal(await page.locator('.upload-file-item').count(), 0);
    await page.close();
    console.log('PASS: cancel active and queued uploads, queue continues, errors and simple retry');

    page = await setup(browser);
    await upload(page, [['cancel-chunks.txt', '0123456789'], ['next.txt', 'ok']]);
    await requestAt(page, 0, '/init'); await respond(page, 0, { success: true, upload_id: 'cancel-session', chunk_size: 4, total_chunks: 3 });
    await requestAt(page, 1, '/chunk'); await page.locator('.upload-cancel').first().click();
    await requestAt(page, 2, '/cancel'); await respond(page, 2, { success: true });
    await requestAt(page, 3, '/upload');
    await respond(page, 3, { success: true }); await status(page, 1, 'Completed');
    await page.close();
    console.log('PASS: chunk cancellation waits for server cleanup before starting the next upload');

    page = await setup(browser);
    await page.locator('[data-drive-extract]').click();
    await page.evaluate(() => document.querySelector('#extract-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    await requestAt(page, 0, '/23/extract'); await status(page, 0, 'Extracting…');
    assert.equal(await page.evaluate(() => window.requests[0].data.get('target_folder_id')), '7');
    assert.equal(await page.locator('.upload-cancel').isVisible(), false);
    await respond(page, 0, { success: true, folder: { id: 90 }, folder_url: 'http://cloud.test/drive?folder=90', message: 'Archive extracted.' }); await status(page, 0, 'Completed');
    assert.equal(await page.locator('.upload-open').getAttribute('href'), 'http://cloud.test/drive?folder=90');
    assert.ok(await page.locator('.upload-open').isVisible());
    await page.close();
    console.log('PASS: extraction destination, processing indicator and completion');

    page = await setup(browser);
    await page.addScriptTag({ path: path.join(root, 'public/assets/js/translations.js') });
    await page.evaluate(() => {
      document.documentElement.lang = 'id'; window.CloudApp.t = value => window.CloudTranslations.id[value] || value;
    });
    await upload(page, [['laporan.txt', '0123456789']]);
    await requestAt(page, 0, '/init'); await respond(page, 0, { success: true, upload_id: 'visual', chunk_size: 4, total_chunks: 3 });
    await requestAt(page, 1, '/chunk'); await page.waitForTimeout(300);
    await page.evaluate(() => window.requests[1].progress(2, 4));
    assert.equal(await page.locator('.transfer-title').textContent(), 'Transfer');
    assert.match(await page.locator('.upload-metrics').textContent(), /Sekitar .* lagi/);
    fs.mkdirSync(path.join(root, 'build/screenshots'), { recursive: true });
    await page.waitForTimeout(700);
    await page.screenshot({ path: path.join(root, 'build/screenshots/transfers-desktop.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    const bounds = await page.locator('#drive-transfer-panel').boundingBox();
    assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 390);
    await page.screenshot({ path: path.join(root, 'build/screenshots/transfers-mobile.png') });
    await page.close();
    console.log('PASS: Indonesian labels and mobile layout');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
