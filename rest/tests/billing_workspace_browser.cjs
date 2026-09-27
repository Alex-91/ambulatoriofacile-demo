const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),http=require('http'),assert=require('assert');
const {execFileSync}=require('child_process');
const root=path.resolve(__dirname,'../..'),output=path.join(root,'rest/build/billing-workspace');fs.mkdirSync(output,{recursive:true});
const server=http.createServer((req,res)=>{
 if(req.method!=='GET'){res.writeHead(405);res.end();return;}
 const url=new URL(req.url,'http://localhost');
 if(url.pathname.startsWith('/public/')){
  const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.writeHead(404);res.end();return;}
  res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':'application/octet-stream');res.end(fs.readFileSync(file));return;
 }
 try{res.setHeader('Content-Type','text/html; charset=utf-8');res.end(execFileSync(process.env.PHP_BIN||'php',['-d','xdebug.mode=off',path.join(root,'ops/billing-workspace-preview.php'),url.searchParams.get('mode')||'advanced']));}catch(e){res.writeHead(500);res.end(String(e));}
});
(async()=>{await new Promise(r=>server.listen(0,'127.0.0.1',r));let browser;
try{browser=await chromium.launch({headless:true});const page=await browser.newPage({viewport:{width:1586,height:980}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.route('**/*',r=>new URL(r.request().url()).hostname==='127.0.0.1'?r.continue():r.abort());const url='http://127.0.0.1:'+server.address().port;
await page.goto(url);if(errors.length) throw new Error(errors.join('; '));await page.getByRole('button',{name:'FT-003',exact:true}).click();
assert.match(await page.locator('#bw-detail').innerText(),/Paolo DEMO Blu/);assert.match(await page.locator('#bw-detail').innerText(),/77,00/);
assert.match(await page.locator('#bw-kpi-net').innerText(),/520,00/);assert.match(await page.locator('#bw-kpi-cash').innerText(),/253,00/);assert.match(await page.locator('#bw-kpi-due').innerText(),/267,00/);
assert.match(await page.getByRole('link',{name:'Nota di credito',exact:true}).getAttribute('href'),/#pc-credit$/);
await page.screenshot({path:path.join(output,'desktop.png'),fullPage:true});
await page.locator('#bw-select-all').check();assert.equal(await page.locator('#bw-bulk-inputs input').count(),1);assert.equal(await page.locator('#bw-bulk-inputs input').getAttribute('name'),'billing_document_ids[]');assert.equal(await page.locator('#bw-bulk-form input[name="csrf_synthetic"]').count(),1);
await page.locator('#bw-search').fill('Elena');assert.equal(await page.locator('.bw-row:visible').count(),1);assert.match(await page.locator('#bw-detail').innerText(),/Elena/);assert.equal(await page.locator('#bw-bulk-inputs input').count(),0);assert.match(await page.locator('#bw-kpi-due').innerText(),/80,00/);
await page.locator('#bw-search').fill('');await page.locator('#bw-doctor').selectOption('1');assert.equal(await page.locator('.bw-row:visible').count(),5);
await page.locator('#bw-doctor').selectOption('');await page.locator('#bw-status').selectOption('Nota di credito');assert.equal(await page.locator('.bw-row:visible').count(),1);assert.match(await page.locator('#bw-kpi-cash').innerText(),/0,00/);
await page.goto(url+'/?mode=basic');assert.equal(await page.getByRole('link',{name:'Compensi',exact:true}).count(),0);assert.equal(await page.locator('#bw-kpi-drafts').count(),1);await page.screenshot({path:path.join(output,'basic.png'),fullPage:true});
await page.setViewportSize({width:390,height:844});await page.goto(url);assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));await page.locator('.bw-mobile-menu').click();assert.equal(await page.locator('.bw-mobile-menu').getAttribute('aria-expanded'),'true');await page.locator('.bw-mobile-menu').click();await page.screenshot({path:path.join(output,'mobile.png'),fullPage:true});
await page.goto(url+'/?mode=empty');assert(await page.locator('#bw-empty').isVisible());assert.equal(await page.locator('#bw-bulk-inputs input').count(),0);assert.deepEqual(errors,[]);
console.log('PASS: real workspace rendering, detail selection, invoice/credit KPIs, filters, capability visibility, cumulative TS form, mobile layout and empty state.');
}finally{if(browser)await browser.close();server.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
