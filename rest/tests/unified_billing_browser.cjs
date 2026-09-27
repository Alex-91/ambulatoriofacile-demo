// Synthetic read-only UI, no .env, session, database or external deliveries.
const {chromium}=require('playwright');
const fs=require('fs'),http=require('http'),path=require('path'),assert=require('assert');
const {execFileSync}=require('child_process');
const root=path.resolve(__dirname,'../..');
const output=path.join(root,'rest/build/unified-billing');fs.mkdirSync(output,{recursive:true});
const server=http.createServer((req,res)=>{
  if(req.method!=='GET'){res.writeHead(405);res.end();return;}
  const u=new URL(req.url,'http://localhost');
  if(u.pathname.startsWith('/public/')){
    const file=path.resolve(root,'.'+decodeURIComponent(u.pathname));
    if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.writeHead(404);res.end();return;}
    res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'application/octet-stream');res.end(fs.readFileSync(file));return;
  }
  const section=u.searchParams.get('tab')||'documenti';
  if(!['accettazione','catalogo','documenti','report','integrazioni'].includes(section)){res.writeHead(404);res.end();return;}
  try{const html=execFileSync(process.env.PHP_BIN||'php',['-d','xdebug.mode=off',path.join(root,'ops/polyclinic-ui-preview.php'),section,u.searchParams.get('kind')||'service',u.searchParams.get('mode')||'advanced']);res.setHeader('Content-Type','text/html; charset=utf-8');res.end(html);}
  catch(e){res.writeHead(500);res.end(String(e));}
});
(async()=>{
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  let browser;
  try{
    browser=await chromium.launch({headless:true});const page=await browser.newPage({viewport:{width:1440,height:1000}});
    await page.route('**/*',route=>new URL(route.request().url()).hostname==='127.0.0.1'?route.continue():route.abort());
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    const url=`http://127.0.0.1:${server.address().port}`;
    await page.goto(url+'/?tab=documenti');
    assert.equal(await page.locator('.admin-sidebar-menu').count(),1);
    const nav=page.getByRole('navigation',{name:'Sezioni fatturazione',exact:true});
    for(const label of ['Fatture','Incassi','Prestazioni e listini','Convenzioni','Compensi'])assert.strictEqual(await nav.getByRole('link',{name:label,exact:true}).count(),1,label);
    assert.strictEqual(await page.getByRole('heading',{name:'Fatturazione',exact:true}).count(),1);
    await page.getByText('Sistema TS · invio cumulativo',{exact:true}).click();
    assert.strictEqual(await page.locator('select[name=ts_sync_enabled]').count(),1);
    const before=await page.locator('.pc-rate').count();await page.getByText('Piano rate',{exact:true}).click();await page.locator('#pc-add-rate').click();assert.strictEqual(await page.locator('.pc-rate').count(),before+1);
    await page.screenshot({path:path.join(output,'advanced.png'),fullPage:true});
    await page.goto(url+'/?tab=documenti&mode=basic');
    for(const label of ['Prestazioni e listini','Convenzioni','Compensi'])assert.strictEqual(await nav.getByRole('link',{name:label,exact:true}).count(),0,label);
    assert.strictEqual(await nav.getByRole('link',{name:'Sistema TS',exact:true}).count(),0);assert.equal(await page.locator('.ts-menu-parent').count(),1);
    assert.strictEqual(await page.getByRole('heading',{name:'Compensi maturati e liquidazioni'}).count(),0);
    await page.screenshot({path:path.join(output,'basic.png'),fullPage:true});
    for(const section of ['catalogo','accettazione','report','integrazioni']){await page.goto(url+'/?tab='+section);assert.equal(await page.locator('.admin-sidebar-menu').count(),1);assert.strictEqual(await page.locator('form form').count(),0);assert.strictEqual(await page.locator('form[method=post]').count(),await page.locator('form[method=post] input[name=csrf_synthetic]').count());}
    for(const [kind,label] of [['service','Prestazioni e listini'],['agreement','Convenzioni'],['rule','Compensi']]){
      await page.goto(url+'/?tab=catalogo&kind='+kind);
      const menu=page.getByRole('navigation',{name:'Sezioni fatturazione',exact:true});
      assert.equal(await menu.locator('[aria-current=page]').innerText(),label);
      const sidebar=await page.locator('.admin-sidebar-menu').boundingBox(),content=await page.locator('main.pc').boundingBox();
      assert(sidebar.x+sidebar.width<=content.x+1,'Menu must stay to the left of the content');
    }
    await page.screenshot({path:path.join(output,'compensi-menu.png'),fullPage:true});
    await page.setViewportSize({width:390,height:844});await page.goto(url+'/?tab=catalogo&kind=rule');
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    assert.deepStrictEqual(errors,[]);console.log('Unified billing browser: advanced/basic navigation, TS queue link, installment interaction, 6 sections and CSRF passed.');
  }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(e=>{console.error(e);process.exitCode=1;});
