const {chromium}=require('playwright'),{PNG}=require('pngjs'),fs=require('fs'),path=require('path');
const {server,catalog,source,root}=require('./patient_booking_browser.cjs');
const startup=/\(async function avvio\(\) \{[\s\S]*?\}\)\(\);/;
const screens=['benvenuto','prestazione','professionista','sede','orario','perchi','dati','riepilogo','preso','doppione','limite','nonammesso','esito','mie','dettaglio','sposta','sposta-conferma','annullata','contatto','contatto-ok'];
const cases=screens.map(screen=>({name:screen,screen})).concat([
 {name:'esito-in-attesa',screen:'esito',status:'in_attesa'}, {name:'dettaglio-in-attesa',screen:'dettaglio',status:'in_attesa'},
 {name:'dettaglio-annullata',screen:'dettaglio',status:'annullata'}, {name:'dettaglio-tardi',screen:'dettaglio',date:'2026-10-22'},
 {name:'dati-errori',screen:'dati',invalid:true},{name:'dati-per-altro',screen:'dati',other:true},{name:'perchi-altro',screen:'perchi',other:true},
 {name:'contatto-errori',screen:'contatto',invalid:true},{name:'prestazione-nessun-risultato',screen:'prestazione',query:'inesistente'},
 {name:'orario-vuoto',screen:'orario',empty:true},{name:'orario-oltre30',screen:'orario',date:'2026-12-12'},
 {name:'mie-vuoto',screen:'mie',empty:true},{name:'mie-passate',screen:'mie',tab:'passate'},{name:'mie-annullate',screen:'mie',tab:'annullate'},
 {name:'dettaglio-menu-calendario',screen:'dettaglio',menu:'det-ABC234'},{name:'dettaglio-menu-contatti',screen:'dettaglio',menu:'contatti-ABC234'},
 {name:'benvenuto-sospeso',screen:'benvenuto',suspended:true},{name:'benvenuto-invito',screen:'benvenuto',invite:true},
 {name:'annulla-dialogo',screen:'dettaglio',modal:true},{name:'annulla-richiesta',screen:'dettaglio',modal:true,status:'in_attesa'},{name:'annulla-motivo',screen:'dettaglio',modal:true,motivo:true},
 {name:'esito-senza-email',screen:'esito',noemail:true},{name:'dettaglio-rifiutata',screen:'dettaglio',status:'rifiutata'},{name:'dettaglio-scaduta',screen:'dettaglio',status:'scaduta'},
 {name:'benvenuto-logo',screen:'benvenuto',logo:true},{name:'dati-campi-obbligatori',screen:'dati',required:true}
]);
(async()=>{await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin='http://127.0.0.1:'+server.address().port;
const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXE}),out=path.join(root,'output/patient-booking/parity');fs.mkdirSync(out,{recursive:true});const report=[];
try{for(const width of [390,1280]){const pages=[];
 for(const reference of [true,false]){const page=await browser.newPage({viewport:{width,height:1000}});pages.push(page);await page.addInitScript(()=>Object.defineProperty(navigator,'standalone',{value:true}));await page.route('**/*',async route=>{
 const url=new URL(route.request().url());if(reference&&url.pathname==='/reference'){let html=source.replace(startup,'').replace(/<link[^>]+fonts\.(?:googleapis|gstatic)\.com[^>]*>\s*/g,'').replace('</head>','<link rel="stylesheet" href="/public/assets/patient-booking/fonts.css"></head>');html=html.replace(/const demoHint = .*?;\r?\n/,"const demoHint=()=>'';\n");return route.fulfill({contentType:'text/html',body:html});}
 if(url.pathname.endsWith('/booking.js'))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.join(root,'public/assets/patient-booking/booking.js'),'utf8').replace(startup,'')});
 if(url.origin!==origin)return route.abort();return route.continue();});await page.goto(origin+(reference?'/reference':'/'));await page.evaluate(()=>document.fonts.ready);}
 for(const scenario of cases){let captures=[];try{for(const page of pages){const result=await page.evaluate(({scenario,catalog})=>{
 chiudiSheet();state.catalogo=structuredClone(catalog);state.catalogo.prenotazioni_attive=!scenario.suspended;
 if(scenario.logo)state.catalogo.config.aspetto.logo_url='data:image/svg+xml;base64,'+btoa('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" fill="#00795f"/></svg>');
 if(scenario.required)for(const f of ['nascita','codice_fiscale','note'])state.catalogo.config.dati[f]='obbligatorio';
 const p={ref:'ABC234',stato:scenario.status||'confermata',data:scenario.date||'2026-11-12',ora:'9:00',prestazione:'derm',professionista:'conti',sede:'empoli',paziente:{nome:'Rosa',cognome:'Bianchi'},cellulare:'3478126650',email:'rosa@example.test',canale:null,ics_sequence:0,verificata:false,notificata:true};
 if(scenario.noemail)p.email='';
 if(scenario.invite){state.catalogo.modalita='invito';state.catalogo.invito={...p,nome:'Rosa',valido_fino:'2026-12-31'};}
 state.ui=nuovaUi();Object.assign(state.ui,{screen:scenario.screen,ref:p.ref,query:scenario.query||'',mieTab:scenario.tab||'prossime',menuAperto:scenario.menu||null,sposta:{...p,data:'2026-11-13',ora:'10:00'},alternative:[{...p,ora:'10:00'},{...p,ora:'11:00'}],doppione:p,limite:{massimo:2},annullata:p,contattoInviato:p.cellulare});
 state.sessione={cellulare:scenario.screen==='mie'?'3478126650':null,paziente:null};state.draft=nuovaBozza();Object.assign(state.draft,{...p,proAssegnato:'conti',sedeAssegnata:'empoli',esito:p,autorizzato:true,perChi:scenario.other?'altro':'me',assistito:{nome:'Franco',cognome:'Rinaldi',nascita:'05/02/1956'}});Object.assign(state.draft.dati,{nome:'Rosa',cognome:'Bianchi',cellulare:p.cellulare,email:p.email,canale:null,privacy:true});
 if(scenario.invalid){state.draft.dati=nuovaBozza().dati;state.ui.tentato={dati:true,contatto:true};}
 state.cache={disp:new Map(),primi:new Map(),mie:scenario.empty?[]:[p],mieInCorso:false};api.disponibilita=()=>new Promise(()=>{});api.mie=()=>new Promise(()=>{});
 if(['orario','sposta'].includes(scenario.screen)){const q=parametriOrario(),d=dispPer(q);d.primo=scenario.empty?null:p;for(let i=0;i<120;i++){const iso=piuGiorni('2026-10-21',i);d.giorni[iso]={stato:'posti',orari:[{...p,data:iso},{...p,data:iso,ora:'10:00'}]};}}
 const rendered=SCHERMATE[scenario.screen].render();document.querySelector('#screen').innerHTML='<div class="screen">'+(rendered.html||'')+'</div>';
 document.querySelector('#demo')?.remove();document.querySelector('#bar').innerHTML='';document.querySelector('#side').innerHTML='';document.querySelector('#cta-inline').innerHTML='';
 if(scenario.modal){apriAnnulla(p.ref);if(scenario.motivo){const el=document.querySelector('#motivo-1');el.checked=true;CHANGE.motivo(el);}}
 return {text:document.querySelector(scenario.modal?'#sheet':'#screen').innerText,cta:rendered.cta?.label||null};
 },{scenario,catalog});await page.addStyleTag({content:'*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}'});captures.push({...result,png:await page.locator(scenario.modal?'#sheet':'#screen').screenshot()});}
 const [a,b]=captures,aa=PNG.sync.read(a.png),bb=PNG.sync.read(b.png);let changed=0;if(aa.width!==bb.width||aa.height!==bb.height)changed=-1;else for(let i=0;i<aa.data.length;i+=4)if(aa.data[i]!==bb.data[i]||aa.data[i+1]!==bb.data[i+1]||aa.data[i+2]!==bb.data[i+2])changed++;
 const same=a.text===b.text&&a.cta===b.cta&&changed===0;report.push({state:scenario.name,width,same,changed,textEqual:a.text===b.text,ctaEqual:a.cta===b.cta});if(!same){for(const [i,label]of ['reference','actual'].entries())fs.writeFileSync(path.join(out,`${scenario.name}-${width}-${label}.png`),captures[i].png);fs.writeFileSync(path.join(out,`${scenario.name}-${width}.json`),JSON.stringify({reference:{text:a.text,cta:a.cta},actual:{text:b.text,cta:b.cta}},null,2));}
 }catch(e){report.push({state:scenario.name,width,error:e.message});}}
 for(const p of pages)await p.close();}
 fs.writeFileSync(path.join(out,'report.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report.filter(r=>!r.same),null,2));console.log(`${report.filter(r=>r.same).length}/${report.length} exact patient screen comparisons`);if(report.some(r=>!r.same))process.exitCode=1;
}finally{await browser.close();server.closeAllConnections();server.close();}})().catch(e=>{console.error(e);server.closeAllConnections();server.close();process.exitCode=1;});
