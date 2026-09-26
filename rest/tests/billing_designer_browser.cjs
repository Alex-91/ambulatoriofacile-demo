const {chromium}=require('playwright');
const fs=require('fs'),http=require('http'),path=require('path'),{spawn}=require('child_process'),assert=require('assert');
const root=path.resolve(__dirname,'../..');process.chdir(root);
fs.mkdirSync('rest/build/billing-port',{recursive:true});
fs.writeFileSync('rest/build/billing-port/editor.html',require('child_process').execFileSync(process.env.PHP_BIN||'php',['-d','xdebug.mode=off','rest/tests/_support/billing_port_fixture.php']));
const server=http.createServer(async(req,res)=>{
 if(req.method==='POST'){
  const chunks=[];for await(const chunk of req)chunks.push(chunk);
  const request=new Request('http://localhost'+req.url,{method:'POST',headers:req.headers,body:Buffer.concat(chunks)});
  const fields=Object.fromEntries(await request.formData());
  const proc=spawn((process.env.PHP_BIN||'php'),['-d','xdebug.mode=off','rest/tests/_support/billing_port_fixture.php','render']);let html='',error='';
  proc.stdout.on('data',d=>html+=d);proc.stderr.on('data',d=>error+=d);proc.stdin.end(JSON.stringify(fields));
  proc.on('exit',code=>{res.setHeader('Content-Type','application/json');res.end(JSON.stringify(code?{error}:{html,csrf:'synthetic'}));});return;
 }
 const file=req.url==='/'?path.join(root,'rest/build/billing-port/editor.html'):path.join(root,decodeURIComponent(req.url.split('?')[0]));
 if(!(file.startsWith(path.join(root,'public')+path.sep)||file===path.join(root,'rest/build/billing-port/editor.html'))||!fs.existsSync(file)){res.statusCode=404;res.end();return;}
 const ext=path.extname(file);res.setHeader('Content-Type',({'.js':'text/javascript','.css':'text/css','.html':'text/html'})[ext]||'application/octet-stream');res.end(fs.readFileSync(file));
});
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const browser=await chromium.launch({headless:true});
 const page=await browser.newPage({viewport:{width:1720,height:1080}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 try{
 await page.goto(`http://127.0.0.1:${server.address().port}/`);
 await page.locator('#bd-live-status').filter({hasText:'Aggiornata'}).waitFor();
 const read=()=>page.locator('#bd-json').inputValue().then(JSON.parse);
 assert((await read()).blocks.length>=6);
 // Real pointer drag on the production handlers.
 const first=page.locator('.bd-block').nth(0),second=page.locator('.bd-block').nth(1);
 const before=(await read()).blocks.map(b=>b.name);
 const handle=await first.locator('.bd-handle').first().boundingBox(),target=await second.boundingBox();
 await page.mouse.move(handle.x+10,handle.y+10);await page.mouse.down();await page.mouse.move(target.x+target.width/2,target.y+target.height/2,{steps:12});await page.mouse.up();
 assert.equal((await read()).blocks[1].name,before[0]);
 await page.locator('#bd-undo').click();assert.equal((await read()).blocks[0].name,before[0]);
 await page.locator('#bd-redo').click();assert.equal((await read()).blocks[1].name,before[0]);
 await page.locator('#bd-add').click();
 await page.locator('#bd-properties label').filter({hasText:'Larghezza'}).locator('select').selectOption('4');
 await page.locator('#bd-search').fill('Testo libero');await page.locator('#bd-catalog button').click();
 await page.locator('#bd-properties textarea').fill('TESTO-VISIBILE-ANTEPRIMA');
 await page.waitForFunction(()=>document.querySelector('#bd-live-frame').contentDocument?.body?.textContent.replace(/\u200b/g,'').includes('TESTO-VISIBILE-ANTEPRIMA'));
 assert.equal((await read()).blocks.at(-1).span,4);
 await page.locator('#bd-search').fill('Informativa');await page.locator('#bd-catalog button').click();
 await page.locator('#bd-properties textarea').fill('INFORMATIVA-UNICA-UI');
 await page.waitForFunction(()=>document.querySelector('#bd-live-frame').contentDocument?.body?.textContent.replace(/\u200b/g,'').includes('INFORMATIVA-UNICA-UI'));
 await page.locator('#bd-undo').click();assert.equal(await page.locator('[name=branding_terms_text]').inputValue(),'');
 await page.locator('#bd-redo').click();assert.equal(await page.locator('[name=branding_terms_text]').inputValue(),'INFORMATIVA-UNICA-UI');
 await page.locator('#bd-preview').click();await page.locator('#bd-preview-dialog').waitFor({state:'visible'});await page.locator('#bd-close-preview').click();
 await page.screenshot({path:'rest/build/billing-port/editor.png',fullPage:true});
 assert.deepEqual(errors,[]);console.log('PASS: pointer drag, undo/redo, thirds, live rendering, terms editing, shared text undo, enlarged preview; no JS errors');
 }finally{await browser.close();server.close();}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
