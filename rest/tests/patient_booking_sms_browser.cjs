// Browser contract against the loopback synthetic preview; never sends an SMS.
const {chromium}=require('playwright'),assert=require('assert'),fs=require('fs');
(async()=>{
 const origin=process.argv[2],browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXE});
 const page=await browser.newPage({viewport:{width:1280,height:1000}}),errors=[],reserveRequests=[];let submitted;
 page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
 page.on('request',r=>{if(r.url().includes('/api/riserva'))reserveRequests.push(r.url());if(r.method()==='POST'&&r.url().endsWith('/api/prenotazioni'))submitted=r.postDataJSON();});
 fs.mkdirSync('output/patient-booking/sms',{recursive:true});
 try{
  await page.goto(origin+'/admin/A05-configurazione-verifica');
  assert(await page.locator('#senza-registrazione').isChecked());assert(!(await page.locator('#metodo-sms').isChecked()));
  assert(!(await page.locator('#metodo-sms').isDisabled()));
  await page.locator('#metodo-sms').check({force:true});
  assert(!(await page.locator('#senza-registrazione').isChecked()));
  const [published]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/editor/publish')),page.getByRole('button',{name:'Pubblica',exact:true}).first().click()]);
  assert.equal(published.status(),200);
  await page.goto(origin+'/admin/A04-configurazione-flusso');assert(await page.locator('#passo-verifica').isChecked());
  await page.goto(origin+'/paziente');
  await page.getByRole('button',{name:"Scegli il giorno e l'orario",exact:true}).click();
  await page.locator('#screen [data-action=ora]').first().waitFor();await page.locator('#screen [data-action=ora]').first().click();
  await page.getByRole('button',{name:'Scegli questo orario',exact:true}).first().click();
  await page.getByRole('heading',{name:'Verifica che sei tu',exact:true}).waitFor();
  assert.equal(await page.locator('#dati-nome').count(),0);assert.equal(submitted,undefined);
  await page.locator('#cell').fill('3470000000');
  let failOnce=true;await page.route('**/api/otp/invia',route=>{if(failOnce){failOnce=false;return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({errore:'invio_sms_non_riuscito',messaggio:'Non è stato possibile inviare l’SMS. Riprova o contatta lo studio.',csrf:'preview'})});}return route.continue();});
  await page.locator('[data-action=cta]').filter({visible:true}).first().click();await page.getByText('Non è stato possibile inviare l’SMS. Riprova o contatta lo studio.',{exact:true}).waitFor();
  assert.equal(await page.locator('#dati-nome').count(),0);
  await page.locator('[data-action=cta]').filter({visible:true}).first().click();await page.locator('#otp-0').waitFor();
  await page.locator('#otp-0').fill('000000');await page.getByText(/Codice non corretto/).waitFor();
  assert.equal(await page.locator('#dati-nome').count(),0);assert.equal(submitted,undefined);
  assert.equal(await page.locator('#side .sum-label').filter({hasText:/^(Prestazione|Sede)$/}).count(),0);
  await page.screenshot({path:'output/patient-booking/sms/codice-errato-desktop.png'});
  await page.setViewportSize({width:390,height:844});await page.locator('#otp-0').fill('654321');await page.locator('#dati-nome').waitFor();
  await page.screenshot({path:'output/patient-booking/sms/numero-verificato-mobile.png'});
  await page.locator('#dati-nome').fill('Rosa');await page.locator('#dati-cognome').fill('Bianchi');await page.locator('#dati-email').fill('rosa@example.test');await page.locator('#canale-email').check();await page.locator('#dati-privacy').check();
  await page.locator('[data-action=cta]').filter({visible:true}).first().click();
  // A stale/revoked server proof must send the patient back to verification, even if JS says verified.
  await page.route('**/api/prenotazioni',route=>route.fulfill({status:401,contentType:'application/json',body:JSON.stringify({errore:'verifica_richiesta',csrf:'preview'})}));
  await page.getByRole('button',{name:'Conferma la prenotazione',exact:true}).first().click();
  await page.getByRole('heading',{name:'Verifica che sei tu',exact:true}).waitFor();
  assert(new URL(page.url()).pathname==='/paziente');await page.unroute('**/api/prenotazioni');
  await page.locator('[data-action=cta]').filter({visible:true}).first().click();await page.locator('#otp-0').waitFor();await page.locator('#otp-0').fill('654321');await page.locator('#dati-nome').waitFor();
  await page.locator('[data-action=cta]').filter({visible:true}).first().click();
  const [booked]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/api/prenotazioni')),page.getByRole('button',{name:'Conferma la prenotazione',exact:true}).first().click()]);
  assert.equal(booked.status(),200);assert.equal(submitted.contatto.cellulare,'3470000000');assert.equal(submitted.slot.prestazione,null);
  await page.getByRole('heading',{name:/Ci vediamo/}).waitFor();assert.equal((await booked.json()).prenotazione.verificata,true);
  await page.goto(origin+'/paziente');await page.getByRole('button',{name:'Le mie prenotazioni',exact:true}).click();await page.locator('#cell').waitFor();assert.equal(new URL(page.url()).hash,'#mie-accesso');
  assert.deepEqual(reserveRequests,[]);assert.deepEqual(errors,[]);
  console.log('PASS SMS browser: admin enable/publish and workflow sync, delivery failure, wrong code cannot advance, valid code, verified number submitted, confirmation, desktop/mobile.');
 }catch(e){console.error('Screen:',await page.locator('body').innerText());console.error('Browser errors:',errors);throw e;}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
