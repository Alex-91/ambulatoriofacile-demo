const {chromium}=require('playwright'),assert=require('assert');
(async()=>{
 const origin=process.argv[2],browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXE});
 const page=await browser.newPage({viewport:{width:1280,height:1000}}),errors=[];
 page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
 try {
  await page.goto(origin+'/admin/A03-link-aspetto');
  const before=await page.evaluate(()=>window.bookingAdmin.state.published.comunicazioni.contatti);
  await page.getByRole('link',{name:'Modifica i contatti',exact:true}).click();
  const dialog=page.locator('dialog');await dialog.waitFor();
  await dialog.getByLabel('Telefono dello spazio',{exact:true}).fill('0698765432');
  await dialog.getByLabel('Email dello spazio',{exact:true}).fill('cliente@example.test');
  const [saved]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/editor/save')),dialog.getByRole('button',{name:'Salva bozza',exact:true}).click()]);
  assert.equal(saved.status(),200);await dialog.waitFor({state:'detached'});
  await page.reload();
  const draft=await page.evaluate(()=>window.bookingAdmin.state);
  assert.equal(draft.draft.comunicazioni.contatti.telefono,'0698765432');
  assert.equal(draft.draft.comunicazioni.contatti.email,'cliente@example.test');
  assert.deepEqual(draft.published.comunicazioni.contatti,before);
  await page.screenshot({path:'output/patient-booking/tenant-contacts-draft.png',fullPage:true});
  await page.goto(origin+'/admin/A04-configurazione-flusso');
  const [published]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/editor/publish')),page.getByRole('button',{name:'Pubblica',exact:true}).first().click()]);
  assert.equal(published.status(),200);
  await page.goto(origin+'/admin/A03-link-aspetto');
  const state=await page.evaluate(()=>window.bookingAdmin.state);
  assert.equal(state.published.comunicazioni.contatti.email,'cliente@example.test');
  assert.equal(state.published.comunicazioni.contatti.telefono,'0698765432');
  await page.goto(origin+'/paziente');
  assert.equal(await page.evaluate(()=>window.patientBooking.studio.email),'cliente@example.test');
  assert.equal(await page.evaluate(()=>window.patientBooking.studio.telefono),'0698765432');
  assert.deepEqual(errors,[]);
  console.log('PASS tenant contacts: edit in original administration, persist draft, publish, patient data, no browser errors.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
