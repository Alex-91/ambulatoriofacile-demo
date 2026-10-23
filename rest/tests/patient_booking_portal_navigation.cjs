const {chromium}=require('playwright'),assert=require('assert'),fs=require('fs');
(async()=>{
 const origin=process.argv[2],browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXE});
 const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];
 page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
 fs.mkdirSync('output/patient-booking/portal',{recursive:true});
 try{
  await page.goto(origin+'/admin');
  const navigation=page.getByRole('complementary',{name:'Menu dello spazio',exact:true});
  const links=await navigation.locator('a').evaluateAll(nodes=>nodes.map(n=>({text:n.textContent.trim(),href:n.href})));
  assert(links.some(l=>l.text==='Menu personalizzato dello spazio'));
  await navigation.locator('a').filter({hasText:'Prenotazione online'}).click();
  for(const board of ['A01-regole','A02-richieste','A03-link-aspetto','A04-configurazione-flusso','A05-configurazione-verifica']){
   await page.goto(origin+'/admin/'+board);
   const module=page.locator('#patient-booking-admin');
   await module.locator('h1').first().waitFor();
   assert.deepEqual(await navigation.locator('a').evaluateAll(nodes=>nodes.map(n=>({text:n.textContent.trim(),href:n.href}))),links);
   assert.equal(await page.locator('.main-header').count(),1);
   assert.equal(await module.locator('aside[aria-label="Menu del gestionale"]').count(),0);
   assert.equal(await module.locator('#cerca-globale').count(),0);
   assert.equal(await page.locator('.content-wrapper #patient-booking-admin').count(),1);
   assert(await module.evaluate(host=>!!host.shadowRoot));
   await page.screenshot({path:'output/patient-booking/portal/'+board+'.png'});
  }
  await page.goto(origin+'/admin/A01-regole');
  const module=page.locator('#patient-booking-admin');
  await module.getByRole('link',{name:'Configura il percorso',exact:true}).click();
  await page.waitForURL('**/admin/A04-configurazione-flusso');
  assert(await navigation.isVisible());
  await page.goto(origin+'/admin/A03-link-aspetto');
  await module.getByRole('link',{name:'Aspetto e comunicazioni',exact:true}).click();
  await module.locator('#url-privacy').waitFor({state:'visible'});
  assert(await navigation.isVisible());
  await module.getByRole('link',{name:'Link e QR',exact:true}).click();
  await module.locator('#link-studio').waitFor({state:'visible'});
  assert.equal(await module.locator('#url-privacy').isVisible(),false);
  const patient=module.getByRole('link',{name:'Apri la pagina pubblica',exact:true});
  assert.equal(await patient.getAttribute('target'),'_blank');
  const [patientTab]=await Promise.all([page.waitForEvent('popup'),patient.click()]);
  await patientTab.waitForLoadState();
  assert(new URL(patientTab.url()).pathname==='/paziente');
  assert(await navigation.isVisible());await patientTab.close();
  for(const [width,board] of [[1280,'A01-regole'],[390,'A01-regole'],[390,'A02-richieste'],[390,'A03-link-aspetto#aspetto'],[390,'A04-configurazione-flusso'],[390,'A05-configurazione-verifica']]){
   await page.goto(origin+'/admin/'+board);
   await page.setViewportSize({width,height:width===390?844:1000});
   await page.evaluate(()=>document.fonts.ready);
   assert(await navigation.isVisible());
   const layout=await module.evaluate(el=>({width:el.clientWidth,scroll:el.scrollWidth,pageWidth:document.documentElement.clientWidth,pageScroll:document.documentElement.scrollWidth}));
   assert(layout.pageScroll<=layout.pageWidth+1,JSON.stringify(layout));
   assert(layout.scroll<=layout.width+1,board+' '+JSON.stringify(layout));
   await page.screenshot({path:`output/patient-booking/portal/menu-${width}-${board.slice(0,3)}.png`});
  }
  assert.deepEqual(errors,[]);
  console.log('PASS portal navigation: shared header/menu on five boards, custom entries preserved, workflow link, appearance/link tabs, patient opens separately, desktop/mobile bounds, no browser errors.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
