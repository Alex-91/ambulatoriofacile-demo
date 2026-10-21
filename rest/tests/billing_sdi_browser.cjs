// Run with playwright-core on NODE_PATH. All responses are local; no app/DB boot.
const {chromium} = require('playwright-core');
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const {execFileSync} = require('child_process');
const root = path.resolve(__dirname, '../..');
(async () => {
  const html = execFileSync('php', [path.join(__dirname, 'billing_sdi_fixture.php')], {encoding:'utf8'});
  const browser = await chromium.launch({headless:true});
  try {
    const page = await browser.newPage({viewport:{width:1440,height:1100}});
    const errors = [], unexpected = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      // Existing shared styles import Google Fonts; keep this offline fixture on fallback fonts.
      if (url.origin === 'https://fonts.googleapis.com') return route.fulfill({contentType:'text/css',body:''});
      if (url.origin !== 'http://sdi.test') { unexpected.push(url.href); return route.abort(); }
      if (url.pathname === '/') return route.fulfill({contentType:'text/html', body:html});
      const file = path.join(root, url.pathname);
      if (!url.pathname.startsWith('/public/') || !fs.existsSync(file)) { unexpected.push(url.href); return route.abort(); }
      const type = file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'application/javascript' : 'application/octet-stream';
      return route.fulfill({contentType:type, body:fs.readFileSync(file)});
    });
    await page.goto('http://sdi.test/');
    await page.waitForFunction(() => document.getElementById('sdi-channel-description').textContent.length > 0);
    for (const channel of ['portal','pec','api']) {
      await page.locator(`[data-channel="${channel}"]`).click();
      assert.equal(await page.locator('[aria-pressed="true"]').count(), 1);
      for (const scenario of ['delivered','rejected','unavailable','pending']) {
        await page.locator('#sdi-scenario').selectOption(scenario);
        assert(await page.locator('#sdi-report').isDisabled());
        await page.locator('#sdi-run').click();
        assert.equal(await page.locator('#sdi-timeline li').count(), 4);
        assert.match(await page.locator('#sdi-result').innerText(), /simulazione/);
        assert(await page.locator('#sdi-report').isEnabled());
      }
    }
    const xmlDownload = page.waitForEvent('download');
    await page.locator('#sdi-xml').click();
    const xml = await xmlDownload;
    assert.equal(xml.suggestedFilename(), 'DEMO-NON-TRASMETTERE.xml');
    const xmlContent = fs.readFileSync(await xml.path(), 'utf8');
    assert.match(xmlContent, /NON TRASMETTERE/);
    assert.equal(await page.evaluate(value => new DOMParser().parseFromString(value, 'application/xml').querySelector('parsererror') === null, xmlContent), true);
    const reportDownload = page.waitForEvent('download');
    await page.locator('#sdi-report').click();
    const report = fs.readFileSync(await (await reportDownload).path(), 'utf8');
    assert.match(report, /NON È UNA RICEVUTA UFFICIALE/);
    assert.match(report, /"network_used": false/);
    await page.locator('#sdi-reset').click();
    assert(await page.locator('#sdi-report').isDisabled());
    assert.equal(await page.locator('#sdi-timeline li').count(), 0);
    if (process.env.SDI_SCREENSHOT_DIR) {
      fs.mkdirSync(process.env.SDI_SCREENSHOT_DIR, {recursive:true});
      await page.screenshot({path:path.join(process.env.SDI_SCREENSHOT_DIR,'desktop.png'),fullPage:true});
    }
    await page.setViewportSize({width:390,height:844});
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    await page.locator('[data-channel="pec"]').click();
    await page.locator('#sdi-scenario').selectOption('delivered');
    await page.locator('#sdi-run').click();
    if (process.env.SDI_SCREENSHOT_DIR) await page.screenshot({path:path.join(process.env.SDI_SCREENSHOT_DIR,'mobile.png'),fullPage:true});
    assert.deepEqual(errors, []);
    assert.deepEqual(unexpected, []);
    console.log('PASS: 12 channel/outcome combinations, reset, XML and report downloads, mobile layout; no external requests or JavaScript errors.');
  } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
