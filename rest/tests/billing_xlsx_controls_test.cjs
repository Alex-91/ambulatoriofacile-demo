const {chromium} = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');
const assert = require('node:assert/strict');
const {pathToFileURL} = require('node:url');
const path = require('node:path');
(async () => {
  const browser = await chromium.launch({headless:true,channel:'msedge'});
  try {
    const page = await browser.newPage({viewport:{width:1100,height:1000}});
    await page.goto(pathToFileURL(path.resolve('build/billing-xlsx-controls.html')).href);
    const selected = () => page.evaluate(() => new FormData(document.querySelector('form')).getAll('xlsx_columns[]'));
    assert.equal((await selected()).length, 6);
    await page.locator('.billing-xlsx-field').filter({has:page.locator('input[value="stamp_duty_amount"]')}).locator('[data-xlsx-move="up"]').click();
    assert.deepEqual((await selected()).slice(-2), ['stamp_duty_amount','subtotal_amount']);
    await page.locator('input[value="patient_tax_code"]').check();
    assert.equal((await selected()).at(-1), 'patient_tax_code');
    await page.screenshot({path:'build/billing-xlsx-controls.png',fullPage:true});
    for (const checkbox of await page.locator('#billing-xlsx-fields input').all()) await checkbox.uncheck();
    assert.equal(await page.locator('form').evaluate(f => f.checkValidity()),false);
    await page.locator('input[value="document_number"]').check();
    assert.equal(await page.locator('form').evaluate(f => f.checkValidity()),true);
    await page.setViewportSize({width:390,height:844});
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true);
    console.log('PASS: actual settings controls select, reorder, serialize and reject empty selection; mobile has no horizontal overflow.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
