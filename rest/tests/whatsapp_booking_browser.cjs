const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),http=require('http'),assert=require('assert'),{execFileSync}=require('child_process');
const root=path.resolve(__dirname,'../..'),fixture=path.join(__dirname,'_support/whatsapp_booking_fixture.php');
const php=(args,input)=>execFileSync('php',['-d','xdebug.mode=off',fixture,...args],{input,encoding:'utf8'});
const html=php([]);let state={};
const server=http.createServer(async(req,res)=>{
 try{
  if(req.method==='POST'){
   let chunks=[];for await(const chunk of req)chunks.push(chunk);
   const fields=await new Request('http://localhost'+req.url,{method:'POST',headers:req.headers,body:Buffer.concat(chunks)}).formData();
   const config={};for(const [k,v] of fields){const match=k.match(/^booking\[(\w+)\](\[\])?$/);if(match){if(match[2])(config[match[1]]??=[]).push(v);else config[match[1]]=v;}}
   if(fields.get('reset')==='1')state={};
   const result=JSON.parse(php(['preview'],JSON.stringify({config,state,message:fields.get('message'),found:fields.get('patient_found')!=='0'})));state=result.state;
   res.setHeader('Content-Type','application/json');res.end(JSON.stringify(result));return;
  }
  if(req.url==='/'){res.setHeader('Content-Type','text/html');res.end(html);return;}
  const file=path.resolve(root,'.'+decodeURIComponent(req.url.split('?')[0]));
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;res.end();return;}
  res.setHeader('Content-Type',path.extname(file)==='.css'?'text/css':path.extname(file)==='.js'?'application/javascript':'application/octet-stream');res.end(fs.readFileSync(file));
 }catch(e){res.statusCode=422;res.setHeader('Content-Type','application/json');res.end(JSON.stringify({error:e.message,csrf:'synthetic'}));}
});
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXE?{executablePath:process.env.BROWSER_EXE}:{})});
 const page=await browser.newPage({viewport:{width:1440,height:1080}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 try{
  // Simulate the old, cached right-sidebar CSS. A corrected page must request a new URL.
  let staleCssRequests=0;
  await page.route('**/public/assets/css/whatsapp-booking.css',async route=>{
   if(!new URL(route.request().url()).search){staleCssRequests++;await route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(root,'public/assets/css/whatsapp-booking.css'),'utf8').replace('grid-template-columns:260px minmax(0,1fr)','grid-template-columns:minmax(0,1fr) 260px')});}else await route.continue();
  });
  await page.goto('http://127.0.0.1:'+server.address().port);
  const cssUrl=await page.locator('link[href*="whatsapp-booking.css"]').getAttribute('href');
  assert.match(cssUrl,/\?v=[a-f0-9]{16}$/,'Stylesheet must be content-versioned');assert.equal(staleCssRequests,0,'Old stylesheet must not be reused');

  const mainBox=await page.locator('#wb-app').boundingBox(),menuBox=await page.locator('.wb-navigation').boundingBox();assert(menuBox.x+menuBox.width<=mainBox.x,'Shared menu must be on the left');assert(await page.locator('.wb-navigation').isVisible());assert(menuBox.width<=280 && mainBox.width>700,'Sidebar must remain narrow and content usable');
  for(const key of ['location','location_id','lead_hours','window_days'])assert.equal(await page.locator('[name="booking['+key+']"]').count(),0,'Agenda field must not be configurable: '+key);
  await page.locator('#wb-single').check();assert(await page.locator('#wb-order').isHidden());assert.equal(await page.locator('[name="booking[default_visit_id]"]').count(),0);
  await page.locator('#wb-patient').selectOption('name');assert(await page.locator('#wb-unknown').isHidden());
  const send=async(text,expected)=>{await page.locator('#wb-message').fill(text);await page.locator('#wb-chat-form button').click();await page.waitForFunction(s=>document.querySelector('#wb-chat').lastElementChild.textContent.includes(s),expected);};
  await send('Buongiorno','Prenota un appuntamento');await page.locator('#wb-chat button:enabled').filter({hasText:'Prenota'}).click();await page.waitForFunction(()=>document.querySelector('#wb-chat').lastElementChild.textContent.includes('Quale giorno'));const firstDates=await page.locator('#wb-chat button:enabled').allTextContents();assert.equal(firstDates.length,6);assert.equal(firstDates[5],'Mostra altri giorni');
  await page.locator('#wb-chat button:enabled').filter({hasText:'Mostra altri giorni'}).click();await page.waitForFunction(first=>document.querySelector('#wb-chat').lastElementChild.textContent.includes('Quale giorno')&&!document.querySelector('#wb-chat').lastElementChild.textContent.includes(first),firstDates[0]);
  const nextDates=await page.locator('#wb-chat button:enabled').allTextContents();assert.equal(nextDates.length,7);assert(nextDates.slice(0,5).every(d=>!firstDates.includes(d)));
  await page.locator('#wb-chat button:enabled').filter({hasText:'Mostra altri giorni'}).click();await page.waitForFunction(()=>document.querySelector('#wb-chat').lastElementChild.textContent.includes('Non ci sono altri giorni'));
  assert.equal(await page.locator('#wb-chat button:enabled').count(),3);
  await page.locator('#wb-chat button:enabled').filter({hasText:'Giorni precedenti'}).click();await page.waitForFunction(day=>document.querySelector('#wb-chat').lastElementChild.textContent.includes(day),nextDates[0]);
  assert.deepEqual(await page.locator('#wb-chat button:enabled').allTextContents(),nextDates);
  await page.locator('#wb-chat button:enabled').filter({hasText:'Giorni precedenti'}).click();await page.waitForFunction(day=>document.querySelector('#wb-chat').lastElementChild.textContent.includes(day),firstDates[0]);
  assert.deepEqual(await page.locator('#wb-chat button:enabled').allTextContents(),firstDates);
  await send('1','orario');await send('1','nome');await send('Andrea','cognome');await send('Esempio','Riepilogo');await send('1','confermato');
  await page.locator('[data-panel="availability"]').click();assert(await page.locator('#wb-visits').isHidden());await page.locator('select[name="booking[confirmation]"]').selectOption('manual');
  await page.locator('[data-panel="flow"]').click();await page.locator('#wb-patient').selectOption('cf');assert(await page.locator('#wb-unknown').isVisible());
  await send('Buongiorno','Prenota un appuntamento');await page.locator('#wb-chat button:enabled').filter({hasText:'Prenota'}).click();await page.waitForFunction(()=>document.querySelector('#wb-chat').lastElementChild.textContent.includes('Quale giorno'));await send('1','orario');await send('1','codice fiscale');await send('RSSMRA85T10A562S','Riepilogo');await send('1','non è ancora confermato');
  await send('Ciao','Prenota un appuntamento');
  await page.locator('#wb-chat button:enabled').filter({hasText:'Annulla appuntamento'}).click();await page.waitForFunction(()=>document.querySelector('#wb-chat').lastElementChild.textContent.includes('codice fiscale'));
  await send('RSSMRA85T10A562S','Quale appuntamento');
  await page.locator('#wb-chat button:enabled').first().click();await page.waitForFunction(()=>document.querySelector('#wb-chat').lastElementChild.textContent.includes('Mantieni'));
  await page.locator('#wb-chat button:enabled').filter({hasText:'Annulla appuntamento'}).click();await page.waitForFunction(()=>document.querySelector('#wb-chat').lastElementChild.textContent.includes('Appuntamento annullato'));
  fs.mkdirSync(path.join(root,'output/whatsapp-booking'),{recursive:true});await page.screenshot({path:path.join(root,'output/whatsapp-booking/desktop.png'),fullPage:true});
  for(const width of [1920,1440,1024,768,390,320]){await page.setViewportSize({width,height:900});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'Horizontal overflow at '+width);}
  await page.screenshot({path:path.join(root,'output/whatsapp-booking/mobile.png'),fullPage:true});assert.deepEqual(errors,[]);
  console.log('PASS browser: real view and JS; single visit; name and fiscal-code paths; automatic/manual confirmation; responsive 1440/768/390/320px; no JS errors.');
 }finally{await browser.close();server.close();}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
