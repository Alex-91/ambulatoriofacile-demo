const {chromium}=require('playwright'),assert=require('assert'),path=require('path'),fs=require('fs');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXE});
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const url=process.argv[2];assert(url&&/^http:\/\/127\.0\.0\.1:\d+\/admin$/.test(url),'Explicit loopback preview required');
 const out=path.resolve(__dirname,'../../output/patient-booking');fs.mkdirSync(out,{recursive:true});
 try{
  await page.goto(url);await page.locator('#workflow-form').waitFor();
  for(const width of [390,768,1280]){await page.setViewportSize({width,height:960});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Responsive overflow');await page.screenshot({path:path.join(out,'admin-'+width+'.png'),fullPage:true});}
  await page.locator('[name="workflow[testi][benvenuto_titolo]"]').fill('Prenota la tua visita di prova');
  await page.locator('[name="workflow[senza_verifica][recapito]"]').selectOption('email');
  await page.locator('[data-step="perchi"] [type=checkbox]').uncheck();
  await page.locator('[data-step="professionista"] [data-move="up"]').click();
  await page.locator('button[type=submit]').click();await page.waitForURL('**/admin?saved=1');
  assert.equal(await page.locator('#step-order').inputValue(),'professionista,prestazione,sede,orario,perchi,verifica,dati,riepilogo');
  assert.equal(await page.locator('[name="workflow[senza_verifica][recapito]"]').inputValue(),'email');
  const popupPromise=page.waitForEvent('popup');await page.locator('#open-patient').click();const patient=await popupPromise;
  await patient.waitForLoadState();await patient.getByRole('heading',{name:'Prenota la tua visita di prova',exact:true}).waitFor();
  assert.equal(await patient.evaluate(()=>window.patientBooking.catalog.config.passi[0].id),'professionista');
  assert.equal(await patient.evaluate(()=>window.patientBooking.catalog.config.passi.find(p=>p.id==='perchi').attivo),false);
  await patient.close();
  await page.locator('[name="workflow[testi][benvenuto_titolo]"]').fill('');await page.locator('[name="workflow[senza_verifica][recapito]"]').selectOption('almeno_uno');await page.locator('[data-step="perchi"] [type=checkbox]').check();await page.locator('[data-step="prestazione"] [data-move="up"]').click();await page.locator('button[type=submit]').click();await page.waitForURL('**/admin?saved=1');
  assert.deepEqual(errors,[]);console.log('PASS admin responsive layout, save, ordering, field rules, patient button and applied configuration.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
