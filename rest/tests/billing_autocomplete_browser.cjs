const {chromium}=require('playwright');
const fs=require('fs'),assert=require('assert');
process.chdir(require('path').resolve(__dirname,'../..'));
(async()=>{
 const browser=await chromium.launch({headless:true});const page=await browser.newPage({viewport:{width:1000,height:800}});
 try{
 const names=['patient_search_term','patient_name','patient_last_name','patient_first_name','patient_tax_code','patient_phone','patient_mobile','patient_email','patient_address','patient_city','id_client'];
 await page.setContent('<div style="height:150px"></div><div id="root" data-autocomplete-url="/synthetic" style="margin-left:120px;width:400px;overflow:hidden">'+names.map(n=>`<input name="${n}" style="${n==='patient_search_term'?'width:390px;height:35px':'display:none'}">`).join('')+'<div data-role="patient-results" style="display:none;background:white"></div><div data-role="patient-help"></div><button data-role="patient-unlink"></button><span data-role="patient-link-pill"></span></div><div style="height:1500px"></div>');
 await page.addScriptTag({path:'public/plugins/jQuery/jQuery-2.1.4.min.js'});
 await page.evaluate(()=>{window.requests=[];$.getJSON=(url,data)=>{const d=$.Deferred();d.abort=()=>{};requests.push({d,data});return d;};});
 let src=fs.readFileSync('rest/app/Views/admin/billing/document_form.php','utf8');src=src.slice(src.indexOf('    function initPatientAutocomplete'),src.indexOf('    function initServiceAutocomplete'));
 await page.addScriptTag({content:'(function($){'+src+'initPatientAutocomplete($("#root"));})(jQuery);'});
 const input=page.locator('[name=patient_search_term]');await input.fill('Al');await page.waitForFunction(()=>requests.length===1);await input.fill('Be');await page.waitForFunction(()=>requests.length===2);
 await page.evaluate(()=>requests[1].d.resolve({ok:true,results:[{id_client:2,label:'Beta',first_name:'Beta',last_name:'Test'}]}));
 await page.evaluate(()=>requests[0].d.resolve({ok:true,results:[{id_client:1,label:'Alpha',first_name:'Alpha',last_name:'Test'}]}));
 assert((await page.locator('[data-role=patient-results]').innerText()).includes('Beta'));assert(!(await page.locator('[data-role=patient-results]').innerText()).includes('Alpha'));
 const box=await input.boundingBox(),list=await page.locator('[data-role=patient-results]').boundingBox();assert(Math.abs(list.y-box.y-box.height-4)<2);assert(Math.abs(list.x-box.x)<2);
 await page.evaluate(()=>window.scrollBy(0,90));await page.waitForTimeout(80);
 const moved=await input.boundingBox(),menu=await page.locator('[data-role=patient-results]').boundingBox();assert(Math.abs(menu.y-moved.y-moved.height-4)<2);
 await page.setViewportSize({width:720,height:650});const resized=await page.locator('[data-role=patient-results]').boundingBox();assert(resized.x+resized.width<=720);
 await page.locator('.patient-autocomplete-item').click();assert.equal(await page.locator('[name=id_client]').inputValue(),'2');
 console.log('PASS: stale async responses ignored, portal placement, scroll, resize and patient selection');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
