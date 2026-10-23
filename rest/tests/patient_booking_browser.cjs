const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),http=require('http'),assert=require('assert'),vm=require('vm'),{execFileSync}=require('child_process');
const root=path.resolve(__dirname,'../..'),source=fs.readFileSync(path.join(root,'rest/app/Resources/patient-booking/source/prototipo.html'),'utf8');
const script=source.match(/<script>([\s\S]*?)<\/script>/)[1];
const fixtures=vm.runInNewContext(script.split('const FakeServer =')[0]+';({STUDIO,SEDI,PROFESSIONISTI,PRESTAZIONI,AGENDE})');
const config=JSON.parse(fs.readFileSync(path.join(root,'rest/app/Resources/patient-booking/defaults.json')));
config.verifica={richiesta:false,metodi:[]};config.passi.find(p=>p.id==='verifica').attivo=false;config.accesso.modalita='aperta';config.dati.cellulare='facoltativo';
const catalog={config,prenotazioni_attive:true,modalita:'aperta',invito:null,prestazioni:fixtures.PRESTAZIONI,professionisti:Object.values(fixtures.PROFESSIONISTI).map(p=>({...p,sedi:Object.keys(fixtures.AGENDE[p.id].sedi)})),sedi:Object.values(fixtures.SEDI),regole:{max_attive:2,finestra_giorni:90,preavviso_sposta_ore:24,riserva_minuti:0,scadenza_richiesta_ore:24}};
const boot={studio:fixtures.STUDIO,catalog,api:'/api',csrf:'synthetic',csrfHeader:'X-CSRF-TOKEN',today:'2026-10-21T20:40:00',privacy:'https://studio.example/privacy',back:'/'};
const html=execFileSync('php',['-d','xdebug.mode=off',path.join(__dirname,'_support/patient_booking_view.php')],{input:JSON.stringify(boot),encoding:'utf8'});
const bookings=[];let posts=0;
const server=http.createServer(async(req,res)=>{
 try{
  const u=new URL(req.url,'http://localhost');
  if(u.pathname.startsWith('/api')){
   res.setHeader('Content-Type','application/json');let body={};
   if(req.method==='POST'){let raw='';for await(const b of req)raw+=b;body=JSON.parse(raw);assert.equal(req.headers['x-csrf-token'],'synthetic');}
   let data;
   if(u.pathname==='/api/catalogo')data=catalog;
   else if(u.pathname==='/api/disponibilita'){
    const day=u.searchParams.get('dal')||'2026-11-12';
    const slot={data:day,ora:'9:00',professionista:'conti',sede:'empoli'};
    if(u.searchParams.get('prima_disponibilita'))data={primo:slot};
    else {const days={};for(let d=new Date(day+'T12:00:00');d.toISOString().slice(0,10)<=u.searchParams.get('al');d.setDate(d.getDate()+1)){const iso=d.toISOString().slice(0,10);days[iso]={stato:'posti',orari:[{...slot,data:iso},{...slot,data:iso,ora:'10:00'}]};}data={giorni:days};}
   } else if(req.method==='POST' && u.pathname==='/api/prenotazioni'){
    posts++;assert.equal(body.privacy_letta,true);assert(req.headers['idempotency-key']);assert.equal(bookings.length,0);
    const p={...body.slot,paziente:body.paziente,ref:'ABC234',stato:'confermata',cellulare:body.contatto.cellulare,email:body.contatto.email,canale:null,verificata:false,ics_sequence:0};bookings.push(p);data={prenotazione:p};
   }else if(u.pathname==='/api/prenotazioni/mie')data={prenotazioni:bookings};
   else if(u.pathname.endsWith('/annulla')){bookings[0].stato='annullata';data={prenotazione:bookings[0]};}
   else{res.statusCode=404;data={errore:'non_trovata'};}
   res.end(JSON.stringify({...data,csrf:'synthetic'}));return;
  }
  if(u.pathname==='/'){res.setHeader('Content-Type','text/html');res.end(html.replaceAll('http://booking-test.invalid/','/'));return;}
  if(u.pathname==='/reference'){res.setHeader('Content-Type','text/html');res.end(source.replace(/<link[^>]+fonts\.(?:googleapis|gstatic)\.com[^>]*>\s*/g,'').replace('</head>','<link rel="stylesheet" href="/public/assets/patient-booking/fonts.css"></head>'));return;}
  const file=path.resolve(root,'.'+u.pathname);
  if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)){res.statusCode=404;res.end();return;}
  res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':'application/octet-stream');res.end(fs.readFileSync(file));
 }catch(e){console.error(e);res.statusCode=500;res.end(JSON.stringify({errore:e.message}));}
});
if(require.main===module)(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const browser=await chromium.launch({headless:true,...(process.env.BROWSER_EXE?{executablePath:process.env.BROWSER_EXE}:{})});
 const page=await browser.newPage({viewport:{width:1280,height:960}}),errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 const origin='http://127.0.0.1:'+server.address().port,out=path.join(root,'output/patient-booking');fs.mkdirSync(out,{recursive:true});
 try {
  await page.goto(origin);await page.waitForSelector('[data-action="prestazione"]');await page.evaluate(()=>document.fonts.ready);
  assert.equal(await page.locator('.demo').count(),0);
  for(const width of [390,768,1280]){
   await page.setViewportSize({width,height:960});await page.screenshot({path:path.join(out,'welcome-'+width+'.png'),fullPage:true});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Overflow '+width);
  }
  await page.locator('[data-action="prestazione"][data-id="derm"]').first().click();
  if(await page.locator('[data-action="sede"][data-id="empoli"]').count())await page.locator('[data-action="sede"][data-id="empoli"]').click();
  await page.locator('[data-action="ora"]').first().click();
  await page.locator('[data-action="cta"]').filter({visible:true}).first().click();
  if(await page.locator('[data-action="cta"]').count())await page.locator('[data-action="cta"]').filter({visible:true}).first().click();
  await page.locator('#dati-nome').fill('Rosa');await page.locator('#dati-cognome').fill('Bianchi');
  const phone=page.locator('#dati-cell');if(await phone.count())await phone.fill('3478126650');
  await page.locator('#dati-email').fill('rosa@example.test');await page.locator('#dati-privacy').check();
  await page.locator('[data-action="cta"]').filter({visible:true}).first().click();
  await page.screenshot({path:path.join(out,'summary.png'),fullPage:true});
  await page.locator('[data-action="cta"]').filter({visible:true}).first().click();
  await page.waitForFunction(()=>document.body.textContent.includes('ABC234'));
  assert.equal(await page.evaluate(()=>{const p=PRESTAZIONI[0],price=p.prezzo;p.prezzo=null;const html=SCHERMATE.prestazione.render().html;p.prezzo=price;return html.includes('null €');}),false,'Absent price must not render null');
  assert.equal(posts,1);assert(!await page.getByText('Ti abbiamo mandato una conferma',{exact:false}).count());
  await page.screenshot({path:path.join(out,'confirmation.png'),fullPage:true});
  assert(!(await page.locator('#screen').innerText()).includes('mandato'), 'Unsent notification must not be claimed');
  await page.goto(origin+'/?ref=ABC234');await page.waitForSelector('[data-action="annulla"]');
  assert.equal(await page.evaluate(()=>{state.sessione.cellulare=null;return typeof SCHERMATE.mie.render().html;}),'string','Internal bookings without verified number render');
  await page.locator('[data-action="annulla"]').first().click();
  await page.locator('[data-action="conferma-annulla"]').click();
  await page.waitForFunction(()=>document.body.textContent.includes('Prenotazione annullata'));
  assert.equal(bookings[0].stato,'annullata');
  assert.deepEqual(errors,[]);
  console.log('PASS browser: original layout 390/768/1280, dynamic catalogue, booking, CSRF/idempotency, real confirmation, management, no JS errors.');
 }catch(e){console.error('Browser errors',errors);console.error((await page.locator('body').innerText()).slice(0,2500));await page.screenshot({path:path.join(out,'failure.png'),fullPage:true});throw e;}finally {await browser.close();server.close();}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});

module.exports={server,fixtures,catalog,source,root};
