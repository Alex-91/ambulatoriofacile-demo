// Reproducible adapter: original ZIP is retained byte-for-byte under Resources.
// CSS, SVG artwork and rendering templates come from the supplied prototype.
const fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'..');
const base=path.join(root,'rest/app/Resources/patient-booking');
const source=fs.readFileSync(path.join(base,'source/prototipo.html'),'utf8');
const readme=fs.readFileSync(path.join(base,'source/README.md'),'utf8');
const config=JSON.parse(readme.match(/```json\s*(\{[\s\S]*?\})\s*```/)[1]);
fs.writeFileSync(path.join(base,'defaults.json'),JSON.stringify(config,null,2)+'\n');
let js=source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/\r\n/g,'\n');
function between(start,end,replacement) {
 const a=js.indexOf(start),b=js.indexOf(end,a);
 if(a<0||b<0)throw Error('Prototype boundary missing: '+start);
 js=js.slice(0,a)+replacement+'\n'+js.slice(b);
}
function replace(a,b){if(!js.includes(a))throw Error('Prototype anchor missing: '+a);js=js.replace(a,b);}
between('const CONFIG_PREDEFINITA =','// Logo d\'esempio', 'const BOOT = window.patientBooking;\nconst CONFIG_PREDEFINITA = BOOT.catalog.config;');
replace('const OGGI = new Date(2026, 9, 21, 20, 40);','const OGGI = new Date(BOOT.today);');
between('const STUDIO =','const MOTIVI_ANNULLO =',`const STUDIO = BOOT.studio;
const SEDI = Object.fromEntries(BOOT.catalog.sedi.map(s=>[s.id,s]));
const PROFESSIONISTI = Object.fromEntries(BOOT.catalog.professionisti.map(p=>[p.id,p]));
const PRESTAZIONI = BOOT.catalog.prestazioni;
const SPECIALITA = [...new Set(PRESTAZIONI.map(p=>p.specialita))];
const AGENDE = Object.fromEntries(BOOT.catalog.professionisti.map(p=>[p.id,{sedi:Object.fromEntries(p.sedi.map(s=>[s,{}]))}]));
const NUMERO_ROSA='', NUMERO_DAVIDE='', DATI_ROSA={}, QR_DEMO='';`);
between('const FakeServer =','class ErroreApi', '');
replace('const API_BASE = `/api/prenota/${STUDIO.tenantKey}`;','const API_BASE = BOOT.api;');
between('async function chiama(', 'const api =', `let csrfToken = BOOT.csrf;
let requestQueue=Promise.resolve();
async function chiama(metodo, percorso, corpo, intestazioni={}) {
  // Serialize all requests, including reads, because CSRF tokens can rotate on writes.
  const perform=async()=>{
    if (metodo==='POST' && percorso==='/prenotazioni') corpo={...corpo, privacy_letta:state.draft.dati.privacy, per:state.draft.perChi, autorizzato:state.draft.autorizzato};
    if (metodo==='POST' && percorso==='/contatto') corpo={...corpo,privacy_letta:state.ui.contatto.privacy};
    const url=new URL(API_BASE+percorso,location.href);
    const invitation=new URLSearchParams(location.search).get('invito');
    if(invitation)url.searchParams.set('invito',invitation);
    if(BOOT.draftPreview)url.searchParams.set('bozza','1');
    if(corpo && percorso==='/prenotazioni')corpo.invito=invitation;
    const r=await fetch(url,{method:metodo,credentials:'same-origin',headers:{'Content-Type':'application/json',[BOOT.csrfHeader]:csrfToken,...intestazioni},body:corpo?JSON.stringify(corpo):undefined});
    let body;try{body=await r.json();}catch(e){throw new ErroreApi(r.status,{errore:'riprova'});}
    if(body.csrf)csrfToken=body.csrf;
    if(!r.ok){
      if(r.status===401){location.href=BOOT.back;throw new ErroreApi(r.status,body);}
      if(['limite_prenotazioni','prestazione_gia_prenotata'].includes(body.errore))throw new ErroreApi(422,{...body,errore:'limite_raggiunto',massimo:BOOT.catalog.regole.max_attive});
      throw new ErroreApi(r.status,body);
    }
    return body;
  };
  const result=requestQueue.then(perform,perform);requestQueue=result.catch(()=>{});return result;
}
`);
replace("const turnstile = () => attesa(1000).then(() => 'token-turnstile-demo');", "// This entry point is authenticated and internal; public verification is not simulated.\nconst turnstile = () => Promise.resolve('');");
replace('catalogo: null,','catalogo: BOOT.catalog,');
replace('const demoHint = html => `<div class="demo-hint" role="note"><b>DEMO</b> · ${html}</div>`;','const demoHint = () => \'\';');
between('function disegnaDemo()','/* ==========================================================================\n   12. AVVIO', 'function disegnaDemo() {}\nfunction aggiornaDemo() {}\n');
replace("state.ui.cellulareInput = formattaCell(NUMERO_ROSA); // il pannello demo parte con Rosa", "state.ui.cellulareInput = ''; // No example patient data in the live form.");
replace("'nota-legale'() { toast('Nel prodotto vero qui si apre l\\'informativa privacy o i termini dello studio.'); },", "'nota-legale'() { window.open(BOOT.privacy, '_blank', 'noopener,noreferrer'); },");
// Never claim that a notification was sent from this internal test entry point.
replace('function doveScriviamo(canale, cellulare, email) {', 'function doveScriviamo(canale, cellulare, email) {\n  return null; /* Delivery is deferred until the public patient channel is configured. */');
replace("const standalone = () => (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;", 'const standalone = () => true; // No installable public patient application in this phase.');
replace('  document.title = `${titoloDocumento(s)} · ${STUDIO.nome}`;', `  const visible=cfg().comunicazioni.visibili||{};
  document.querySelectorAll('.contact-list a').forEach(a=>{const kind=a.href.startsWith('tel:')?'telefono':a.href.startsWith('mailto:')?'email':'whatsapp';a.hidden=visible[kind]===false||(kind==='whatsapp'&&!STUDIO.whatsapp);});
  document.querySelectorAll('#side .sede-list').forEach(el=>el.hidden=visible.sedi===false);
  document.title = \`\${titoloDocumento(s)} · \${STUDIO.nome}\`;`);
replace('Ciao ${esc(inv.nome)}, lo studio ti invita a prenotare', "Ciao${inv.nome ? ' '+esc(inv.nome) : ''}, lo studio ti invita a prenotare");
replace('const gestibile = verificato();','const gestibile = true; // The authenticated operator can manage bookings they created.');
replace("${azione('move', 'Sposta l\\'appuntamento', 'sposta')}", "${cfg().regole.spostamento_consentito ? azione('move', 'Sposta l\\'appuntamento', 'sposta') : ''}");
replace("return verificato() ? null : conMiePrenotazioni() ? 'mie-accesso' : 'benvenuto';", "return null; // Server authorizes management against the authenticated operator.");
replace("'apri-mie'() { vai(verificato() ? 'mie' : 'mie-accesso'); },", "'apri-mie'() { vai('mie'); },");
replace('const linkGestione = p => `https://ambulatoriofacile.it/prenota/${STUDIO.tenantKey}/p/${p.ref}?t=esempio`;', "const linkGestione = p => new URL(BOOT.api.replace(/\\/api$/, '/paziente') + '?ref=' + encodeURIComponent(p.ref), location.href).href;");
// The supplied prototype assumes fixed 24h notice; the value now comes from the tenant.
js=js.replaceAll('ore < 24','ore < cfg().regole.preavviso_modifica_ore');
js=js.replaceAll('24 ore','${cfg().regole.preavviso_modifica_ore} ore');
// Replacing literal text outside template strings would alter JS syntax: keep original
// literal messages at 24h until contextual copy is formatted by the renderer.
js=js.replaceAll("'Mancano meno di ${cfg().regole.preavviso_modifica_ore} ore: per spostare o annullare chiama lo studio.'","`Mancano meno di ${cfg().regole.preavviso_modifica_ore} ore: per spostare o annullare chiama lo studio.`");
js=js.replace(/'Mancano meno di \$\{cfg\(\)\.regole\.preavviso_modifica_ore\} ore: per (spostare|annullare) chiama lo studio\.'/g, match=>'`'+match.slice(1,-1)+'`');
// Preserve unknown prices as absent rather than displaying "null €".
js=js.replaceAll('${pres.prezzo} €','${pres.prezzo == null ? \'\' : pres.prezzo + \' €\'}');
js=js.replaceAll('${pres.durata} minuti · ${pres.prezzo == null ? \'\' : pres.prezzo + \' €\'}, paghi in studio', '${pres.durata} minuti${pres.prezzo == null ? \'\' : \' · \' + pres.prezzo + \' €, paghi in studio\'}');
// Studio and site strings previously came only from hard-coded fixtures.
js=js.replaceAll('${STUDIO.nome}', '${esc(STUDIO.nome)}').replaceAll('${sede.nome}', '${esc(sede.nome)}');
for(const field of ['s.nome','STUDIO.telefono','STUDIO.email','STUDIO.whatsapp','STUDIO.telHref','STUDIO.waHref']) js=js.replaceAll('${'+field+'}', '${esc('+field+')}');
js=js.replaceAll('${indirizzo(s)}','${esc(indirizzo(s))}').replaceAll('${indirizzo(sede)}','${esc(indirizzo(sede))}');
js=js.replaceAll("${sede.online ? 'Videochiamata' : sede.nome}","${sede.online ? 'Videochiamata' : esc(sede.nome)}").replaceAll("${sede.online ? 'Video' : sede.nome}","${sede.online ? 'Video' : esc(sede.nome)}");
// No demo control or simulator can be invoked through the live event delegates.
const preflight=`for (const handlers of [CLICK,CHANGE]) for (const key of Object.keys(handlers)) if (/^(demo-|cfg-)/.test(key)) delete handlers[key];
CLICK['simula-wa']=()=>{};
CLICK['esci']=()=>{location.href=BOOT.back;};

CLICK.avvisami=()=>{location.href=STUDIO.telHref;};
`;
js=js.replace('(async function avvio() {',preflight+'\n(async function avvio() {');
js=js.replace('avviato = true;',"avviato = true;\n  const params=new URLSearchParams(location.search); const combination=combinazioni().find(c=>['prestazione','professionista','sede'].every(key=>!params.get(key)||params.get(key)===c[key])); if(combination){for(const key of ['prestazione','professionista','sede'])if(params.get(key))state.draft[key]=combination[key];render();}\n  const bookingRef=new URLSearchParams(location.search).get('ref'); if(bookingRef && /^[A-Z2-9]{6}$/.test(bookingRef)) vai('dettaglio',{ref:bookingRef});");
const css=source.match(/<style>([\s\S]*?)<\/style>/)[1];
const asset=path.join(root,'public/assets/patient-booking');fs.mkdirSync(asset,{recursive:true});
fs.writeFileSync(path.join(asset,'booking.css'),css);
fs.writeFileSync(path.join(asset,'booking.js'),js);
let html=source.replace(/<script>[\s\S]*?<\/script>/,`<script>window.patientBooking = <?= json_encode($bookingBootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;</script><script src="<?= base_url('public/assets/patient-booking/booking.js') ?>"></script>`)
 .replace(/<style>[\s\S]*?<\/style>/,`<link rel="stylesheet" href="<?= base_url('public/assets/patient-booking/booking.css') ?>">`)
 .replace(/<link[^>]+fonts\.(?:googleapis|gstatic)\.com[^>]*>\s*/g,'')
 .replace('</head>',`<link rel="stylesheet" href="<?= base_url('public/assets/patient-booking/fonts.css') ?>"></head>`)
 .replace('<div id="demo" class="demo"></div>','')
 .replaceAll('Poliambulatorio Santa Chiara',"<?= esc($bookingBootstrap['studio']['nome']) ?>")
 .replace('Empoli e Vinci',"<?= esc(implode(' · ', array_column($bookingBootstrap['catalog']['sedi'], 'nome'))) ?>")
 .replace('Santa Chiara Servizi Sanitari S.r.l. · P.IVA 01234560485',"<?= esc($bookingBootstrap['studio']['ragioneSociale']) ?>")
 .replace('>SC</span>',"><?= esc($bookingBootstrap['studio']['monogramma']) ?></span>");
fs.mkdirSync(path.join(root,'rest/app/Views/patient_booking'),{recursive:true});
fs.writeFileSync(path.join(root,'rest/app/Views/patient_booking/index.php'),html);
console.log('Generated booking assets. Original CSS is byte-identical to the ZIP style block.');
