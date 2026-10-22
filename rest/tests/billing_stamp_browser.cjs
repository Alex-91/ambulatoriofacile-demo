const {chromium}=require('playwright');
const fs=require('fs'),assert=require('assert'),path=require('path');
process.chdir(path.resolve(__dirname,'../..'));
(async()=>{
 const browser=await chromium.launch({headless:true, ...(process.env.BROWSER_CHANNEL ? {channel:process.env.BROWSER_CHANNEL} : {})});
 try {
  const page=await browser.newPage();
  await page.setContent(fs.readFileSync('rest/build/business-billing/form.html','utf8').replace(/<script[\s\S]*?<\/script>/g,''));
  await page.addScriptTag({path:'public/assets/js/billing-stamp.js'});
  const field=name=>page.locator('[name="'+name+'"]');
  await field('stamp_mode').selectOption('automatic');
  await field('item_unit_amount[]').first().fill('77.47');
  assert.equal(await field('stamp_duty_amount').inputValue(),'0.00');
  await field('item_unit_amount[]').first().fill('77.48');
  assert.equal(await field('stamp_duty_amount').inputValue(),'2.00');
  await field('stamp_duty_amount').fill('0');
  await field('item_unit_amount[]').first().fill('100');
  assert.equal(await field('stamp_mode').inputValue(),'manual');
  assert.equal(await field('stamp_duty_amount').inputValue(),'0');
  await field('stamp_mode').selectOption('automatic');
  await field('stamp_charge_customer').selectOption('0');
  assert((await page.locator('#stamp-summary').innerText()).includes('100,00'));
  await field('vat_rate').fill('22');
  assert.equal(await field('stamp_duty_amount').inputValue(),'0.00');
  await field('item_tax_treatment[]').first().selectOption('N4');
  assert.equal(await field('stamp_duty_amount').inputValue(),'2.00');
  await field('stamp_method').selectOption('virtual');
  assert.equal(await field('stamp_identifier').isEnabled(),false);
  assert.equal(await field('stamp_authorization_number').isEnabled(),true);
  console.log('PASS: browser threshold, manual override, re-enable, issuer charge, VAT and method controls');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
