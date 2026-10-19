const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try {
  const page=await browser.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/list' || url.pathname==='/ts') return route.fulfill({contentType:'text/html',body:fs.readFileSync(path.join(__dirname,'../build/billing-export-'+(url.pathname==='/list'?'list':'ts')+'.html'),'utf8')});
   if(url.pathname==='/public/assets/js/billing-csv-export.js') return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.join(__dirname,'../../public/assets/js/billing-csv-export.js'),'utf8')});
   if(url.pathname==='/public/assets/css/billing-ts-ui.css') return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(__dirname,'../../public/assets/css/billing-ts-ui.css'),'utf8')});
   return route.fulfill({body:''});
  });
  for(const width of [1280,390]) {
   await page.setViewportSize({width,height:900}); await page.goto('http://synthetic.invalid/list');
   await page.locator('#billing-select-all').check();
   assert.equal(await page.locator('.billing-document-select:checked').count(),10);
   assert.equal(await page.locator('#billing-bulk-send').isDisabled(),true); // TS disabled does not block exports.
   for(const id of ['csv','xlsx','pdf']) assert.equal(await page.locator('#billing-bulk-'+id).isEnabled(),true);
   await page.locator('#billing-select-all-filtered').click();
   assert.equal(await page.locator('.billing-document-select:checked').count(),14);
   assert.equal(await page.locator('#billing-bulk-inputs input').count(),14);
   await page.goto('http://synthetic.invalid/ts');
   await page.locator('#billing-csv-sent-all').check();
   assert.equal(await page.locator('#billing-ts-export-inputs input').count(),2);
   assert.equal(await page.locator('#billing-ts-xlsx-btn').isEnabled(),true);
   await page.locator('#billing-ts-check-all').check();
   assert.equal(await page.locator('#billing-ts-export-inputs input').count(),4);
  }
  assert.deepEqual(errors,[]); console.log('PASS: invoice/TS selections, page/all filtered selection, TS eligibility separated from exports, desktop/mobile, no JS errors.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
