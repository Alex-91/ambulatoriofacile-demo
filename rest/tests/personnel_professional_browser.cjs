const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),http=require('http'),assert=require('assert'),{execFileSync}=require('child_process');
const root=path.resolve(__dirname,'../..');
const server=http.createServer((req,res)=>{
 const u=new URL(req.url,'http://localhost');
 if(req.method!=='GET'){res.writeHead(405);res.end();return;}
 if(u.pathname.startsWith('/public/')){const p=path.resolve(root,'.'+u.pathname);if(!p.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(p)){res.writeHead(404);res.end();return;}res.setHeader('Content-Type',p.endsWith('.js')?'text/javascript':p.endsWith('.css')?'text/css':'application/octet-stream');res.end(fs.readFileSync(p));return;}
 if(u.pathname==='/admin/personale/search'){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,results:[{id_personale:1,label:'Anna Test'},{id_personale:2,label:'Altro personale'}]}));return;}
 if(u.pathname.startsWith('/admin/personale/get/')){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,personale:{id_personale:1,id_user:10,nome:'Anna',cognome:'Test',tipo:1},user:{id_user:10,username:'synthetic'},tipi:[{id:1,label:'Dottore'}],gruppi:[{id:1,label:'Sede test'}],selected_luoghi:[1],professional:u.pathname.endsWith('/2')?{available:false}:{available:true,enabled:true,specialties:'Cardiologia',version:3,catalog_id:0,legacy_options:[{id:5,name:'Professionista precedente'}]}}));return;}
 res.setHeader('Content-Type','text/html;charset=utf-8');res.end(execFileSync('php',['-d','xdebug.mode=off',path.join(root,'ops/personnel-professional-preview.php'),u.searchParams.get('mode')||'']));
});
(async()=>{await new Promise(r=>server.listen(0,'127.0.0.1',r));const browser=await chromium.launch({headless:true});try{
 const page=await browser.newPage({viewport:{width:1440,height:1100}}),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.route('**/*',r=>new URL(r.request().url()).hostname==='127.0.0.1'?r.continue():r.abort());const url='http://127.0.0.1:'+server.address().port;
 await page.goto(url);await page.locator('#s_nome').fill('Anna');await page.locator('#searchForm').evaluate(f=>f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));await page.locator('.res-item').first().click();
 const fields=page.locator('#professional-profile');await fields.waitFor({state:'visible'});assert.equal(await fields.isEnabled(),true);assert.equal(await page.locator('#professional_specialties').inputValue(),'Cardiologia');assert.equal(await page.locator('#professional_version').inputValue(),'3');await page.locator('#professional_legacy_id').selectOption('5');
 const payload=await page.locator('#professional_specialties').evaluate(e=>Object.fromEntries(new FormData(e.form)));assert.equal(payload.professional_legacy_id,'5');assert.equal(payload.professional_enabled,'1');assert.equal(payload.professional_profile_present,'1');
 await page.locator('.res-item').nth(1).click();await fields.waitFor({state:'hidden'});await page.waitForFunction(()=>document.getElementById('professional-profile').disabled===true);
 await page.goto(url+'/?mode=create');assert.equal(await page.locator('[name=professional_specialties]').getAttribute('required'),null);assert.equal(await page.locator('[name=professional_enabled]').inputValue(),'');
 assert.deepEqual(errors,[]);console.log('PASS: personnel profile load, optional specialties, explicit legacy link, form payload and feature-disabled fields.');
}finally{await browser.close();server.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
