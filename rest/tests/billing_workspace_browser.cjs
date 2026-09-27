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
await page.goto(url);
await page.evaluate(()=>document.fonts.ready);
const menuFonts=await page.locator('.admin-sidebar-menu .fa').evaluateAll(icons=>icons.map(icon=>{const style=getComputedStyle(icon,'::before');return {family:style.fontFamily,content:style.content,loaded:document.fonts.check(style.fontWeight+' 16px '+style.fontFamily)};}));
assert(menuFonts.length>=4);for(const font of menuFonts){assert.match(font.family,/Font Awesome 6/);assert(font.loaded);assert.notEqual(font.content,'none');}
assert(await page.evaluate(()=>performance.getEntriesByType('resource').some(r=>r.name.includes('/webfonts/fa-solid-900.woff2'))));
if(errors.length) throw new Error(errors.join('; '));await page.getByRole('button',{name:'FT-003',exact:true}).click();
assert.match(await page.locator('#bw-detail').innerText(),/Paolo DEMO Blu/);assert.match(await page.locator('#bw-detail').innerText(),/77,00/);
assert.match(await page.locator('#bw-kpi-net').innerText(),/520,00/);assert.match(await page.locator('#bw-kpi-cash').innerText(),/253,00/);assert.match(await page.locator('#bw-kpi-due').innerText(),/267,00/);
assert.match(await page.getByRole('link',{name:'Nota di credito',exact:true}).getAttribute('href'),/#pc-credit$/);
await page.screenshot({path:path.join(output,'desktop.png'),fullPage:true});
await page.locator('#bw-select-all').check();assert.equal(await page.locator('#bw-bulk-inputs input').count(),1);assert.equal(await page.locator('#bw-bulk-inputs input').getAttribute('name'),'billing_document_ids[]');assert.equal(await page.locator('#bw-bulk-form input[name="csrf_synthetic"]').count(),1);
await page.locator('#bw-search').fill('Elena');assert.equal(await page.locator('.bw-row:visible').count(),1);assert.match(await page.locator('#bw-detail').innerText(),/Elena/);assert.equal(await page.locator('#bw-bulk-inputs input').count(),0);assert.match(await page.locator('#bw-kpi-due').innerText(),/80,00/);
await page.locator('#bw-search').fill('');await page.locator('#bw-doctor').selectOption('1');assert.equal(await page.locator('.bw-row:visible').count(),5);
await page.locator('#bw-doctor').selectOption('');await page.locator('#bw-status').selectOption('Nota di credito');assert.equal(await page.locator('.bw-row:visible').count(),1);assert.match(await page.locator('#bw-kpi-cash').innerText(),/0,00/);
assert.equal(await page.locator('.ts-menu-parent').count(),1);
assert.equal(await page.getByRole('navigation',{name:'Sezioni fatturazione',includeHidden:true}).locator('a[href*="sistema-ts"]').count(),0);
const tsLinks=await page.getByRole('navigation',{name:'Sezioni Sistema TS',includeHidden:true}).locator('a').allTextContents();
assert.deepEqual(tsLinks,['Riepilogo','Documenti e invii','Nuovo documento','Diagnostica','Configurazione']);
const billingLinks=await page.getByRole('navigation',{name:'Sezioni fatturazione',includeHidden:true}).locator('a').evaluateAll(links=>links.map(a=>[a.textContent,a.getAttribute('href')]));
await page.goto(url+'/?mode=outside');
const group=page.locator('.billing-menu-parent .billing-menu-group');assert.equal(await group.getAttribute('open'),null);
await group.locator('summary').click();assert.notEqual(await group.getAttribute('open'),null);
assert.deepEqual(await group.locator('nav a').evaluateAll(links=>links.map(a=>[a.textContent,a.getAttribute('href')])),billingLinks);
await group.locator('summary').focus();await page.keyboard.press('Enter');assert.equal(await group.getAttribute('open'),null);
assert.equal(await page.locator('.billing-menu-parent').count(),1);
for(const [mode,label] of [['schedule','Incassi'],['reports','Report'],['ts','']]){
 await page.goto(url+'/?mode='+mode);
 const menu=page.getByRole('navigation',{name:'Sezioni fatturazione',includeHidden:true});
 assert.deepEqual(await menu.locator('a').evaluateAll(links=>links.map(a=>[a.textContent,a.getAttribute('href')])),billingLinks);
 if(label) assert.equal(await menu.locator('[aria-current=page]').innerText(),label);
 else {assert.equal(await menu.locator('[aria-current=page]').count(),0);assert.equal(await page.getByRole('navigation',{name:'Sezioni Sistema TS',includeHidden:true}).locator('[aria-current=page]').innerText(),'Documenti e invii');}
 assert.equal(await page.locator('.billing-menu-parent').count(),1);
 assert.equal(await page.locator('.bw-tabs').count(),0);
 const menuColor=await page.locator((mode==='ts'?'.ts-menu-parent':'.billing-menu-parent')+' summary').evaluate(e=>getComputedStyle(e).backgroundColor);
 assert.equal(menuColor,'rgb(232, 246, 248)');
 await page.screenshot({path:path.join(output,mode+'-menu.png'),fullPage:true});
}
await page.goto(url+'/?mode=basic');assert.equal(await page.getByRole('link',{name:'Compensi',exact:true}).count(),0);assert.equal(await page.locator('#bw-kpi-drafts').count(),1);await page.screenshot({path:path.join(output,'basic.png'),fullPage:true});
await page.setViewportSize({width:390,height:844});await page.goto(url);assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));assert.equal(await page.locator('.bw-sidebar').count(),0);assert.equal(await page.locator('.main-header').count(),1);await page.screenshot({path:path.join(output,'mobile.png'),fullPage:true});
await page.goto(url+'/?mode=empty');assert(await page.locator('#bw-empty').isVisible());assert.equal(await page.locator('#bw-bulk-inputs input').count(),0);assert.deepEqual(errors,[]);
console.log('PASS: real workspace rendering, detail selection, invoice/credit KPIs, filters, capability visibility, cumulative TS form, mobile layout and empty state.');
}finally{if(browser)await browser.close();server.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
