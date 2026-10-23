const {chromium}=require('playwright'),assert=require('assert'),fs=require('fs');
(async()=>{
 const origin=process.argv[2],browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXE});
 const page=await browser.newPage({viewport:{width:1280,height:1000}}),errors=[];
 page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
 fs.mkdirSync('output/patient-booking/catalog-dialog',{recursive:true});
 try{
  await page.goto(origin+'/admin/A01-regole');
  await page.getByRole('link',{name:'Prestazioni e professionisti',exact:true}).click();
  const dialog=page.locator('dialog[data-booking-catalog]');await dialog.waitFor();
  assert.equal(await dialog.locator('input[type=checkbox]').count(),10);
  for(const width of [1280,390]){
   await page.setViewportSize({width,height:width===390?844:1000});
   const layout=await dialog.evaluate(d=>{
    const r=d.getBoundingClientRect(),body=d.querySelector('.catalog-body'),footer=d.querySelector('footer').getBoundingClientRect();
    return {x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:innerWidth,height:innerHeight,bodyScroll:body.scrollWidth<=body.clientWidth,footerVisible:footer.bottom<=innerHeight,
     fonts:[...d.querySelectorAll('input,select,textarea,h2,h3,label,button')].map(e=>getComputedStyle(e).fontFamily),
     fields:[...d.querySelectorAll('input[type=text],textarea')].map(e=>getComputedStyle(e).textAlign),
     selects:[...d.querySelectorAll('select')].map(e=>({height:e.offsetHeight,radius:getComputedStyle(e).borderRadius,background:getComputedStyle(e).backgroundColor}))};
   });
   assert(layout.x>=15&&layout.y>=15&&layout.right<=layout.width-15&&layout.bottom<=layout.height-15,JSON.stringify(layout));
   assert(layout.bodyScroll&&layout.footerVisible);
   assert(layout.fonts.every(f=>f.includes('Inter')));
   assert(layout.fields.every(a=>a==='left'));
   assert(layout.selects.every(s=>s.height===44&&s.radius==='10px'&&s.background==='rgb(255, 255, 255)'));
   await dialog.screenshot({path:`output/patient-booking/catalog-dialog/${width}-prestazioni.png`});
   const doctor=dialog.locator('[data-catalog-item="doctors-10"]');await doctor.scrollIntoViewIfNeeded();
   const input=doctor.locator('input[name=doctors]');assert.equal(await input.isChecked(),true);
   await doctor.locator('label').first().click();assert.equal(await input.isChecked(),false);
   await doctor.locator('label').first().click();assert.equal(await input.isChecked(),true);
   await dialog.screenshot({path:`output/patient-booking/catalog-dialog/${width}-professionisti.png`});
   await dialog.locator('.catalog-body').evaluate(e=>e.scrollTop=0);
  }
  await page.setViewportSize({width:1280,height:1000});
  const visit=dialog.locator('[data-catalog-item="visits-1"]');
  await visit.getByLabel('Nome pubblico',{exact:true}).fill('Visita cardiologica');
  await visit.getByLabel('Descrizione',{exact:true}).fill('Descrizione della visita');
  await visit.getByLabel('Preparazione',{exact:true}).fill('Porta gli esami precedenti');
  await visit.getByLabel('Prezzo',{exact:true}).fill('120');
  await visit.locator('select').selectOption('approvazione');
  const revision=await page.evaluate(()=>window.bookingAdmin.state.etag);
  const [saved]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/editor/catalog')),dialog.getByRole('button',{name:'Salva le modifiche',exact:true}).click()]);
  assert.equal(saved.status(),200);await page.waitForFunction(r=>window.bookingAdmin?.state.etag>r,revision);
  await page.getByRole('link',{name:'Prestazioni e professionisti',exact:true}).click();await dialog.waitFor();
  const stored=dialog.locator('[data-catalog-item="visits-1"]');
  assert.equal(await stored.getByLabel('Nome pubblico',{exact:true}).inputValue(),'Visita cardiologica');
  assert.equal(await stored.getByLabel('Descrizione',{exact:true}).inputValue(),'Descrizione della visita');
  assert.equal(await stored.getByLabel('Preparazione',{exact:true}).inputValue(),'Porta gli esami precedenti');
  assert.equal(await stored.getByLabel('Prezzo',{exact:true}).inputValue(),'120');
  assert.equal(await stored.locator('select').inputValue(),'approvazione');
  await page.keyboard.press('Escape');await dialog.waitFor({state:'detached'});
  assert.deepEqual(errors,[]);
  console.log('PASS catalog dialog: original fonts and controls, desktop/mobile bounds, visible actions, checkbox interaction, edit/save/reopen, Escape.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
