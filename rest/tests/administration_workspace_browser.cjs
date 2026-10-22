const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),http=require('http'),assert=require('assert');
const {execFileSync}=require('child_process');
const root=path.resolve(__dirname,'../..'),output=path.join(root,'rest/build/administration');fs.mkdirSync(output,{recursive:true});
const server=http.createServer((req,res)=>{
 if(req.method!=='GET'){res.writeHead(405);res.end();return;}
 const url=new URL(req.url,'http://localhost');
 if(url.pathname.startsWith('/public/')){
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.writeHead(404);res.end();return;}
  res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':'application/octet-stream');res.end(fs.readFileSync(file));return;
 }
 try{res.setHeader('Content-Type','text/html; charset=utf-8');res.end(execFileSync(process.env.PHP_BIN||'php',['-d','xdebug.mode=off',path.join(root,'ops/administration-workspace-preview.php'),url.searchParams.get('mode')||'advanced']));}catch(e){res.writeHead(500);res.end(String(e));}
});
(async()=>{await new Promise(r=>server.listen(0,'127.0.0.1',r));let browser;
try{browser=await chromium.launch({headless:true});const page=await browser.newPage({viewport:{width:1586,height:980}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.route('**/*',r=>new URL(r.request().url()).hostname==='127.0.0.1'?r.continue():r.abort());const url='http://127.0.0.1:'+server.address().port;
await page.goto(url);assert.equal(await page.locator('.af-unified-menu a').count(),6);assert.equal(await page.locator('.administration-area-tabs a').count(),2);assert.equal(await page.locator('.bw-row:visible').count(),6);
await page.locator('#bw-select-all').check();assert.equal(await page.locator('#bw-bulk-inputs input').count(),6);assert.equal(await page.locator('#bw-ts-count').innerText(),'(1)');
assert.equal(await page.locator('.bw-row-actions a').count(),6);assert.equal(await page.locator('.bw-send-one:enabled').count(),1);
await page.evaluate(()=>{window.downloadSelectedBillingCsv=(form,button,url,refresh,format)=>{window.exported={ids:new FormData(form).getAll('billing_document_ids[]'),format};refresh();return Promise.resolve(true);};});
await page.locator('#bw-export').click();assert.deepEqual(await page.evaluate(()=>window.exported),{ids:['1','2','3','4','5','6'],format:'xlsx'});
await page.locator('#bw-search').fill('Elena');assert.equal(await page.locator('.bw-row:visible').count(),1);assert.equal(await page.locator('#bw-bulk-inputs input').count(),1);assert.match(await page.locator('#bw-kpi-due').innerText(),/80,00/);
await page.locator('#bw-reset').click();await page.locator('#bw-doctor').selectOption('unassigned');assert.equal(await page.locator('.bw-row:visible').count(),1);
await page.locator('#bw-reset').click();await page.locator('#bw-from').fill('2026-10-01');assert.equal(await page.locator('.bw-row:visible').count(),0);
await page.locator('#bw-reset').click();await page.locator('.bw-extra summary').click();await page.locator('#bw-recipient').selectOption('business');assert.equal(await page.locator('.bw-row:visible').count(),1);assert.match(await page.locator('#bw-detail a[aria-label="Apri documento completo"]').getAttribute('href'),/fatturazione-strutture/);
await page.locator('#bw-reset').click();await page.locator('#bw-status').selectOption('Parziale');assert.equal(await page.locator('.bw-row:visible').count(),1);
await page.locator('#bw-reset').click();await page.locator('#bw-method').selectOption('cash');assert.equal(await page.locator('.bw-row:visible').count(),0);
await page.locator('#bw-reset').click();await page.locator('#bw-ts').selectOption('selectable');assert.equal(await page.locator('.bw-row:visible').count(),1);
await page.evaluate(()=>{window.HTMLFormElement.prototype.submit=function(){window.sent=JSON.parse(new FormData(this).get('billing_document_ids_json'));};});
page.on('dialog',d=>d.accept());await page.locator('.bw-send-one:enabled').click();assert.deepEqual(await page.evaluate(()=>window.sent),['1']);
await page.goto(url+'/?mode=many');await page.locator('#bw-select-all').check();await page.locator('#bw-export-format').selectOption('pdf');
const batches=[];
await page.route('**/admin/fatturazione-documenti/export-selezionate',async route=>{
 const body=route.request().postData();batches.push(body);
 await route.fulfill({status:200,headers:{'Content-Type':'application/zip','X-CSRF-Name':'csrf_synthetic','X-CSRF-Hash':'renewed'},body:'synthetic zip bytes'});
});
await page.locator('#bw-export').click();await page.waitForFunction(()=>!document.getElementById('bw-export').disabled);
assert.deepEqual(batches.map(body=>(body.match(/name="billing_document_ids\[\]"/g)||[]).length),[50,6]);assert(batches[1].includes('renewed'));

await page.goto(url+'/?mode=cash');assert.match(await page.locator('#bw-kpi-cash').innerText(),/253,00/);assert.match(await page.locator('#bw-kpi-due').innerText(),/267,00/);assert.match(await page.locator('#bw-kpi-overdue').innerText(),/157,00/);assert.match(await page.locator('#bw-kpi-today').innerText(),/110,00/);assert.equal(await page.locator('.bw-row:visible').count(),3);
const kpis=await page.locator('.bw-kpis').innerText();await page.locator('[data-cash-tab=paid]').click();assert.equal(await page.locator('.bw-kpis').innerText(),kpis);assert.equal(await page.locator('.bw-row:visible').count(),4);
await page.screenshot({path:path.join(output,'incassi-desktop.png'),fullPage:true});
await page.goto(url+'/?mode=business');assert.equal(await page.locator('.bw-row:visible').count(),1);await page.locator('#bw-reset').click();assert.equal(await page.locator('.bw-row:visible').count(),1);
await page.setViewportSize({width:390,height:844});await page.goto(url);assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));await page.screenshot({path:path.join(output,'mobile.png'),fullPage:true});
await page.goto(url+'/?mode=empty');assert(await page.locator('#bw-empty').isVisible());assert(await page.locator('#bw-select-all').isDisabled());assert.deepEqual(errors,[]);
console.log('PASS: all-selection export, single TS, filters, business routing, cash amounts/counts, responsive layout, empty state.');
}finally{if(browser)await browser.close();server.closeAllConnections();server.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
