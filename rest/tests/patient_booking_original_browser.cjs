const {chromium}=require('playwright'),assert=require('assert'),path=require('path'),fs=require('fs');
(async()=>{
 const origin=process.argv[2];assert(/^http:\/\/127\.0\.0\.1:\d+$/.test(origin));
 const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXE});
 const page=await browser.newPage({viewport:{width:1280,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const out=path.resolve(__dirname,'../../output/patient-booking/original');fs.mkdirSync(out,{recursive:true});
 try{
  for(const board of ['A01-regole','A02-richieste','A03-link-aspetto','A04-configurazione-flusso','A05-configurazione-verifica']){
   await page.goto(origin+'/admin/'+board);await page.locator('h1').first().waitFor();await page.evaluate(()=>document.fonts.ready);
   await page.screenshot({path:path.join(out,board+'.png'),fullPage:true});
  }
  await page.goto(origin+'/admin/A04-configurazione-flusso');await page.locator('#testo-titolo').fill('Titolo di verifica della bozza');
  await page.waitForFunction(()=>document.body.textContent.includes('Bozza salvata in automatico.'));
  const patient=await browser.newPage();await patient.goto(origin+'/paziente');assert.equal(await patient.evaluate(()=>window.patientBooking.catalog.config.testi.benvenuto_titolo),null,'Draft must not appear to patient');
  await page.getByRole('button',{name:'Pubblica',exact:true}).first().click();await page.waitForFunction(()=>document.body.textContent.includes('Pubblicata la revisione 2'));
  await patient.reload();assert.equal(await patient.evaluate(()=>window.patientBooking.catalog.config.testi.benvenuto_titolo),'Titolo di verifica della bozza');
  await page.reload();page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Ripristina la versione 1',exact:true}).click();await page.waitForLoadState();await page.waitForFunction(()=>document.querySelector('#testo-titolo').value==='');
  await patient.reload();assert.equal(await patient.evaluate(()=>window.patientBooking.catalog.config.testi.benvenuto_titolo),'Titolo di verifica della bozza','Restore must not publish');
  await page.getByRole('button',{name:'Pubblica',exact:true}).first().click();await page.waitForFunction(()=>document.body.textContent.includes('Pubblicata la revisione 3'));
  await patient.close();assert.deepEqual(errors,[]);console.log('PASS original A01–A05 render; autosave, unpublished draft isolation, publish, history and restore.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
