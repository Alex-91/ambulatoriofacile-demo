// Loopback-only visual preview. Synthetic fixtures and memory only; no application DB.
const fs=require('fs'),path=require('path'),http=require('http'),{execFileSync}=require('child_process');
const root=path.resolve(__dirname,'..');
const catalog={
 professionisti:[{id:'10',nome:'Elena',cognome:'Conti',titolo:'Dott.ssa',qualifica:'',sedi:['1','2']},{id:'20',nome:'Luca',cognome:'Romano',titolo:'Dott.',qualifica:'',sedi:['1']}],
 prestazioni:[{id:'1',nome:'Prima visita',specialita:'Prestazioni',durata:45,prezzo:null,professionisti:['10','20'],sedi:['1','2'],parole:'Prima visita'},{id:'2',nome:'Visita di controllo',specialita:'Prestazioni',durata:30,prezzo:null,professionisti:['10','20'],sedi:['1','2'],parole:'Controllo'}],
 sedi:[{id:'1',nome:'Sede centrale',indirizzo:'Via di esempio 10',cap:'00100',citta:'Roma',prov:'RM'},{id:'2',nome:'Seconda sede',indirizzo:'Via di esempio 20',cap:'00100',citta:'Roma',prov:'RM'}]
};
let config={doctors:[10,20],visits:[1,2],locations:[1,2],telefono:'0612345678',email:'segreteria@example.test',privacy_url:'https://example.test/privacy',first_available:true,min_notice:120,days:90,cancel_hours:24,workflow:{}},revision=1;
let bookings=[];
function php(file,input){return execFileSync('php',['-d','xdebug.mode=off',path.join(root,'rest/tests/_support',file)],{input:JSON.stringify(input),encoding:'utf8',stdio:['pipe','pipe','pipe']});}
function normalize(c){return JSON.parse(php('patient_booking_admin_preview.php',{mode:'config',config:c}));}
config=normalize(config).config;
function currentCatalog(){return {...catalog,professionisti:catalog.professionisti.filter(p=>config.doctors.includes(+p.id)),prestazioni:catalog.prestazioni.filter(p=>config.visits.includes(+p.id)).map(p=>({...p,professionisti:p.professionisti.filter(id=>config.doctors.includes(+id)),sedi:p.sedi.filter(id=>config.locations.includes(+id))})),sedi:catalog.sedi.filter(p=>config.locations.includes(+p.id)),config:normalize(config).workflow,prenotazioni_attive:true,modalita:'aperta',invito:null,regole:{max_attive:100,finestra_giorni:config.days,preavviso_sposta_ore:config.cancel_hours,riserva_minuti:0}};}
const server=http.createServer(async(req,res)=>{
 try {
 const u=new URL(req.url,'http://127.0.0.1');res.setHeader('Cache-Control','no-store');
 if(u.pathname==='/admin/save'&&req.method==='POST'){
  const chunks=[];for await(const chunk of req)chunks.push(chunk);
  const form=await new Response(Buffer.concat(chunks),{headers:{'Content-Type':req.headers['content-type']}}).formData();
  const get=k=>form.get(k),checked=k=>get(k)==='1';
  if(+get('revision')!==revision)throw Error('Configurazione cambiata. Ricarica la pagina.');
  const next=structuredClone(config);
  for(const key of ['doctors','visits','locations'])next[key]=form.getAll('booking['+key+'][]').map(Number);
  for(const key of ['telefono','email','privacy_url'])next[key]=get('booking['+key+']')||'';
  for(const key of ['min_notice','days','cancel_hours'])next[key]=+get('booking['+key+']');
  next.first_available=checked('booking[first_available]');
  const w=next.workflow;
  w.passi=get('workflow[order]').split(',').map(id=>({id,attivo:form.getAll('workflow[active][]').includes(id)}));
  for(const key of ['salta_professionista_se_unico','salta_sede_se_unica'])w.scelte[key]=checked('workflow[scelte]['+key+']');
  for(const key of ['prestazione_predefinita','professionista_predefinito'])w.scelte[key]=get('workflow[scelte]['+key+']')||null;
  w.senza_verifica.recapito=get('workflow[senza_verifica][recapito]');w.dati.note=get('workflow[dati][note]');
  w.regole.spostamento_consentito=checked('workflow[regole][spostamento_consentito]');
  for(const key of Object.keys(w.testi))w.testi[key]=get('workflow[testi]['+key+']');
  w.aspetto.mostra_nome_con_logo=checked('workflow[aspetto][mostra_nome_con_logo]');
  if(checked('remove_logo'))w.aspetto.logo_url=null;
  const logo=get('logo');if(logo&&logo.size)w.aspetto.logo_url='data:'+logo.type+';base64,'+Buffer.from(await logo.arrayBuffer()).toString('base64');
  config=normalize(next).config;revision++;res.writeHead(303,{Location:'/admin?saved=1'});res.end();return;
 }
 if(u.pathname.startsWith('/editor/')&&req.method==='POST'){
  let raw='';for await(const chunk of req)raw+=chunk;
  if(+req.headers['if-match']!==revision){res.writeHead(409,{'Content-Type':'application/json'});res.end(JSON.stringify({messaggio:'Bozza cambiata',csrf:'preview'}));return;}
  try{const result=JSON.parse(php('patient_booking_admin_preview.php',{mode:'transition',config,revision,action:u.pathname.split('/')[2],payload:JSON.parse(raw)}));config=result.config;revision++;res.setHeader('Content-Type','application/json');res.end(JSON.stringify({state:result.state,csrf:'preview'}));}
  catch(e){res.writeHead(422,{'Content-Type':'application/json'});res.end(JSON.stringify({messaggio:e.stderr?.toString()||e.message,csrf:'preview'}));}return;
 }
 if(u.pathname==='/'||u.pathname==='/admin'||u.pathname.startsWith('/admin/A')){
  res.setHeader('Content-Type','text/html; charset=utf-8');res.end(php('patient_booking_admin_preview.php',{mode:'design',board:u.pathname.split('/')[2]||'A01-regole',config,catalog,revision}).replaceAll('http://booking-test.invalid/','/'));return;
 }
 if(u.pathname==='/paziente'){
  const studio={tenantKey:'preview',nome:'Studio dimostrativo',monogramma:'SD',telefono:config.telefono,telHref:'tel:'+config.telefono,email:config.email,whatsapp:'',waHref:'#',ragioneSociale:'Studio dimostrativo',piva:''};
  res.setHeader('Content-Type','text/html; charset=utf-8');res.end(php('patient_booking_view.php',{studio,catalog:currentCatalog(),api:'/api',csrf:'preview',csrfHeader:'X-CSRF-TOKEN',today:'2026-10-22T12:00:00',privacy:config.privacy_url,back:'/admin'}).replaceAll('http://booking-test.invalid/','/'));return;
 }
 if(u.pathname.startsWith('/api/')){
  let body={};if(req.method==='POST'){let raw='';for await(const chunk of req)raw+=chunk;body=JSON.parse(raw);}
  let result;
  if(u.pathname==='/api/catalogo')result=currentCatalog();
  else if(u.pathname==='/api/disponibilita'){
   const doctor=u.searchParams.get('professionista'),site=u.searchParams.get('sede');
   const slot={data:u.searchParams.get('dal')||'2026-11-12',ora:'9:00',professionista:doctor==='primo'||!doctor?String(config.doctors[0]):doctor,sede:site||String(config.locations[0])};
   if(u.searchParams.get('prima_disponibilita'))result={primo:slot};else{const giorni={};const until=u.searchParams.get('al')||slot.data;for(let day=slot.data;day<=until;day=new Date(new Date(day+'T12:00:00Z').getTime()+86400000).toISOString().slice(0,10))giorni[day]={stato:'posti',orari:[{...slot,data:day},{...slot,data:day,ora:'10:00'}]};result={giorni};}
  }else if(u.pathname==='/api/prenotazioni'&&req.method==='POST'){const p={...body.slot,paziente:body.paziente,ref:'ABC'+String(234+bookings.length),stato:'confermata',...body.contatto,canale:null,verificata:false,ics_sequence:0};bookings.push(p);result={prenotazione:p};}
  else if(u.pathname==='/api/prenotazioni/mie')result={prenotazioni:bookings};
  else {const p=bookings.find(p=>p.ref===u.pathname.split('/')[3]);if(!p){res.statusCode=404;result={errore:'non_trovata'};}else{if(u.pathname.endsWith('/annulla'))p.stato='annullata';if(u.pathname.endsWith('/sposta'))Object.assign(p,{data:body.data,ora:body.ora});result={prenotazione:p};}}
  res.setHeader('Content-Type','application/json');res.end(JSON.stringify({...result,csrf:'preview'}));return;
 }
 const file=path.resolve(root,'.'+u.pathname);
 if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.statusCode=404;res.end();return;}
 res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':'application/octet-stream');res.end(fs.readFileSync(file));
 }catch(e){res.statusCode=400;res.setHeader('Content-Type','text/plain; charset=utf-8');res.end(e.stderr?.toString()||e.message);}
});
server.listen(0,'127.0.0.1',()=>console.log('PREVIEW_URL=http://127.0.0.1:'+server.address().port+'/admin'));
