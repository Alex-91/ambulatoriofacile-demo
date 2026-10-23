
'use strict';
/* ==========================================================================
   PRENOTAZIONE ONLINE DEL PAZIENTE: prototipo interattivo
   Ambulatorio Facile, da implementare nel gestionale CodeIgniter 4.

   Come è organizzato lo script (in produzione diventano moduli ES separati):
    1. DATI DEMO      studio, sedi, professionisti, prestazioni, agende finte
    2. DATE           formattazione italiana, calcoli sui giorni (Europe/Rome)
    3. FAKE SERVER    imita il backend CodeIgniter: in produzione sparisce
    4. API            client con gli stessi percorsi degli endpoint del brief §6.3
    5. STATO          bozza della prenotazione, sessione del dispositivo, interfaccia
    6. NAVIGAZIONE    passi, history (pushState), guardie
    7. COMPONENTI     pezzi di interfaccia riusati da più schermate
    8. SCHERMATE      una funzione di render per schermata
    9. AZIONI         gestori degli eventi (delegazione su data-action / data-input)
   10. SERVIZI UI     riserva, toast, bottom sheet, file .ics, installa l'app
   11. PANNELLO DEMO  strumenti di prova, fuori dal design
   12. AVVIO
   ========================================================================== */


/* ==========================================================================
   1. CONFIGURAZIONE DELLO STUDIO e DATI DEMO (fittizi, coerenti con decisioni.md)
   ========================================================================== */

// Configurazione della pagina di prenotazione, versione 1. Schema completo, regole e validazioni nella guida
// (README.md, sezione "Configurazione per studio"). La decide il team Ambulatorio Facile dall'editor super admin
// (bozza, pubblica, cronologia delle versioni); il catalogo restituisce la versione PUBBLICATA e già validata,
// e la pagina si costruisce da quella: ordine dei passi, scelte automatiche, verifica, campi, logo, testi.
const BOOT = window.patientBooking;
const CONFIG_PREDEFINITA = BOOT.catalog.config;
// Logo d'esempio per la demo (in produzione: file caricato dallo studio, PNG, JPG o SVG, max 1 MB).
// Inline come data URI così il prototipo non dipende da percorsi esterni. Orizzontale: marchio SC e nome.
const LOGO_DEMO = 'data:image/svg+xml,' + encodeURIComponent(
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 48" width="300" height="48">'
  + '<rect x="0" y="0" width="48" height="48" rx="11" fill="#08364F"/>'
  + '<text x="24" y="30.5" text-anchor="middle" font-family="Inter,Segoe UI,Arial,sans-serif" font-size="17" font-weight="600" fill="#ffffff" letter-spacing=".5">SC</text>'
  + '<text x="60" y="30" font-family="Inter,Segoe UI,Arial,sans-serif" font-size="17" font-weight="600" fill="#08364F" textLength="236" lengthAdjust="spacingAndGlyphs">Poliambulatorio Santa Chiara</text>'
  + '</svg>');

// Nomi dei passi nell'editor (e nel pannello demo).
const NOMI_PASSI = { prestazione: 'Prestazione', professionista: 'Professionista', sede: 'Sede', orario: 'Data e orario', perchi: 'Per chi è la visita', verifica: 'Verifica dell\'identità', dati: 'I tuoi dati', riepilogo: 'Riepilogo' };
const METODI_VERIFICA = ['whatsapp', 'email', 'sms'];

// Validazioni dell'editor (bloccano "Pubblica"). La stessa funzione gira lato server al salvataggio.
// Restituisce { errori, avvisi }: gli avvisi non bloccano.
function validaConfig(c, studio = { whatsappCollegato: !!STUDIO.whatsapp, prestazioni: PRESTAZIONI }) {
  const errori = []; const avvisi = [];
  const attivi = c.passi.filter(p => p.attivo).map(p => p.id);
  const pos = id => attivi.indexOf(id);
  const conVerifica = c.verifica.richiesta && attivi.includes('verifica');
  for (const id of ['orario', 'dati', 'riepilogo']) if (!attivi.includes(id)) errori.push(`Il passo "${NOMI_PASSI[id]}" non si può spegnere.`);
  if (c.passi[c.passi.length - 1].id !== 'riepilogo') errori.push('Il riepilogo deve essere l\'ultimo passo.');
  for (const id of ['prestazione', 'professionista', 'sede']) {
    if (attivi.includes(id) && pos('orario') >= 0 && pos(id) > pos('orario')) errori.push(`"${NOMI_PASSI.orario}" deve venire dopo "${NOMI_PASSI[id]}".`);
  }
  if (conVerifica && pos('dati') >= 0 && pos('dati') < pos('verifica')) errori.push('"I tuoi dati" deve venire dopo la verifica: chi è già paziente trova i dati compilati.');
  if (c.verifica.richiesta && attivi.includes('verifica') && !c.verifica.metodi.length) errori.push('Con la verifica attiva serve almeno un metodo (WhatsApp, email o SMS).');
  if (c.verifica.metodi.includes('whatsapp') && !studio.whatsappCollegato) errori.push('Per verificare con WhatsApp lo studio deve avere il numero WhatsApp collegato al gestionale.');
  if (c.accesso.modalita === 'archivio' && !conVerifica) errori.push('"Solo pazienti già in archivio" non è ammessa senza verifica: non si può sapere chi è davvero.');
  if (!conVerifica && c.senza_verifica.recapito === 'facoltativo') avvisi.push('Senza verifica e senza recapito obbligatorio lo studio non potrà avvisare il paziente se l\'appuntamento cambia.');
  if (!conVerifica && c.accesso.modalita === 'aperta') avvisi.push('Chiunque può occupare l\'agenda senza essere verificato.');
  if (!conVerifica && !c.verifica.metodi.length) avvisi.push('Nessun metodo di verifica: "Le mie prenotazioni" sparisce, si sposta e si annulla dal link nei messaggi.');
  return { errori, avvisi };
}

// "Adesso" nel prototipo: mercoledì 21 ottobre 2026, ore 20:40. Rosa prenota la sera dal divano.
const OGGI = new Date(BOOT.today);

const STUDIO = BOOT.studio;
const SEDI = Object.fromEntries(BOOT.catalog.sedi.map(s=>[s.id,s]));
const PROFESSIONISTI = Object.fromEntries(BOOT.catalog.professionisti.map(p=>[p.id,p]));
const PRESTAZIONI = BOOT.catalog.prestazioni;
const SPECIALITA = [...new Set(PRESTAZIONI.map(p=>p.specialita))];
const AGENDE = Object.fromEntries(BOOT.catalog.professionisti.map(p=>[p.id,{sedi:Object.fromEntries(p.sedi.map(s=>[s,{}]))}]));
const NUMERO_ROSA='', NUMERO_DAVIDE='', DATI_ROSA={}, QR_DEMO='';
const MOTIVI_ANNULLO = ['Ho un impegno', 'Sto meglio', 'Ho trovato un altro orario', 'Altro'];
const CANALI = [{ id: 'whatsapp', nome: 'WhatsApp' }, { id: 'sms', nome: 'SMS' }, { id: 'email', nome: 'Email' }];
const RECAPITI = { almeno_uno: 'Almeno uno tra cellulare ed email', cellulare: 'Cellulare obbligatorio', email: 'Email obbligatoria', facoltativo: 'Nessuno obbligatorio' };
const MODALITA = {
  archivio:     'a. Solo pazienti già in archivio',
  approvazione: 'b. Nuovi pazienti su approvazione',
  aperta:       'c. Aperta a tutti con verifica',
  invito:       'd. Solo su invito',
};


/* ==========================================================================
   2. DATE (tutto in ora di Roma; le date sono stringhe ISO "AAAA-MM-GG")
   ========================================================================== */

const GIORNI = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];
const GIORNI_BREVI = ['dom', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab'];
const MESI = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
const MESI_BREVI = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];

const pad = n => String(n).padStart(2, '0');
// Mai new Date("AAAA-MM-GG"): sarebbe mezzanotte UTC. Si costruisce coi componenti locali.
const isoDi = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const daIso = s => { const [y, m, g] = s.split('-').map(Number); return new Date(y, m - 1, g); };
const piuGiorni = (s, n) => { const d = daIso(s); d.setDate(d.getDate() + n); return isoDi(d); };
const diffGiorni = (a, b) => Math.round((daIso(b) - daIso(a)) / 86400000);
const OGGI_ISO = isoDi(OGGI);

const dataLunga = s => { const d = daIso(s); return `${GIORNI[d.getDay()]} ${d.getDate()} ${MESI[d.getMonth()]}`; };   // giovedì 12 novembre
const dataLungaAnno = s => `${dataLunga(s)} ${daIso(s).getFullYear()}`;                                               // giovedì 12 novembre 2026
const dataMedia = s => { const d = daIso(s); return `${GIORNI_BREVI[d.getDay()]} ${d.getDate()} ${MESI[d.getMonth()]}`; }; // mar 3 novembre
const dataBreve = s => { const d = daIso(s); return `${GIORNI_BREVI[d.getDay()]} ${d.getDate()} ${MESI_BREVI[d.getMonth()]}`; }; // gio 12 nov
const giornoMese = s => { const d = daIso(s); return `${d.getDate()} ${MESI[d.getMonth()]}`; };                       // 12 novembre
const meseAnno = s => { const d = daIso(s); return `${MESI[d.getMonth()]} ${d.getFullYear()}`; };
const maiuscola = s => s.charAt(0).toUpperCase() + s.slice(1);
const minutiDi = ora => { const [h, m] = ora.split(':').map(Number); return h * 60 + m; };
const oraDi = min => `${Math.floor(min / 60)}:${pad(min % 60)}`;
const mmss = ms => { const s = Math.max(0, Math.ceil(ms / 1000)); return `${Math.floor(s / 60)}:${pad(s % 60)}`; };
const fasciaDi = ora => (minutiDi(ora) < 13 * 60 ? 'Mattina' : minutiDi(ora) < 18 * 60 + 30 ? 'Pomeriggio' : 'Sera');

function traQuanto(iso) {
  const n = diffGiorni(OGGI_ISO, iso);
  if (n < 0) return '';
  if (n === 0) return 'oggi';
  if (n === 1) return 'domani';
  return `tra ${n} giorni`;
}
// Fuso di Roma per i link Outlook: +02:00 dall'ultima domenica di marzo all'ultima di ottobre.
function offsetRoma(iso) {
  const y = Number(iso.slice(0, 4));
  const ultimaDomenica = m => { const d = new Date(y, m + 1, 0); d.setDate(d.getDate() - d.getDay()); return isoDi(d); };
  return iso >= ultimaDomenica(2) && iso < ultimaDomenica(9) ? '+02:00' : '+01:00';
}


/* ==========================================================================
   3. FAKE SERVER
   Imita le risposte del backend. Le regole che contiene (riserva solo dopo la
   verifica, limiti, modalità dello studio, 409 con alternative) vanno lato server.
   ========================================================================== */


class ErroreApi extends Error {
  constructor(status, body) { super((body && body.errore) || 'errore'); this.status = status; this.body = body || {}; }
}
const API_BASE = BOOT.api;
const attesa = ms => new Promise(r => setTimeout(r, ms));
const query = o => new URLSearchParams(Object.entries(o).filter(([, v]) => v !== null && v !== undefined && v !== '')).toString();

let csrfToken = BOOT.csrf;
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

const api = {
  catalogo:         ()        => chiama('GET', '/catalogo'),
  disponibilita:    (q)       => chiama('GET', `/disponibilita?${query(q)}`),     // dal/al: massimo 14 giorni; oppure prima_disponibilita=1
  riserva:          (c)       => chiama('POST', '/riserva', c),                   // lock dap14 di 10 minuti, solo con numero verificato
  rilasciaRiserva:  (token)   => chiama('DELETE', `/riserva/${token}`),
  otpInvia:         (c)       => chiama('POST', '/otp/invia', c),                 // metodo whatsapp|email|sms (solo quelli accesi), con token Turnstile e campo trappola
  otpVerifica:      (c)       => chiama('POST', '/otp/verifica', c),              // codice a 6 cifre (email o SMS)
  otpStato:         (c)       => chiama('POST', '/otp/stato', c),                 // WhatsApp: 202 in attesa, 200 verificato
  // Solo demo: in produzione lo chiama il gateway WhatsApp quando arriva il messaggio del paziente.
  simulaWhatsapp:   (c)       => chiama('POST', '/webhook/whatsapp', c),
  // Con verifica: { riserva, ... }. Senza verifica: { slot, contatto, turnstile_token, trappola, ... } e controllo atomico qui.
  creaPrenotazione: (c, key)  => chiama('POST', '/prenotazioni', c, { 'Idempotency-Key': key }),
  mie:              ()        => chiama('GET', '/prenotazioni/mie'),
  dettaglio:        (ref)     => chiama('GET', `/prenotazioni/${ref}`),
  sposta:           (ref, c)  => chiama('POST', `/prenotazioni/${ref}/sposta`, c),
  annulla:          (ref, c)  => chiama('POST', `/prenotazioni/${ref}/annulla`, c),
  // GET /prenotazioni/{ref}/calendario.ics?t= : nel prototipo il file si genera nel browser (creaIcs, sezione 10)
  contatto:         (c)       => chiama('POST', '/contatto', c),
  esci:             ()        => chiama('DELETE', '/sessione'),                   // aggiunto: dimentica il dispositivo
};

// Turnstile finto: in produzione widget Cloudflare invisibile, il token va a /otp/invia e si verifica lato server.
// This entry point is authenticated and internal; public verification is not simulated.
const turnstile = () => Promise.resolve('');
const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'k-' + Date.now() + '-' + Math.random().toString(16).slice(2));


/* ==========================================================================
   5. STATO
   In produzione la bozza resta in sessionStorage fino alla conferma (solo id, mai dati personali dopo l'invio).
   ========================================================================== */

// prestazione, professionista, sede: le scelte (del paziente, automatiche o predefinite dalla configurazione).
// proAssegnato, sedeAssegnata: chi e dove per l'orario scelto (con "Il primo disponibile" o con la sede non scelta).
function nuovaBozza(base) {
  return {
    prestazione: null, professionista: null, sede: null, data: null, ora: null, proAssegnato: null, sedeAssegnata: null,
    perChi: base ? base.perChi : 'me',
    assistito: base ? { ...base.assistito } : { nome: '', cognome: '', nascita: '' },
    autorizzato: base ? base.autorizzato : false,
    dati: base ? { ...base.dati } : { nome: '', cognome: '', cellulare: '', email: '', nascita: '', cf: '', canale: null, nota: '', privacy: false },
    riserva: null, idemKey: null, esito: null, completata: false,
  };
}
const nuovoOtp = () => ({ contesto: null, cellulare: '', inviato: false, rimandaDa: 0, errore: null, bloccatoFino: 0, cifre: ['', '', '', '', '', ''], antibot: false, inVerifica: false,
  metodo: 'whatsapp', email: '', messaggio: '', waLink: '', waAperto: false, scade: 0 });
function nuovaUi() {
  return {
    screen: 'benvenuto', ref: null, editing: false, query: '', specialita: 'Tutte',
    giorno: null, giornoChiave: null, scegliPrimoTra: null, oltre30: new Set(), sposta: null,
    errori: {}, tentato: {}, busy: false, otp: nuovoOtp(), cellulareInput: '',
    mieTab: 'prossime', alternative: [], altScelta: 0, doppione: null, limite: null, correggi: false,
    installMostrato: false, contatto: { nome: '', cellulare: '', testo: '', privacy: false }, contattoInviato: null,
    annullata: null, menuAperto: null, antibotConferma: false,
  };
}
const state = {
  catalogo: BOOT.catalog,
  draft: nuovaBozza(),
  sessione: { cellulare: null, paziente: null },   // dispositivo che ha verificato il numero
  ui: nuovaUi(),
  cache: { disp: new Map(), primi: new Map(), mie: null, mieInCorso: false },
  demo: { aperto: false, paziente: 'nuovo', errori: null, avvisi: [] },
};
const verificato = () => !!state.sessione.cellulare;
// Il numero è provato dalla verifica con WhatsApp o con il codice SMS; con il codice email è provata l'email.
const cellVerificato = () => verificato() && state.sessione.metodo !== 'email';
const prestazione = id => PRESTAZIONI.find(p => p.id === id);
function invalidaCache() { state.cache.disp.clear(); state.cache.primi.clear(); state.cache.mie = null; }

// --- Configurazione pubblicata (arriva nel catalogo; prima del caricamento vale quella predefinita) ---
const cfg = () => (state.catalogo && state.catalogo.config) || CONFIG_PREDEFINITA;
const passoAttivo = id => cfg().passi.some(p => p.id === id && (p.attivo || id === 'riepilogo'));
// Posizione di un passo nell'ordine della configurazione; un passo spento non ha posizione (Infinity).
const posPasso = id => { const i = cfg().passi.filter(p => p.attivo || p.id === 'riepilogo').findIndex(p => p.id === id); return i < 0 ? Infinity : i; };
// Verifica spenta (passo spento o verifica.richiesta false): prenotazione senza registrazione.
const verificaRichiesta = () => cfg().verifica.richiesta && passoAttivo('verifica');
// Metodi accesi, nell'ordine scelto dallo studio. Valgono per la prenotazione e per "Le mie prenotazioni".
const metodiVerifica = () => cfg().verifica.metodi.filter(m => METODI_VERIFICA.includes(m));
const conMiePrenotazioni = () => metodiVerifica().length > 0;
const testoCfg = (k, predefinito) => { const t = cfg().testi[k]; return t ? esc(t) : predefinito; };
const catPrestazioni = () => (state.catalogo ? state.catalogo.prestazioni : PRESTAZIONI);
const sediStudio = () => (state.catalogo ? state.catalogo.sedi : Object.values(SEDI)).filter(s => !s.online);
const sedeDi = d => d.sedeAssegnata || d.sede;   // la sede dell'orario scelto, o quella scelta prima


/* ==========================================================================
   6. NAVIGAZIONE
   Ogni schermata è uno stato della history: il tasto indietro del browser funziona.
   ========================================================================== */

// L'ordine dei passi NON è fisso: arriva dalla configurazione (config.passi, solo quelli attivi).
// Il passo 'verifica' corrisponde a due schermate: 'telefono' (Verifica che sei tu) e 'codice' (WhatsApp o codice).
const SCHERMATE_FLUSSO = ['prestazione', 'professionista', 'sede', 'orario', 'perchi', 'telefono', 'codice', 'dati', 'riepilogo'];
const FLUSSO = [...SCHERMATE_FLUSSO, 'preso', 'doppione', 'limite', 'nonammesso'];
const SCELTE = ['prestazione', 'professionista', 'sede'];
const passoDellaSchermata = s => ({ telefono: 'verifica', codice: 'verifica', preso: 'riepilogo' }[s] || s);

// Schermate del flusso nell'ordine della configurazione.
function ordine() {
  const out = [];
  for (const p of cfg().passi) {
    if (!p.attivo && p.id !== 'riepilogo') continue;
    if (p.id === 'verifica') { if (verificaRichiesta()) out.push('telefono', 'codice'); } else out.push(p.id);
  }
  return out;
}

let idx = 0;           // posizione nella history della app
const pila = ['benvenuto'];

function vai(screen, opz = {}) {
  if (opz.ref !== undefined) state.ui.ref = opz.ref;
  state.ui.screen = screen;
  state.ui.menuAperto = null;
  if (opz.replace) { pila[idx] = screen; } else { idx++; pila.length = idx; pila.push(screen); }
  const voce = { screen, idx, ref: state.ui.ref };
  try { history[opz.replace ? 'replaceState' : 'pushState'](voce, '', `#${screen}`); } catch (e) { /* file:// in alcuni browser */ }
  render({ nuova: true });
}

function tornaA(screen) {
  const i = pila.lastIndexOf(screen, idx);
  if (i >= 0 && i < idx) history.go(i - idx); else vai(screen, { replace: true });
}

function indietro() { if (idx > 0) history.back(); else vai('benvenuto', { replace: true }); }

window.addEventListener('popstate', e => {
  const st = e.state;
  if (!st || !st.screen) { idx = 0; state.ui.screen = 'benvenuto'; render({ nuova: true }); return; }
  idx = st.idx; pila[idx] = st.screen; state.ui.ref = st.ref; state.ui.screen = st.screen; state.ui.menuAperto = null;
  if (FLUSSO.includes(st.screen) && st.screen !== 'riepilogo') state.ui.editing = false;
  render({ nuova: true });
});

// --- Scelte possibili: prestazione, professionista e sede si filtrano a vicenda, in qualunque ordine ---
// Combinazioni prenotabili (prestazione, professionista, sede) secondo il catalogo e le agende.
let combinazioniCache = { catalogo: undefined, lista: [] };
function combinazioni() {
  if (combinazioniCache.catalogo === state.catalogo) return combinazioniCache.lista;
  const pros = state.catalogo ? state.catalogo.professionisti.map(p => p.id) : Object.keys(PROFESSIONISTI);
  const sedi = state.catalogo ? state.catalogo.sedi.map(s => s.id) : Object.keys(SEDI);
  const lista = [];
  const services = !passoAttivo('prestazione') && !cfg().scelte.prestazione_predefinita
    ? [{ id: null, professionisti: pros, sedi }] : catPrestazioni();
  for (const p of services) for (const pro of p.professionisti) {
    if (!pros.includes(pro) || !AGENDE[pro]) continue;
    const doctor = (state.catalogo?.professionisti || []).find(d => d.id === pro);
    const sites = Object.keys(AGENDE[pro].sedi);
    if (!passoAttivo('sede') && doctor?.senza_sede) sites.push('');
    for (const sede of sites) if (sede === '' || (sedi.includes(sede) && p.sedi.includes(sede))) lista.push({ prestazione:p.id, professionista:pro, sede });
  }
  combinazioniCache = { catalogo: state.catalogo, lista };
  return lista;
}
// Vincoli per le opzioni di un passo: contano solo le scelte dei passi che vengono PRIMA nell'ordine della
// configurazione, più i valori fissati dalla configurazione (prestazione o professionista predefiniti di un passo spento).
function vincoli(per) {
  const d = state.draft; const sc = cfg().scelte; const v = {};
  for (const q of SCELTE) {
    if (q === per) continue;
    const val = d[q]; if (!val || val === 'primo') continue;
    const fisso = q === 'prestazione' ? !passoAttivo(q) : q === 'professionista' ? !passoAttivo(q) && val === sc.professionista_predefinito : false;
    if (fisso || posPasso(q) < posPasso(per)) v[q] = val;
  }
  return v;
}
function opzioni(passo) {
  const v = vincoli(passo);
  return [...new Set(combinazioni().filter(c => Object.keys(v).every(k => c[k] === v[k])).map(c => c[passo]))];
}
const prestazioniPossibili = () => opzioni('prestazione').map(prestazione);
const sediPossibili = () => opzioni('sede');

// Una sola scelta possibile? (per la prestazione sempre; per professionista e sede se lo studio lo vuole)
function unicaScelta(passo) {
  const sc = cfg().scelte;
  if (passo === 'professionista' && !sc.salta_professionista_se_unico) return false;
  if (passo === 'sede' && !sc.salta_sede_se_unica) return false;
  return opzioni(passo).length === 1;
}

// Riempie le scelte che non chiedono nulla al paziente: passi spenti (valori predefiniti o fallback) e passi con
// una sola scelta possibile. Le scelte automatiche restano visibili nel riepilogo, senza "Modifica".
function riempiAutomatici() {
  const d = state.draft; const sc = cfg().scelte;
  if (!passoAttivo('prestazione') && state.catalogo?.modalita !== 'invito') d.prestazione = sc.prestazione_predefinita || null;
  if (!d.prestazione) {
    if (!passoAttivo('prestazione')) d.prestazione = sc.prestazione_predefinita;
    else if (unicaScelta('prestazione')) d.prestazione = opzioni('prestazione')[0];
  }
  if (!d.professionista) {
    if (!passoAttivo('professionista')) {
      // Spento: il professionista predefinito, l'unico possibile, oppure il sistema col primo posto libero.
      if (sc.professionista_predefinito) d.professionista = sc.professionista_predefinito;
      else { const o = opzioni('professionista'); d.professionista = o.length === 1 ? o[0] : 'primo'; }
    } else if (unicaScelta('professionista')) d.professionista = opzioni('professionista')[0];
  }
  if (!d.sede) {
    if (!passoAttivo('sede')) {
      // Spenta: l'unica sede possibile, altrimenti quella del primo posto libero (decide l'orario scelto).
      if (d.professionista) { const o = opzioni('sede'); if (o.length === 1) d.sede = o[0]; }
    } else if (unicaScelta('sede')) d.sede = opzioni('sede')[0];
  }
}

// Il passo si mostra? (spento o con una sola scelta: no; cellulare e codice solo se il dispositivo non è verificato)
function serve(passo) {
  if (SCELTE.includes(passo)) return passoAttivo(passo) && !unicaScelta(passo);
  if (passo === 'telefono' || passo === 'codice') return verificaRichiesta() && !verificato();
  if (passo === 'perchi') return passoAttivo('perchi');
  return true;
}
function completo(passo) {
  const d = state.draft;
  switch (passo) {
    case 'prestazione': return !!d.prestazione;
    case 'professionista': return !!d.professionista;
    case 'sede': return !!d.sede;
    case 'orario': return !!(d.data && d.ora);
    case 'perchi': return Object.keys(erroriPerChi()).length === 0;
    case 'telefono': case 'codice': return verificato();
    case 'dati': return datiCompleti();
    default: return false;
  }
}
// Primo passo da fare (es. la prestazione scelta dalla home quando la sede viene per prima).
function primoDaFare(fino = null) {
  for (const passo of ordine()) {
    if (passo === fino) return null;
    if (passo === 'codice' || passo === 'riepilogo') continue;
    if (serve(passo) && !completo(passo)) return passo;
  }
  return null;
}
// Passo successivo nell'ordine della configurazione. Le scelte già fatte si saltano;
// tornando dal riepilogo con "Modifica" si saltano tutti i passi già completi.
function avanti(da, opz = {}) {
  riempiAutomatici();
  const ord = ordine();
  const i = ord.indexOf(da);
  const prima = primoDaFare(i >= 0 ? da : ord[0]);
  if (prima) return vai(prima, opz);
  for (const passo of ord.slice(i + 1)) {
    if (passo === 'riepilogo') break;
    if (passo === 'codice' || !serve(passo)) continue;
    if (completo(passo) && (state.ui.editing || SCELTE.includes(passo))) continue;
    return vai(passo, opz);
  }
  state.ui.editing = false;
  vai('riepilogo', opz);
}
// Il paziente cambia una scelta: le scelte dei passi che vengono dopo (e quelle automatiche) ripartono da capo.
function scegli(passo, id) {
  const d = state.draft;
  if (d[passo] !== id) {
    rilasciaRiservaCorrente();
    for (const q of SCELTE) if (q !== passo && (posPasso(q) > posPasso(passo) || !passoAttivo(q) || unicaScelta(q))) d[q] = null;
    Object.assign(d, { [passo]: id, data: null, ora: null, proAssegnato: null, sedeAssegnata: null });
  }
  avanti(passo);
}

// Guardie: se manca un dato si torna al passo giusto (es. aprendo un indirizzo a metà).
function guardia(s) {
  const d = state.draft;
  if (d.completata && FLUSSO.includes(s)) { state.draft = nuovaBozza(d); return 'benvenuto'; }
  if (!state.catalogo) return null;
  if (!state.catalogo.prenotazioni_attive && FLUSSO.includes(s)) return 'benvenuto';
  if (SCHERMATE_FLUSSO.includes(s)) {
    riempiAutomatici();
    const ord = ordine();
    if (!ord.includes(s)) return primoDaFare() || 'riepilogo';               // passo spento dalla configurazione
    if (s === 'codice') return state.ui.otp.inviato && state.ui.otp.contesto === 'prenota' ? null : 'telefono';
    const manca = primoDaFare(s);                                             // un passo precedente non è completo
    if (manca) return manca;
    if (s === 'orario' && ((!d.prestazione && (passoAttivo('prestazione') || cfg().scelte.prestazione_predefinita)) || !d.professionista)) return 'benvenuto';
    return null;
  }
  switch (s) {
    case 'esito':
      if (d.esito && d.esito.stato === 'annullata') { state.draft = nuovaBozza(d); return 'benvenuto'; }
      return d.esito ? null : 'benvenuto';
    case 'preso': return state.ui.alternative.length ? null : 'orario';
    case 'mie': case 'dettaglio': case 'sposta': case 'sposta-conferma': case 'annullata':
      return null; // Server authorizes management against the authenticated operator.
    case 'mie-codice': return state.ui.otp.inviato && state.ui.otp.contesto === 'mie' ? null : 'mie-accesso';
    case 'mie-accesso': return verificato() ? 'mie' : conMiePrenotazioni() ? null : 'benvenuto';
    case 'contatto-ok': return state.ui.contattoInviato ? null : 'contatto';
    default: return null;
  }
}

// Barra di avanzamento: conta solo i passi mostrati davvero. Passi vicini dello stesso gruppo diventano un
// segmento solo (professionista e sede; per chi, verifica e dati sono "I tuoi dati").
const ETICHETTE_PASSI = { prestazione: 'Prestazione', professionista: 'Professionista', sede: 'Sede', orario: 'Orario', perchi: 'Per chi', verifica: 'I tuoi dati', dati: 'I tuoi dati', riepilogo: 'Conferma' };
function segmenti() {
  const segs = [];
  for (const p of cfg().passi) {
    if (!p.attivo && p.id !== 'riepilogo') continue;
    const mostrato = p.id === 'verifica' ? verificaRichiesta() : SCELTE.includes(p.id) ? serve(p.id) : true;
    if (!mostrato) continue;
    const label = ETICHETTE_PASSI[p.id]; const prec = segs[segs.length - 1];
    const unisci = prec && (prec.label === label || (prec.label === 'Professionista' && label === 'Sede')
      || (prec.label === 'Per chi' && label === 'I tuoi dati') || (prec.label === 'I tuoi dati' && label === 'Per chi'));
    if (unisci) { prec.passi.push(p.id); if (prec.label === 'Per chi') prec.label = label; } else segs.push({ label, passi: [p.id] });
  }
  return segs;
}
// Segmento della schermata corrente (1 = primo). Se il passo non è contato, vale il segmento successivo.
function segmentoCorrente(segs, s) {
  const passo = passoDellaSchermata(s);
  const i = segs.findIndex(g => g.passi.includes(passo));
  if (i >= 0) return i + 1;
  const pos = posPasso(passo);
  const dopo = segs.findIndex(g => g.passi.some(id => posPasso(id) > pos));
  return dopo >= 0 ? dopo + 1 : segs.length;
}


/* ==========================================================================
   7. COMPONENTI
   ========================================================================== */

const ICONE = {
  'arrow-left': '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
  'arrow-down': '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
  'chev-left': '<path d="m15 18-6-6 6-6"/>',
  'chev-right': '<path d="m9 18 6-6-6-6"/>',
  'chev-down': '<path d="m6 9 6 6 6-6"/>',
  search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  calendar: '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
  'calendar-check': '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/>',
  'calendar-plus': '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="M12 14v5"/><path d="M9.5 16.5h5"/>',
  'calendar-x': '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="m14 14-4 4"/><path d="m10 14 4 4"/>',
  clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  pin: '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
  user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
  chat: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
  mail: '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
  info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
  alert: '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
  warning: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
  shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
  zap: '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
  hourglass: '<path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/>',
  share: '<path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="m16 6-4-4-4 4"/><path d="M12 2v13"/>',
  'plus-square': '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M8 12h8"/><path d="M12 8v8"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
  ext: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
  video: '<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
  more: '<circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>',
  move: '<path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/>',
  'x-circle': '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
  building: '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
  steth: '<path d="M11 2v2"/><path d="M5 2v2"/><path d="M5 3H4a2 2 0 0 0-2 2v4a6 6 0 0 0 12 0V5a2 2 0 0 0-2-2h-1"/><path d="M8 15a6 6 0 0 0 12 0v-3"/><circle cx="20" cy="10" r="2"/>',
  nav: '<path d="M3 11 22 2l-9 19-2-8-8-2z"/>',
  wifioff: '<path d="M12 20h.01"/><path d="M8.5 16.429a5 5 0 0 1 7 0"/><path d="M5 12.859a10 10 0 0 1 5.17-2.69"/><path d="M19 12.859a10 10 0 0 0-2.007-1.523"/><path d="M2 8.82a15 15 0 0 1 4.177-2.643"/><path d="M22 8.82a15 15 0 0 0-11.288-3.764"/><path d="m2 2 20 20"/>',
  euro: '<path d="M4 10h12"/><path d="M4 14h9"/><path d="M19 6a7.7 7.7 0 0 0-5.2-2A7.9 7.9 0 0 0 6 12c0 4.4 3.5 8 7.8 8 2 0 3.8-.8 5.2-2"/>',
  smartphone: '<rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/>',
  pause: '<circle cx="12" cy="12" r="10"/><path d="M10 15V9"/><path d="M14 15V9"/>',
};
const icon = (nome, cls = '') => `<svg class="ico ${cls}" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${ICONE[nome] || ''}</svg>`;
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const norm = s => String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

const nomePro = p => `${p.titolo} ${p.nome} ${p.cognome}`;
const nomeProBreve = p => `${p.titolo} ${p.cognome}`;
const conArticolo = p => `${p.titolo === 'Dott.ssa' ? 'la' : 'il'} ${nomePro(p)}`;
const iniziali = (n, c) => `${(n || '?')[0]}${(c || '')[0] || ''}`.toUpperCase();
const indirizzo = s => !s ? '' : (s.online ? s.indirizzo : `${s.indirizzo}, ${s.cap} ${s.citta} (${s.prov})`);
const linkMaps = s => !s ? '#' : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${s.indirizzo}, ${s.cap} ${s.citta} ${s.prov}`)}`;
const formattaCell = c => (c.length === 10 ? `${c.slice(0, 3)} ${c.slice(3, 6)} ${c.slice(6)}` : c);
function normalizzaCell(v) {
  let c = String(v || '').replace(/[^\d+]/g, '');
  if (c.startsWith('+39')) c = c.slice(3); else if (c.startsWith('0039')) c = c.slice(4);
  return c.replace(/\D/g, '');
}

function titolo(testo, sotto) {
  return `<h1 class="title" id="titolo" tabindex="-1">${testo}</h1>${sotto ? `<p class="subtitle">${sotto}</p>` : ''}`;
}
const badge = (testo, tipo = 'ok', ic = null) => `<span class="badge badge-${tipo}">${ic ? icon(ic) : ''}${testo}</span>`;
const nota = (tipo, ic, html) => `<div class="note note-${tipo}">${icon(ic)}<div>${html}</div></div>`;
const demoHint = () => '';

function badgeStato(stato) {
  switch (stato) {
    case 'confermata': return badge('Confermata', 'ok', 'check');
    case 'in_attesa': return badge('In attesa di conferma', 'warn', 'hourglass');
    case 'annullata': return badge('Annullata', 'err', 'x');
    case 'rifiutata': return badge('Non accolta', 'err', 'x');
    case 'svolta': return badge('Svolta', 'neutral', 'check');
    default: return '';
  }
}

// Campo di testo con label vera, aiuto, errore collegato (aria-describedby).
// richiesto: per i campi né obbligatori né facoltativi da soli (es. "almeno uno tra cellulare ed email").
function campo({ id, label, valore = '', tipo = 'text', autocomplete, inputmode, aiuto, errore, facoltativo, richiesto, input, placeholder, extra = '' }) {
  const desc = [aiuto ? `${id}-aiuto` : '', errore ? `${id}-err` : ''].filter(Boolean).join(' ');
  const obbligatorio = richiesto === undefined ? !facoltativo : richiesto;
  return `<div class="field ${errore ? 'has-error' : ''}">
    <label class="label" for="${id}">${label}${facoltativo ? ' <span class="opt-txt">(facoltativo)</span>' : ''}</label>
    ${aiuto ? `<p class="hint" id="${id}-aiuto">${aiuto}</p>` : ''}
    <input class="input" id="${id}" type="${tipo}" value="${esc(valore)}" data-input="${input}" data-valida="${input}"
      ${autocomplete ? `autocomplete="${autocomplete}"` : ''} ${inputmode ? `inputmode="${inputmode}"` : ''}
      ${placeholder ? `placeholder="${esc(placeholder)}"` : ''} ${obbligatorio ? 'aria-required="true"' : ''}
      ${errore ? 'aria-invalid="true"' : ''} ${desc ? `aria-describedby="${desc}"` : ''} ${extra}>
    ${errore ? `<p class="error" id="${id}-err">${icon('alert')}<span>${errore}</span></p>` : ''}
  </div>`;
}

function casella({ id, label, checked, errore, change }) {
  return `<div class="${errore ? 'has-error' : ''}">
    <label class="check" for="${id}"><input type="checkbox" id="${id}" data-change="${change}" ${checked ? 'checked' : ''} ${errore ? `aria-invalid="true" aria-describedby="${id}-err"` : ''}><span>${label}</span></label>
    ${errore ? `<p class="error" id="${id}-err">${icon('alert')}<span>${errore}</span></p>` : ''}
  </div>`;
}

function scheletro(n = 3) { return `<div class="stack" aria-hidden="true">${'<span class="sk sk-card"></span>'.repeat(n)}</div>`; }

function rassicurazioni(cls = 'reassure') {
  return `<ul class="${cls}">
    <li>${icon('check')}<span>Non serve creare un account</span></li>
    <li>${icon('check')}<span>Puoi spostare o annullare fino a ${cfg().regole.preavviso_modifica_ore} ore prima</span></li>
    <li>${icon('check')}<span>Paghi in studio</span></li>
  </ul>`;
}

// La riserva vale solo per l'orario per cui è stata presa (se l'orario cambia, va ripresa).
function riservaValida() {
  const r = state.draft.riserva; const d = state.draft;
  return !!(r && !r.scaduta && r.data === d.data && r.ora === d.ora && r.pro === d.proAssegnato && r.sede === sedeDi(d));
}
// Banner della riserva: compare SOLO dopo la verifica del codice (senza verifica non c'è riserva).
function bannerRiserva(compatto = false) {
  if (!verificaRichiesta()) return '';
  const r = state.draft.riserva; const d = state.draft;
  const serve = verificato() && d.data && d.ora && ['dati', 'riepilogo'].includes(state.ui.screen);
  if (!r && !serve) return '';
  if (!riservaValida()) {
    if (!serve && !r) return '';
    return `<div class="hold expired ${compatto ? 'compact' : ''}">${icon('warning')}<span>${r && r.scaduta ? 'Il tempo per confermare è scaduto.' : 'Questo orario non è ancora tenuto per te.'}</span>
      <button type="button" class="btn-link" data-action="riprova-riserva">Riprova con lo stesso orario</button></div>`;
  }
  return `<div class="hold ${compatto ? 'compact' : ''}">${icon('clock')}<span>Teniamo questo orario per te per <strong data-hold-time>${mmss(r.scade - Date.now())}</strong></span></div>`;
}

function cardPrestazione(p) {
  const sel = state.draft.prestazione === p.id;
  return `<button type="button" class="opt ${sel ? 'is-selected' : ''}" id="pres-${p.id}" data-action="prestazione" data-id="${p.id}" ${sel ? 'aria-current="true"' : ''}>
    <span class="opt-body">
      <span class="opt-title">${esc(p.nome)}</span>
      <span class="opt-meta">
        <span>${icon('clock')}${p.durata} min</span>
        <span>${p.prezzo == null ? '' : p.prezzo + ' €'}</span>
        ${p.video ? `<span>${icon('video')}Videochiamata</span>` : ''}
      </span>
      ${p.etichetta ? badge(p.etichetta, 'info') : ''}
    </span>
    <span class="opt-end">${sel ? icon('check') : icon('chev-right')}</span>
    ${sel ? '<span class="sr-only">(scelta attuale)</span>' : ''}
  </button>`;
}

// Prestazioni prenotabili con le scelte già fatte (es. professionista o sede scelti prima), filtrate da ricerca e specialità.
function specialitaDi(lista) { return SPECIALITA.filter(s => lista.some(p => p.specialita === s)); }
function filtraPrestazioni() {
  const q = norm(state.ui.query.trim()); const base = prestazioniPossibili();
  const spec = specialitaDi(base).includes(state.ui.specialita) ? state.ui.specialita : 'Tutte';
  return base.filter(p => (spec === 'Tutte' || p.specialita === spec)
    && (!q || norm(`${p.nome} ${p.specialita} ${p.parole}`).includes(q)));
}

// Riga sintetica per la barra in basso su mobile: "gio 12 nov, 17:30 · Dott.ssa Conti · Empoli".
// Anche le scelte automatiche compaiono qui, così il paziente sa con chi e dove.
function rigaRiepilogo() {
  const d = state.draft; const parti = [];
  if (d.data && d.ora) parti.push(`${dataBreve(d.data)}, ${d.ora}`);
  else if (d.prestazione) parti.push(prestazione(d.prestazione).nome);
  const pro = PROFESSIONISTI[d.proAssegnato || d.professionista];
  if (pro) parti.push(nomeProBreve(pro));
  else if (d.professionista === 'primo') parti.push('Il primo disponibile');
  const sede = SEDI[sedeDi(d)];
  if (sede) parti.push(sede?.online ? 'Videochiamata' : sede.nome);
  return parti.join(' · ');
}

function campoRicerca(id, etichettaVisibile) {
  const q = state.ui.query;
  return `<div class="search">
    <label for="${id}" class="${etichettaVisibile ? 'label-lg' : 'sr-only'}">${etichettaVisibile ? 'Cosa ti serve?' : 'Cerca una prestazione'}</label>
    <div class="search-box">${icon('search')}
      <input id="${id}" class="input input-search" type="search" value="${esc(q)}" data-input="cerca" autocomplete="off" enterkeyhint="search"
        placeholder="Ad esempio nei, ECG, fisioterapia" aria-describedby="${id}-esito">
      <button type="button" class="icon-btn clear" data-action="pulisci-ricerca" aria-label="Cancella la ricerca" ${q ? '' : 'hidden'}>${icon('x')}</button>
    </div>
    <p class="sr-only" id="${id}-esito" aria-live="polite"></p>
  </div>`;
}


/* ==========================================================================
   8. SCHERMATE
   Ogni funzione restituisce { html, cta, laterale }. cta: { label, azione, pronto, avviso }.
   ========================================================================== */

// ----- S01 Benvenuto -----
function sBenvenuto() {
  const cat = state.catalogo;
  if (!cat) return { html: `<span class="sk sk-line" style="width:40%"></span><span class="sk" style="height:34px;width:70%;margin:12px 0 20px"></span>${scheletro(4)}`, laterale: 'studio' };
  if (!cat.prenotazioni_attive) return { html: sSospese(), laterale: 'studio' };
  if (cat.modalita === 'invito') return { html: sInvito(cat.invito), laterale: 'studio' };
  const notaArchivio = cat.modalita === 'archivio'
    ? `<div class="mt8" style="margin-bottom:20px">${nota('info', 'info', `<p>Le prenotazioni online sono per chi è già paziente dello studio. Per la prima visita chiama lo <a href="${esc(STUDIO.telHref)}">${esc(STUDIO.telefono)}</a>.</p>`)}</div>` : '';
  // La home parte dal primo passo della configurazione: con la prestazione per prima c'è la ricerca,
  // altrimenti un pulsante che apre il primo passo (professionista, sede...).
  riempiAutomatici();
  const primo = ordine().find(p => p !== 'codice' && serve(p));
  const sotto = verificaRichiesta()
    ? 'Scegli la prestazione e l\'orario che preferisci. Confermi con una verifica veloce, senza creare un account.'
    : passoAttivo('prestazione') ? 'Scegli la prestazione e l\'orario che preferisci. Non serve creare un account.' : 'Scegli il giorno e l\'orario che preferisci. Non serve creare un account.';
  const INIZIO = { professionista: 'Scegli il professionista', sede: 'Scegli la sede', orario: 'Scegli il giorno e l\'orario', perchi: 'Inizia la prenotazione', telefono: 'Inizia la prenotazione', dati: 'Inizia la prenotazione' };
  const scelta = primo === 'prestazione'
    ? `${campoRicerca('cerca-home', true)}
      <section class="section" aria-labelledby="risultati-t"><div id="risultati">${risultatiHome()}</div></section>
      <button type="button" class="btn btn-secondary btn-block mt16" data-action="tutte">Vedi tutte le prestazioni</button>`
    : `<button type="button" class="btn btn-primary btn-lg btn-block" data-action="inizia">${INIZIO[primo] || 'Inizia la prenotazione'}</button>`;
  return {
    laterale: 'studio',
    html: `<p class="eyebrow">Prenotazione online</p>
      ${titolo(testoCfg('benvenuto_titolo', 'Prenota una visita'), testoCfg('benvenuto_sottotitolo', sotto))}
      ${notaArchivio}
      ${scelta}
      <ul class="trust">
        <li><span class="icon-tile">${icon('user')}</span>Non serve creare un account</li>
        <li><span class="icon-tile">${icon('calendar')}</span>Sposti o annulli fino a ${cfg().regole.preavviso_modifica_ore} ore prima</li>
        <li><span class="icon-tile">${icon('euro')}</span>Paghi direttamente in studio</li>
      </ul>
      ${cardHaiGia('Spostala, annullala o aggiungila al calendario.')}
      <p class="center mt20"><a href="#contatto" class="btn-link" data-action="contatto">Non sai cosa prenotare? Chiedi allo studio</a></p>`,
  };
}
// "Hai già una prenotazione?": con almeno un metodo di verifica acceso porta a Le mie prenotazioni;
// senza metodi (prenotazione senza registrazione) si sposta e si annulla dal link nei messaggi o per telefono.
function cardHaiGia(sotto) {
  if (!conMiePrenotazioni()) {
    return `<p class="micro on-bg mt24">Hai già una prenotazione? Per spostarla o annullarla usa il link che ti abbiamo mandato, oppure chiama lo <a href="${esc(STUDIO.telHref)}">${esc(STUDIO.telefono)}</a>.</p>`;
  }
  return `<a href="#mie" class="card link-card mt24" data-action="apri-mie">
      <span class="icon-tile neutral">${icon('calendar-check')}</span>
      <span class="opt-body"><span class="opt-title">Hai già una prenotazione?</span><span class="opt-sub">${sotto}</span></span>
      <span class="opt-end">${icon('chev-right')}</span>
    </a>`;
}
function risultatiHome() {
  const q = state.ui.query.trim();
  if (!q) {
    const lista = prestazioniPossibili(); const evidenza = lista.filter(p => p.evidenza);
    return `<h2 class="group-title" id="risultati-t">${evidenza.length ? 'Le più richieste' : 'Le prestazioni'}</h2>
      <div class="stack">${(evidenza.length ? evidenza : lista).map(cardPrestazione).join('')}</div>`;
  }
  const lista = filtraPrestazioni();
  return `<h2 class="group-title" id="risultati-t">${lista.length ? `${lista.length === 1 ? '1 prestazione trovata' : `${lista.length} prestazioni trovate`}` : 'Nessuna prestazione trovata'}</h2>
    ${lista.length ? `<div class="stack">${lista.map(cardPrestazione).join('')}</div>` : vuotoRicerca()}`;
}
function vuotoRicerca() {
  return `<div class="card center"><p class="h3">Non troviamo “${esc(state.ui.query.trim())}”</p>
    <p class="micro mt8">Prova con un'altra parola, oppure chiedi allo studio: ti consigliano la prestazione giusta.</p>
    <button type="button" class="btn btn-secondary mt16" data-action="contatto">Chiedi allo studio</button></div>`;
}

function contattiStudio() {
  return `<div class="btn-row mt24">
    <a class="btn btn-primary btn-lg btn-block" href="${esc(STUDIO.telHref)}">${icon('phone')}Chiama lo ${esc(STUDIO.telefono)}</a>
    <a class="btn btn-secondary btn-block" href="${esc(STUDIO.waHref)}" target="_blank" rel="noopener">${icon('chat')}Scrivi su WhatsApp</a>
    <a class="btn btn-secondary btn-block" href="mailto:${esc(STUDIO.email)}">${icon('mail')}Scrivi un'email</a>
  </div>`;
}

// ----- S18 Prenotazioni sospese -----
function sSospese() {
  return `<div class="mark neutral">${icon('pause')}</div>
    ${titolo('Le prenotazioni online sono sospese', 'In questo momento lo studio non accetta prenotazioni online. Puoi prenotare per telefono, su WhatsApp o via email.')}
    ${contattiStudio()}
    ${cardHaiGia('Puoi ancora vederla, spostarla o annullarla.')}`;
}

// ----- Modalità d: link d'invito personale -----
function sInvito(inv) {
  const pres = prestazione(inv.prestazione); const pro = PROFESSIONISTI[inv.professionista]; const sede = SEDI[inv.sede];
  return `<p class="eyebrow">Invito personale</p>
    ${titolo(`Ciao${inv.nome ? ' '+esc(inv.nome) : ''}, lo studio ti invita a prenotare`, `Scegli tu l'orario. Il link è personale e vale per una sola prenotazione, fino al ${giornoMese(inv.valido_fino)}.`)}
    <div class="card recap">
      ${pres ? `<div class="recap-row">${icon('steth')}<div class="recap-main"><p class="recap-label">Prestazione</p><p class="recap-val">${esc(pres?.nome || '')}</p><p class="recap-sub">${pres?.durata} minuti${pres?.prezzo == null ? '' : ' · ' + pres?.prezzo + ' €, paghi in studio'}</p></div></div>` : ''}
      <div class="recap-row">${icon('user')}<div class="recap-main"><p class="recap-label">Professionista</p><p class="recap-val">${nomePro(pro)}</p></div></div>
      ${sede ? `<div class="recap-row">${icon('pin')}<div class="recap-main"><p class="recap-label">Sede</p><p class="recap-val">${esc(sede?.nome || '')}</p><p class="recap-sub">${esc(indirizzo(sede))}</p></div></div>` : ''}
    </div>
    <button type="button" class="btn btn-primary btn-lg btn-block mt20" data-action="usa-invito">Scegli l'orario</button>
    <p class="micro on-bg center mt16">Per altre visite chiama lo <a href="${esc(STUDIO.telHref)}">${esc(STUDIO.telefono)}</a>.</p>`;
}

// ----- S02 Prestazione -----
function sPrestazione() {
  const spec = specialitaDi(prestazioniPossibili());
  const attiva = spec.includes(state.ui.specialita) ? state.ui.specialita : 'Tutte';
  const chip = s => `<button type="button" class="chip" id="spec-${norm(s)}" data-action="specialita" data-val="${s}" aria-pressed="${attiva === s}">${s}</button>`;
  // Con professionista o sede scelti prima, l'elenco mostra solo le prestazioni possibili.
  const v = vincoli('prestazione'); const pro = PROFESSIONISTI[v.professionista]; const sede = SEDI[v.sede];
  const filtro = pro ? ` Qui trovi quelle con ${conArticolo(pro)}${sede ? (sede?.online ? ', in videochiamata' : `, a ${esc(sede?.nome || '')}`) : ''}.`
    : sede ? (sede?.online ? ' Qui trovi quelle in videochiamata.' : ` Qui trovi quelle della sede di ${esc(sede?.nome || '')}.`) : '';
  return {
    html: `${titolo('Cosa ti serve?', `Scegli la prestazione. Durata e prezzo sono indicativi e paghi in studio.${filtro}`)}
      ${campoRicerca('cerca-elenco', false)}
      ${spec.length > 1 ? `<div class="chips-scroll mt12" role="group" aria-label="Filtra per specialità">${['Tutte', ...spec].map(chip).join('')}</div>` : '<div class="mt16"></div>'}
      <div id="elenco">${elencoPrestazioni()}</div>
      <p class="center mt24"><a href="#contatto" class="btn-link" data-action="contatto">Non sai cosa prenotare? Chiedi allo studio</a></p>`,
  };
}
function elencoPrestazioni() {
  const lista = filtraPrestazioni();
  if (!lista.length) return vuotoRicerca();
  if (specialitaDi(prestazioniPossibili()).includes(state.ui.specialita) || state.ui.query.trim()) return `<div class="stack">${lista.map(cardPrestazione).join('')}</div>`;
  return SPECIALITA.map(s => {
    const gruppo = lista.filter(p => p.specialita === s);
    return gruppo.length ? `<h2 class="group-title">${s}</h2><div class="stack">${gruppo.map(cardPrestazione).join('')}</div>` : '';
  }).join('');
}

// ----- S03 Professionista -----
function primoPer(pres, pro, sede) {
  const k = `${pres}|${pro}|${sede || ''}`;
  if (!state.cache.primi.has(k)) {
    state.cache.primi.set(k, undefined);
    api.disponibilita({ prestazione: pres, professionista: pro, sede, prima_disponibilita: 1 })
      .then(r => { state.cache.primi.set(k, r.primo); ridisegnaSe(['professionista', 'sede']); })
      .catch(() => { state.cache.primi.set(k, { errore:true }); ridisegnaSe(['professionista', 'sede']); });
  }
  return state.cache.primi.get(k);
}
function badgePrimo(primo, etichetta = 'Primo posto libero') {
  if (primo === undefined) return '<span class="sk sk-pill" aria-hidden="true"></span>';
  if (primo?.errore) return badge('Disponibilità non leggibile. Riprova.', 'warn', 'warning');
  if (primo === null) return badge('Nessun posto nei prossimi '+cfg().regole.giorni_prenotabili+' giorni', 'warn', 'calendar-x');
  if (diffGiorni(OGGI_ISO, primo.data) > 30) return badge(`${etichetta}: ${dataMedia(primo.data)}`, 'warn', 'calendar');
  return badge(`${etichetta}: ${dataMedia(primo.data)}`, 'ok', 'calendar');
}
// Il professionista può venire anche prima della prestazione: senza prestazione niente badge del primo posto.
function sProfessionista() {
  const d = state.draft; const v = vincoli('professionista');
  const pres = prestazione(v.prestazione); const sedeV = SEDI[v.sede];
  const pros = opzioni('professionista').map(id => PROFESSIONISTI[id]);
  const sel = id => d.professionista === id;
  // "Il primo disponibile" si può togliere (scelte.primo_disponibile): il paziente sceglie una persona.
  const cardPrimo = cfg().scelte.primo_disponibile ? `<button type="button" class="opt ${sel('primo') ? 'is-selected' : ''}" id="pro-primo" data-action="professionista" data-id="primo">
      <span class="icon-tile">${icon('zap')}</span>
      <span class="opt-body"><span class="opt-title">Il primo disponibile</span>
        <span class="opt-sub">Ti proponiamo l'orario libero più vicino.</span>${badgePrimo(primoPer(pres?.id || null, 'primo', v.sede))}</span>
      <span class="opt-end">${sel('primo') ? icon('check') : icon('chev-right')}</span></button>` : '';
  const cards = pros.map(p => {
    const sediPro = [...new Set(combinazioni().filter(c => c.professionista === p.id && (!pres || c.prestazione === pres.id) && (!v.sede || c.sede === v.sede)).map(c => c.sede))];
    const online = sediPro.length > 0 && sediPro.every(s => SEDI[s]?.online);
    const sedi = sediPro.map(s => (SEDI[s]?.nome || '')).join(', ');
    return `<button type="button" class="opt ${sel(p.id) ? 'is-selected' : ''}" id="pro-${p.id}" data-action="professionista" data-id="${p.id}">
      <span class="avatar" aria-hidden="true">${iniziali(p.nome, p.cognome)}</span>
      <span class="opt-body"><span class="opt-title">${nomePro(p)}</span><span class="opt-sub">${p.qualifica}</span>
        <span class="opt-meta"><span>${icon(online ? 'video' : 'pin')}${sedi}</span></span>${badgePrimo(primoPer(pres?.id || null, p.id, v.sede))}</span>
      <span class="opt-end">${sel(p.id) ? icon('check') : icon('chev-right')}</span></button>`;
  }).join('');
  const sotto = pres ? `${esc(pres?.nome || '')}, ${pres?.durata} minuti${sedeV && !sedeV.online ? `, sede di ${sedeV.nome}` : ''}`
    : sedeV ? (sedeV.online ? 'I professionisti che ricevono in videochiamata.' : `I professionisti della sede di ${sedeV.nome}.`) : 'Scegli il professionista. Dopo vedi le sue prestazioni.';
  return { html: `${titolo('Con chi?', sotto)}<div class="stack">${cardPrimo}${cards}</div>` };
}

// ----- S04 Sede (solo se più di una, oppure se lo studio non la fa saltare) -----
// Può venire anche per prima: allora professionisti e prestazioni si filtrano per sede.
function sSede() {
  const d = state.draft; const v = vincoli('sede');
  const pres = prestazione(v.prestazione); const pro = PROFESSIONISTI[v.professionista];
  const ids = sediPossibili(); const n = ids.length;
  const sotto = pro ? `${maiuscola(conArticolo(pro))} riceve in ${n === 2 ? 'due' : n} sedi. Scegli quella più comoda per te.` : 'Scegli la sede più comoda per te.';
  const cards = ids.map(id => {
    const s = SEDI[id]; const sel = d.sede === id;
    return `<div class="opt-wrap ${sel ? 'is-selected' : ''}">
      <button type="button" class="opt" id="sede-${id}" data-action="sede" data-id="${id}">
        <span class="icon-tile neutral">${icon(s.online ? 'video' : 'building')}</span>
        <span class="opt-body"><span class="opt-title">${esc(s.nome)}</span><span class="opt-sub">${esc(indirizzo(s))}</span>${badgePrimo(primoPer(pres?.id || null, v.professionista || 'primo', id))}</span>
        <span class="opt-end">${sel ? icon('check') : icon('chev-right')}</span></button>
      ${s.online ? '' : `<div class="opt-foot"><a href="${linkMaps(s)}" target="_blank" rel="noopener">${icon('nav', 'ico-sm')}Apri in Maps<span class="sr-only"> la sede di ${esc(s.nome)} (nuova scheda)</span></a></div>`}
    </div>`;
  }).join('');
  return { html: `${titolo('In quale sede?', sotto)}<div class="stack">${cards}</div>` };
}

// ----- S05 Data e orario (riusata da S13 Sposta) -----
const inizioPagina = k => piuGiorni(OGGI_ISO, 14 * k);
const paginaDi = iso => Math.floor(diffGiorni(OGGI_ISO, iso) / 14);
const ULTIMO_GIORNO = () => piuGiorni(OGGI_ISO, (state.catalogo && state.catalogo.regole.finestra_giorni) || 90);
const MAX_PAGINA = () => paginaDi(ULTIMO_GIORNO());

// Sedi tra cui cercare gli orari quando la sede non è stata scelta.
function sediDiOrario(p) {
  if (p.sede) return [p.sede];
  return [...new Set(combinazioni().filter(c => c.prestazione === p.prestazione && (p.professionista === 'primo' || c.professionista === p.professionista)).map(c => c.sede))];
}
function parametriOrario() {
  if (state.ui.screen === 'sposta') {
    const p = prenotazioneCorrente();
    return p ? { modo: 'sposta', prestazione: p.prestazione, professionista: p.professionista, sede: p.sede, durata:p.durata, prenotazione:p.ref, attuale: p } : null;
  }
  const d = state.draft;
  return { modo: 'prenota', prestazione: d.prestazione, professionista: d.professionista, sede: d.sede };
}
const chiaveDisp = p => `${p.prestazione}|${p.professionista}|${p.sede}`;
function dispPer(p) {
  const k = chiaveDisp(p);
  if (!state.cache.disp.has(k)) state.cache.disp.set(k, { giorni: {}, pagine: new Set(), inCorso: new Set(), primo: undefined, primoInCorso: false, prossimo: {} });
  return state.cache.disp.get(k);
}
// Carica a blocchi di 14 giorni (limite dell'endpoint).
function caricaPagine(p, pagine) {
  const disp = dispPer(p);
  const mancanti = [...new Set(pagine)].filter(k => k >= 0 && k <= MAX_PAGINA() && !disp.pagine.has(k) && !disp.inCorso.has(k));
  if (!mancanti.length) return;
  mancanti.forEach(k => disp.inCorso.add(k));
  Promise.all(mancanti.map(k => api.disponibilita({ prestazione: p.prestazione, professionista: p.professionista, sede: p.sede, prenotazione:p.prenotazione, dal: inizioPagina(k), al: piuGiorni(inizioPagina(k), 13) })
    .then(r => { Object.assign(disp.giorni, r.giorni); disp.pagine.add(k); })
    .finally(() => disp.inCorso.delete(k))))
    .then(() => ridisegnaSe(['orario', 'sposta']))
    .catch(erroreRete);
}
function caricaPrimo(p) {
  const disp = dispPer(p); if (disp.primoInCorso) return;
  disp.primoInCorso = true;
  api.disponibilita({ prestazione: p.prestazione, professionista: p.professionista, sede: p.sede, prenotazione:p.prenotazione, prima_disponibilita: 1 })
    .then(r => { disp.primo = r.primo; ridisegnaSe(['orario', 'sposta']); })
    .catch(e => { disp.primoInCorso = false; erroreRete(e); });
}
function caricaProssimo(p, iso) {
  const disp = dispPer(p); if (iso in disp.prossimo) return;
  disp.prossimo[iso] = undefined;
  api.disponibilita({ prestazione: p.prestazione, professionista: p.professionista, sede: p.sede, prenotazione:p.prenotazione, prima_disponibilita: 1, dal: piuGiorni(iso, 1) })
    .then(r => { disp.prossimo[iso] = r.primo; ridisegnaSe(['orario', 'sposta']); })
    .catch(e => { delete disp.prossimo[iso]; erroreRete(e); });
}
const statoGiorno = (disp, iso) => disp.giorni[iso];
const giornoSelezionabile = (g, iso) => iso >= OGGI_ISO && iso <= ULTIMO_GIORNO() && !(g && (g.stato === 'chiuso' || g.stato === 'fuori'));
function primoLiberoTra(disp, da, a) {
  for (let iso = da; iso <= a; iso = piuGiorni(iso, 1)) { const g = disp.giorni[iso]; if (g && g.orari.length) return iso; }
  return null;
}
function etichettaGiorno(iso, g) {
  const base = dataLunga(iso);
  if (!g) return base;
  return `${base}, ${{ posti: 'posti liberi', pochi: 'pochi posti', completo: 'nessun posto', nessuno: 'nessun posto', chiuso: 'studio chiuso', fuori: 'non ancora prenotabile' }[g.stato]}`;
}

function sOrario() {
  const p = parametriOrario(); if (!p) return { html: scheletro(3) };
  const sposta = p.modo === 'sposta';
  const d = state.draft; const pres = prestazione(p.prestazione);
  const pro = PROFESSIONISTI[p.professionista];
  const sede = SEDI[p.sede];
  const chi = pro ? conArticolo(pro) : 'il primo professionista disponibile';
  // Sede non scelta (passo spento): vale quella dell'orario libero, scritta sotto ogni orario.
  const nomiSedi = sediDiOrario(p).map(s => SEDI[s]?.nome).filter(Boolean);
  const dove = sede ? (sede?.online ? 'in videochiamata' : `a ${esc(sede?.nome || '')}`) : nomiSedi.length ? `nella sede con il posto libero (${nomiSedi.join(' o ')})` : '';
  const testa = sposta
    ? titolo('Scegli il nuovo orario', `${pres ? esc(pres?.nome || '') : 'Appuntamento'} con ${chi}${dove ? ' '+dove : ''}. Il tuo appuntamento di ${dataLunga(p.attuale.data)} alle ${p.attuale.ora} resta valido finché non confermi.`)
    : titolo('Quando vuoi venire?', `${pres ? esc(pres?.nome || '') : 'Appuntamento'} con ${chi}${dove ? ' '+dove : ''}.`);
  const disp = dispPer(p); const k = chiaveDisp(p);

  if (disp.primo === undefined) { caricaPrimo(p); return { html: testa + scheletroOrario(), cta: ctaOrario(p) }; }
  if (disp.primo === null) return { html: testa + nessunaDisponibilita(p, null) };
  if (!sposta && diffGiorni(OGGI_ISO, disp.primo.data) > 30 && !state.ui.oltre30.has(k)) return { html: testa + nessunaDisponibilita(p, disp.primo) };

  // Giorno selezionato: si riparte dal primo disponibile quando cambiano prestazione o professionista.
  if (state.ui.giornoChiave !== `${state.ui.screen}|${k}`) {
    state.ui.giornoChiave = `${state.ui.screen}|${k}`;
    state.ui.giorno = (!sposta && d.data) || (sposta && state.ui.sposta && state.ui.sposta.data) || disp.primo.data;
    state.ui.scegliPrimoTra = null;
  }
  // Dopo "14 giorni successivi" o "Mese successivo": appena arrivano i dati si seleziona il primo giorno con posti.
  const sp = state.ui.scegliPrimoTra;
  if (sp) {
    let tutti = true;
    for (let iso = sp.inizio; iso <= sp.fine; iso = piuGiorni(iso, 1)) if (!disp.giorni[iso]) { tutti = false; break; }
    if (tutti) { const pr = primoLiberoTra(disp, sp.inizio, sp.fine); if (pr) state.ui.giorno = pr; state.ui.scegliPrimoTra = null; }
  }
  const giorno = state.ui.giorno;
  const pag = paginaDi(giorno);
  const mese = giorno.slice(0, 7);
  const [ay, am] = mese.split('-').map(Number);
  const primoMese = `${mese}-01`; const ultimoMese = isoDi(new Date(ay, am, 0));
  const pagineMese = [];
  for (let iso = primoMese < OGGI_ISO ? OGGI_ISO : primoMese; iso <= ultimoMese && iso <= ULTIMO_GIORNO(); iso = piuGiorni(iso, 7)) pagineMese.push(paginaDi(iso));
  pagineMese.push(paginaDi(ultimoMese > ULTIMO_GIORNO() ? ULTIMO_GIORNO() : ultimoMese));
  caricaPagine(p, [pag, ...pagineMese]);

  const sel = sposta ? state.ui.sposta : (d.data && d.ora ? { data: d.data, ora: d.ora } : null);
  const primoSel = sel && sel.data === disp.primo.data && sel.ora === disp.primo.ora;
  const bottonePrimo = `<button type="button" class="first-free" id="primo-orario" data-action="primo-orario" ${primoSel ? 'aria-pressed="true"' : ''}>
      <span class="icon-tile">${icon('zap')}</span>
      <span><span class="ff-label">Primo orario libero</span><span class="ff-val">${maiuscola(dataLunga(disp.primo.data))}, ${disp.primo.ora}</span></span>
      <span class="opt-end">${primoSel ? icon('check') : icon('chev-right')}</span></button>`;

  return {
    cta: ctaOrario(p),
    // Gli aggiornamenti degli orari si annunciano dalla regione #sr (aria-live) con annuncia(): la zona si ridisegna e non può essere live.
    html: `${testa}${!sposta && riservaValida() ? bannerRiserva() : ''}${bottonePrimo}
      <div class="dt wide">
        <div>
          <div class="only-narrow">${striscia(p, disp, pag, giorno)}</div>
          <div class="only-wide">${calendario(p, disp, mese, giorno)}</div>
        </div>
        <div id="orari-zona">${orariDelGiorno(p, disp, giorno, sel)}</div>
      </div>`,
  };
}
function scheletroOrario() {
  return `<div aria-hidden="true"><span class="sk" style="height:60px;border-radius:12px"></span>
    <div style="display:flex;gap:8px;margin-top:24px">${'<span class="sk" style="width:56px;height:72px;border-radius:12px;flex:none"></span>'.repeat(5)}</div>
    <div class="slot-grid mt24">${'<span class="sk" style="height:48px;border-radius:10px"></span>'.repeat(6)}</div></div>`;
}
function striscia(p, disp, pag, giorno) {
  const inizio = inizioPagina(pag); const fine = piuGiorni(inizio, 13);
  const m1 = meseAnno(inizio), m2 = meseAnno(fine);
  const intestazione = m1 === m2 ? maiuscola(m1) : `${maiuscola(MESI[daIso(inizio).getMonth()])} e ${m2}`;
  let celle = '';
  for (let i = 0; i < 14; i++) {
    const iso = piuGiorni(inizio, i); const g = statoGiorno(disp, iso);
    const ok = giornoSelezionabile(g, iso); const on = iso === giorno;
    const dot = g ? (g.stato === 'posti' ? '<span class="dot posti"></span>' : g.stato === 'pochi' ? '<span class="dot pochi"></span>' : '<span class="dot"></span>') : '<span class="dot"></span>';
    celle += `<button type="button" role="radio" class="day ${g && !g.orari.length ? 'is-empty' : ''}" id="g-${iso}" data-action="giorno" data-iso="${iso}"
      aria-checked="${on}" tabindex="${on ? 0 : -1}" ${ok ? '' : 'aria-disabled="true"'} aria-label="${etichettaGiorno(iso, g)}">
      <span class="day-dow" aria-hidden="true">${iso === OGGI_ISO ? 'oggi' : GIORNI_BREVI[daIso(iso).getDay()]}</span>
      <span class="day-num" aria-hidden="true">${daIso(iso).getDate()}</span>${dot}</button>`;
  }
  return `<div class="nav-row"><p class="nav-title" id="striscia-t">${intestazione}</p>
      <div class="nav-btns">
        <button type="button" class="icon-btn" data-action="pagina" data-delta="-1" id="pag-prec" aria-label="14 giorni precedenti" ${pag <= 0 ? 'aria-disabled="true"' : ''}>${icon('chev-left')}</button>
        <button type="button" class="icon-btn" data-action="pagina" data-delta="1" id="pag-succ" aria-label="14 giorni successivi" ${pag >= MAX_PAGINA() ? 'aria-disabled="true"' : ''}>${icon('chev-right')}</button>
      </div></div>
    <div class="strip" role="radiogroup" aria-labelledby="striscia-t" data-roving="lineare">${celle}</div>
    ${legenda()}`;
}
function legenda() {
  return `<p class="legend-row" aria-hidden="true"><span><span class="dot posti"></span>Posti liberi</span><span><span class="dot pochi"></span>Pochi posti</span><span><span class="dot none"></span>Nessun posto</span></p>`;
}
function calendario(p, disp, mese, giorno) {
  const [y, m] = mese.split('-').map(Number);
  const primo = new Date(y, m - 1, 1); const giorniMese = new Date(y, m, 0).getDate();
  const vuoti = (primo.getDay() + 6) % 7; // settimana da lunedì
  let celle = '<span></span>'.repeat(vuoti);
  for (let n = 1; n <= giorniMese; n++) {
    const iso = `${mese}-${pad(n)}`; const g = statoGiorno(disp, iso);
    const ok = giornoSelezionabile(g, iso) && iso >= OGGI_ISO; const on = iso === giorno;
    const dot = g && g.orari.length ? `<span class="dot ${g.stato}"></span>` : '<span class="dot"></span>';
    celle += `<button type="button" role="radio" class="cal-day ${iso === OGGI_ISO ? 'is-today' : ''} ${g && !g.orari.length ? 'is-empty' : ''}" id="c-${iso}" data-action="giorno" data-iso="${iso}"
      aria-checked="${on}" tabindex="${on ? 0 : -1}" ${ok ? '' : 'aria-disabled="true"'} aria-label="${etichettaGiorno(iso, g)}${iso === OGGI_ISO ? ', oggi' : ''}">${n}${dot}</button>`;
  }
  const prec = `${mese}-01` <= OGGI_ISO; const succ = isoDi(new Date(y, m, 1)) > ULTIMO_GIORNO();
  return `<div class="cal">
    <div class="nav-row"><p class="nav-title" id="cal-t">${maiuscola(meseAnno(`${mese}-01`))}</p>
      <div class="nav-btns">
        <button type="button" class="icon-btn" data-action="mese" data-delta="-1" id="mese-prec" aria-label="Mese precedente" ${prec ? 'aria-disabled="true"' : ''}>${icon('chev-left')}</button>
        <button type="button" class="icon-btn" data-action="mese" data-delta="1" id="mese-succ" aria-label="Mese successivo" ${succ ? 'aria-disabled="true"' : ''}>${icon('chev-right')}</button>
      </div></div>
    <div class="cal-dow" aria-hidden="true"><span>L</span><span>M</span><span>M</span><span>G</span><span>V</span><span>S</span><span>D</span></div>
    <div class="cal-days" role="radiogroup" aria-labelledby="cal-t" data-roving="griglia">${celle}</div>
    ${legenda()}
  </div>`;
}
function orariDelGiorno(p, disp, giorno, sel) {
  const g = statoGiorno(disp, giorno);
  const titoloGiorno = `<h2 class="h2 day-head" id="orari-t">${maiuscola(dataLunga(giorno))}</h2>`;
  if (!g) return `${titoloGiorno}<div class="slot-grid mt12" aria-hidden="true">${'<span class="sk" style="height:48px;border-radius:10px"></span>'.repeat(6)}</div>`;
  if (!g.orari.length) {
    const prossimo = disp.prossimo[giorno];
    if (!(giorno in disp.prossimo)) caricaProssimo(p, giorno);
    const motivo = g.stato === 'chiuso' ? `Lo studio è chiuso ${dataLunga(giorno)}.` : `Nessun orario libero ${dataLunga(giorno)}.`;
    let dopo = '<span class="sk sk-line mt8" style="width:70%" aria-hidden="true"></span>';
    if (prossimo === null) dopo = '<p class="mt8">Nei giorni successivi non ci sono altri posti prenotabili online.</p>';
    else if (prossimo) dopo = `<p class="mt8">Il primo posto libero è <strong>${dataLunga(prossimo.data)} alle ${prossimo.ora}</strong>.</p>
      <button type="button" class="btn btn-secondary mt16" id="vai-prossimo" data-action="vai-giorno" data-iso="${prossimo.data}">Vai al ${giornoMese(prossimo.data)}</button>`;
    return `${titoloGiorno}<div class="card empty-day mt12"><p class="h3">${motivo}</p>${dopo}</div>`;
  }
  const fasce = {};
  g.orari.forEach(o => { (fasce[fasciaDi(o.ora)] = fasce[fasciaDi(o.ora)] || []).push(o); });
  const selQui = sel && sel.data === giorno ? sel.ora : null;
  const primoFocus = selQui || g.orari[0].ora;
  const attuale = p.attuale && p.attuale.data === giorno ? p.attuale.ora : null;
  const piuSedi = !p.sede && sediDiOrario(p).length > 1; // sede non scelta: sotto l'orario si legge dove
  const gruppi = ['Mattina', 'Pomeriggio', 'Sera'].filter(f => fasce[f]).map(f => `<div class="slot-group"><p class="slot-label" aria-hidden="true">${f}</p><div class="slot-grid">
      ${fasce[f].map(o => {
        const on = o.ora === selQui; const nomeSede = SEDI[o.sede]?.online ? 'Video' : (SEDI[o.sede]?.nome || '');
        return `<button type="button" role="radio" class="slot ${piuSedi ? 'has-sede' : ''}" id="slot-${o.ora.replace(':', '')}" data-action="ora" data-ora="${o.ora}" data-pro="${o.professionista}" data-sede="${o.sede}" data-durata="${o.durata || prestazione(p.prestazione)?.durata || ''}"
          aria-checked="${on}" tabindex="${o.ora === primoFocus ? 0 : -1}" aria-label="${o.ora}, ${f.toLowerCase()}${p.professionista === 'primo' ? `, con ${nomePro(PROFESSIONISTI[o.professionista])}` : ''}${piuSedi ? `, sede di ${nomeSede}` : ''}">${on ? icon('check') : ''}<span>${o.ora}</span>${piuSedi ? `<span class="slot-sede" aria-hidden="true">${nomeSede}</span>` : ''}</button>`;
      }).join('')}</div></div>`).join('');
  const n = g.orari.length;
  return `${titoloGiorno}<p class="micro" style="margin-bottom:16px">${n === 1 ? '1 orario libero' : `${n} orari liberi`}${prestazione(p.prestazione) ? ' · visita di '+(prestazione(p.prestazione)?.durata || p.durata)+' minuti' : ''}</p>
    ${attuale ? `<p class="micro" style="margin:-8px 0 16px">Il tuo orario attuale è alle ${attuale}.</p>` : ''}
    <div role="radiogroup" aria-labelledby="orari-t" data-roving="lineare">${gruppi}</div>
    <p class="micro slots-note">${p.modo === 'sposta' ? 'Il vecchio orario si libera solo quando confermi lo spostamento.'
      : !verificaRichiesta() ? 'L\'orario diventa tuo quando confermi la prenotazione.'
      : verificato() ? 'Appena scegli, teniamo l\'orario per te per 10 minuti.' : 'Toccare un orario non lo blocca: lo teniamo per te 10 minuti dopo la verifica.'}</p>`;
}
function ctaOrario(p) {
  const sposta = p.modo === 'sposta';
  const pronto = sposta ? !!(state.ui.sposta && state.ui.sposta.ora) : !!(state.draft.data && state.draft.ora);
  return { label: sposta ? 'Continua' : 'Scegli questo orario', azione: sposta ? azioni.continuaSposta : azioni.confermaOrario, pronto, avviso: 'Scegli un orario per continuare.' };
}

// ----- S16 Nessuna disponibilità -----
function nessunaDisponibilita(p, primo) {
  const pres = prestazione(p.prestazione); const pro = PROFESSIONISTI[p.professionista];
  const altri = (pres?.professionisti || (state.catalogo?.professionisti || [])).length > 1 && passoAttivo('professionista');
  const finestra = primo ? 30 : (state.catalogo ? state.catalogo.regole.finestra_giorni : 90);
  return `<div class="card mt8">
      <div class="mark neutral" style="margin:0 0 16px">${icon('calendar-x')}</div>
      <h2 class="h2">Nessun orario libero nei prossimi ${finestra} giorni</h2>
      <p class="mt8">Al momento ${pro ? conArticolo(pro) : 'nessun professionista'} non ha posti liberi ${pres ? 'per la '+esc(pres.nome.toLowerCase())+' ' : ''}fino al ${giornoMese(piuGiorni(OGGI_ISO, finestra))}.
      ${primo ? `Il primo posto libero è <strong>${dataLunga(primo.data)} alle ${primo.ora}</strong>.` : ''}</p>
      <div class="btn-row mt20">
        ${primo ? `<button type="button" class="btn btn-primary btn-lg btn-block" data-action="oltre30">Vedi gli orari di ${MESI[daIso(primo.data).getMonth()]}</button>` : ''}
        <button type="button" class="btn btn-secondary btn-block" data-action="avvisami">${icon('clock')}Avvisami se si libera un posto${primo ? ' prima' : ''}</button>
        ${altri ? '<button type="button" class="btn btn-secondary btn-block" data-action="cambia-pro">Prova con un altro professionista</button>' : ''}
        <a class="btn btn-secondary btn-block" href="${esc(STUDIO.telHref)}">${icon('phone')}Chiama lo studio</a>
      </div>
    </div>`;
}

// ----- S06 Per chi è la visita -----
function erroriPerChi() {
  const d = state.draft; const e = {};
  if (d.perChi !== 'altro') return e;
  if (d.assistito.nome.trim().length < 2) e['as-nome'] = 'Scrivi il nome della persona che verrà in visita.';
  if (d.assistito.cognome.trim().length < 2) e['as-cognome'] = 'Scrivi il cognome della persona che verrà in visita.';
  const n = validaNascita(d.assistito.nascita); if (n) e['as-nascita'] = n;
  if (!d.autorizzato) e['as-autorizzato'] = 'Per continuare conferma di poter prenotare per questa persona.';
  return e;
}
function sPerChi() {
  const d = state.draft; const er = state.ui.tentato.perchi ? erroriPerChi() : state.ui.errori;
  const rc = (val, ic, tit, sub) => `<label class="rcard ${d.perChi === val ? 'is-selected' : ''}" for="perchi-${val}">
      <input type="radio" name="perchi" id="perchi-${val}" value="${val}" data-change="perchi" ${d.perChi === val ? 'checked' : ''}>
      <span class="icon-tile ${d.perChi === val ? '' : 'neutral'}">${icon(ic)}</span>
      <span class="opt-body"><span class="opt-title">${tit}</span><span class="opt-sub">${sub}</span></span>
      <span class="rcard-mark" aria-hidden="true"></span></label>`;
  return {
    cta: { label: 'Continua', azione: azioni.continuaPerChi },
    html: `${titolo('Per chi è la visita?', 'Puoi prenotare anche per un familiare o per una persona che assisti.')}
      <fieldset><legend class="sr-only">Per chi è la visita</legend><div class="rcards">
        ${rc('me', 'user', 'Per me', 'La visita è per te.')}
        ${rc('altro', 'users', 'Per un\'altra persona', 'Un familiare, un figlio minorenne o una persona che assisti.')}
      </div></fieldset>
      ${d.perChi === 'altro' ? `<fieldset class="card mt20"><legend class="legend" style="float:left;width:100%">Chi verrà in visita</legend>
        <div class="grid-2" style="clear:both">
          ${campo({ id: 'as-nome', label: 'Nome', valore: d.assistito.nome, autocomplete: 'off', input: 'assistito.nome', errore: er['as-nome'] })}
          ${campo({ id: 'as-cognome', label: 'Cognome', valore: d.assistito.cognome, autocomplete: 'off', input: 'assistito.cognome', errore: er['as-cognome'] })}
        </div>
        ${campo({ id: 'as-nascita', label: 'Data di nascita', valore: d.assistito.nascita, inputmode: 'numeric', placeholder: 'gg/mm/aaaa', aiuto: 'Richiesta dallo studio.', input: 'assistito.nascita', errore: er['as-nascita'] })}
        ${casella({ id: 'as-autorizzato', change: 'autorizzato', checked: d.autorizzato, errore: er['as-autorizzato'], label: 'Posso prenotare per questa persona. Se è minorenne, sono il genitore o il tutore.' })}
        <p class="micro mt8">Conferme e promemoria arrivano a te, ai tuoi recapiti.</p>
      </fieldset>` : ''}`,
  };
}

// ----- S08a Il tuo cellulare (anche accesso a "Le mie prenotazioni") -----
// Testi dei metodi di verifica. Lo studio sceglie quali accendere e in che ordine (config.verifica.metodi).
const METODO = {
  whatsapp: { icona: 'chat', titolo: 'Con WhatsApp', sotto: 'Mandi allo studio un messaggio già scritto. Basta un tocco, nessun codice da copiare.',
    unico: 'Per verificare mandi allo studio un messaggio WhatsApp già scritto: basta un tocco.', cta: 'Verifica con WhatsApp', in: 'su WhatsApp' },
  email: { icona: 'mail', titolo: 'Con un codice via email', sotto: 'Ti mandiamo 6 cifre all\'indirizzo che scegli. Valgono 10 minuti.',
    unico: 'Ti mandiamo un codice di 6 cifre via email. Vale 10 minuti.', cta: 'Mandami il codice', in: 'via email' },
  sms: { icona: 'smartphone', titolo: 'Con un codice via SMS', sotto: 'Ti mandiamo 6 cifre al cellulare. Valgono 10 minuti.',
    unico: 'Ti mandiamo un codice di 6 cifre via SMS a questo numero. Vale 10 minuti.', cta: 'Mandami il codice', in: 'via SMS' },
};
// "su WhatsApp, via email o via SMS"
function elencoMetodi(metodi) {
  const parti = metodi.map(m => METODO[m].in);
  return parti.length > 1 ? `${parti.slice(0, -1).join(', ')} o ${parti[parti.length - 1]}` : parti[0] || '';
}
function sTelefono() {
  const mie = state.ui.screen === 'mie-accesso';
  const o = state.ui.otp; const err = state.ui.errori.cell;
  if (!mie && verificato()) {
    return {
      cta: { label: 'Continua', azione: async () => { if (state.draft.data && state.draft.ora && !riservaValida() && !(await azioni.prendiRiserva())) return; avanti('codice'); } },
      html: `${titolo('Il tuo cellulare', 'Questo dispositivo ha già verificato il tuo numero.')}
        <div class="card"><div class="recap-row" style="padding:0">${icon('smartphone')}<div class="recap-main"><p class="recap-label">${cellVerificato() ? 'Cellulare verificato' : 'Cellulare'}</p>
        <p class="recap-val tnum">+39 ${formattaCell(state.sessione.cellulare)}</p></div>${badge(cellVerificato() ? 'Verificato' : 'Email verificata', 'ok', 'check')}</div></div>
        <button type="button" class="btn-link mt12" data-action="altro-numero">Usa un altro numero</button>`,
    };
  }
  // Metodi accesi dallo studio, nell'ordine scelto: il primo è "Consigliato". Con un solo metodo niente scelta.
  const metodi = metodiVerifica();
  if (!metodi.length) { // es. solo SMS e tetto giornaliero raggiunto: il metodo torna domani
    return { html: `${titolo('La verifica non è disponibile adesso', 'Per oggi non possiamo mandare altri codici. Riprova domani, oppure prenota per telefono.')}${contattiStudio()}` };
  }
  if (!metodi.includes(o.metodo)) o.metodo = metodi[0];
  const testa = mie
    ? titolo('Le tue prenotazioni', `Entra con il cellulare che hai usato per prenotare e una verifica veloce, ${elencoMetodi(metodi)}. Niente password da ricordare.`)
    : titolo('Verifica che sei tu', 'Così siamo sicuri che sia proprio tu e nessuno può prenotare a tuo nome. Non serve creare un account.');
  const errMail = state.ui.errori.email;
  const scelta = (val, i) => { const m = METODO[val]; return `<label class="rcard ${o.metodo === val ? 'is-selected' : ''}" for="metodo-${val}">
      <input type="radio" name="metodo" id="metodo-${val}" value="${val}" data-change="metodo" ${o.metodo === val ? 'checked' : ''}>
      <span class="icon-tile ${o.metodo === val ? '' : 'neutral'}">${icon(m.icona)}</span>
      <span class="opt-body"><span class="opt-title">${m.titolo}${i === 0 && metodi.length > 1 ? ` ${badge('Consigliato', 'ok')}` : ''}</span><span class="opt-sub">${m.sotto}</span></span>
      <span class="rcard-mark" aria-hidden="true"></span></label>`; };
  const sceltaMetodo = metodi.length > 1
    ? `<fieldset class="mt8"><legend class="label" style="margin-bottom:8px">Come vuoi verificare?</legend>
          <div class="rcards">${metodi.map(scelta).join('')}</div>
        </fieldset>`
    : `<p class="hint mt8" style="display:flex;gap:8px;align-items:flex-start">${icon(METODO[o.metodo].icona, 'ico-sm')}<span>${METODO[o.metodo].unico}</span></p>`;
  return {
    cta: { label: METODO[o.metodo].cta, azione: () => azioni.inviaCodice(mie ? 'mie' : 'prenota') },
    html: `${testa}
      <form novalidate data-submit="invia-codice" data-ctx="${mie ? 'mie' : 'prenota'}" class="${err ? 'has-error' : ''}">
        <div class="field">
          <label class="label" for="cell">Numero di cellulare</label>
          <div class="phone"><span class="phone-pre" aria-hidden="true">+39</span>
            <input class="input" id="cell" type="tel" inputmode="tel" autocomplete="tel-national" value="${esc(state.ui.cellulareInput)}" placeholder="347 123 4567"
              data-input="cellulare" aria-required="true" aria-describedby="cell-aiuto${err ? ' cell-err' : ''}" ${err ? 'aria-invalid="true"' : ''}></div>
          <p class="hint" id="cell-aiuto">Prefisso +39 già inserito. ${mie ? '' : 'Lo usiamo per i promemoria e per riconoscerti se sei già paziente.'}</p>
          ${err ? `<p class="error" id="cell-err">${icon('alert')}<span>${err}</span></p>` : ''}
        </div>
        ${sceltaMetodo}
        ${o.metodo !== 'email' ? '' : `<div class="field mt12">
          <label class="label" for="email-ver">La tua email</label>
          <input class="input" id="email-ver" type="email" inputmode="email" autocomplete="email" value="${esc(o.email)}" placeholder="nome@esempio.it"
            data-input="emailVer" aria-required="true" ${errMail ? 'aria-invalid="true" aria-describedby="email-ver-err"' : ''}>
          ${errMail ? `<p class="error" id="email-ver-err">${icon('alert')}<span>${errMail}</span></p>` : ''}
        </div>`}
        <div class="trap" aria-hidden="true"><label for="sito-web">Non compilare questo campo</label><input id="sito-web" name="sito_web" tabindex="-1" autocomplete="off"></div>
        <p class="antibot ${o.antibot ? 'is-on' : ''}" role="status">${o.antibot ? '<span class="spin dark" aria-hidden="true"></span>Verifica di sicurezza in corso' : `${icon('shield')}Protetto da una verifica automatica anti bot, senza puzzle.`}</p>
        <button type="submit" hidden></button>
      </form>
      ${demoHint(`numero nuovo <button type="button" data-action="demo-numero" data-n="${NUMERO_ROSA}">347 812 6650</button> (Rosa) · già in archivio <button type="button" data-action="demo-numero" data-n="${NUMERO_DAVIDE}">333 000 0000</button> (Davide)`)}`,
  };
}

// ----- S08b Codice a 6 cifre -----
function sWhatsapp() {
  const o = state.ui.otp; const mie = o.contesto === 'mie';
  return {
    cta: { label: 'Apri WhatsApp', azione: azioni.apriWhatsapp },
    html: `${titolo('Manda il messaggio su WhatsApp', 'Si apre WhatsApp con il messaggio già scritto per lo studio. Devi solo toccare Invia.')}
      <div class="card card-tight">
        <p class="micro">Il messaggio che invierai</p>
        <p class="wa-to"><strong>A: ${esc(STUDIO.nome)}</strong> <span class="tnum">${esc(STUDIO.whatsapp)}</span></p>
        <p class="wa-bubble tnum">${esc(o.messaggio)}</p>
      </div>
      <div class="wa-qr">
        <svg viewBox="-2 -2 29 29" width="132" height="132" shape-rendering="crispEdges" aria-hidden="true" focusable="false"><rect x="-2" y="-2" width="29" height="29" fill="#fff"></rect><path fill="#08364F" d="${QR_DEMO}"></path></svg>
        <p class="micro">Dal computer: inquadra il codice con la fotocamera del telefono e invia il messaggio da lì.</p>
      </div>
      <div class="note note-info mt12" role="status"><span class="spin dark" aria-hidden="true"></span><div><p><strong>In attesa del tuo messaggio.</strong> Quando arriva allo studio, questa pagina va avanti da sola.</p></div></div>
      <div class="mt8" style="display:flex;flex-direction:column;align-items:flex-start">
        ${altriMetodi('Non usi WhatsApp? ')}
        <button type="button" class="btn-link" data-action="cambia-numero">Cambia numero</button>
      </div>
      ${demoHint('in produzione il messaggio arriva al gateway WhatsApp dello studio. Qui lo simuli: <button type="button" data-action="simula-wa">Simula messaggio ricevuto</button>')}`,
  };
}

// Link per cambiare metodo: solo verso gli altri metodi accesi dallo studio.
function altriMetodi(prefisso = '') {
  const o = state.ui.otp;
  const testi = { whatsapp: 'Verifica invece con WhatsApp', email: 'Ricevi invece un codice via email', sms: 'Ricevi invece un codice via SMS' };
  return metodiVerifica().filter(m => m !== o.metodo).map((m, i) => `<button type="button" class="btn-link" data-action="usa-metodo" data-metodo="${m}">${i === 0 ? prefisso : ''}${testi[m]}</button>`).join('');
}

// Codice a 6 cifre: via email o via SMS (stessa schermata, testi diversi).
function sCodice() {
  const o = state.ui.otp; const ora = Date.now();
  if (o.metodo === 'whatsapp') return sWhatsapp();
  const sms = o.metodo === 'sms';
  const bloccato = o.bloccatoFino > ora;
  const pronto = o.rimandaDa <= ora;
  const caselle = o.cifre.map((c, i) => `<input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="${i === 0 ? 6 : 1}" id="otp-${i}" data-input="otp" data-i="${i}"
      value="${esc(c)}" aria-label="Cifra ${i + 1} di 6" ${i === 0 ? 'autocomplete="one-time-code"' : 'autocomplete="off"'} ${bloccato ? 'disabled' : ''}
      ${o.errore ? 'aria-invalid="true" aria-describedby="otp-err"' : ''}>`).join('');
  return {
    cta: { label: 'Verifica il codice', azione: azioni.verificaCodice, pronto: !bloccato && o.cifre.join('').length === 6, avviso: bloccato ? 'Per sicurezza i tentativi sono bloccati per qualche minuto.' : 'Scrivi tutte le 6 cifre del codice.' },
    html: `${titolo('Inserisci il codice', sms
      ? `Ti abbiamo mandato un SMS al <strong class="tnum">${formattaCell(o.cellulare)}</strong>. Vale 10 minuti.`
      : `Te l'abbiamo mandato a <strong>${esc(o.email)}</strong>. Vale 10 minuti. Non lo trovi? Guarda nello spam.`)}
      <fieldset ${o.errore ? 'aria-describedby="otp-err"' : ''}><legend class="sr-only">Codice di verifica a 6 cifre</legend>
        <div class="otp ${o.errore ? 'is-error' : ''}" aria-busy="${o.inVerifica}">${caselle}</div>
      </fieldset>
      ${o.errore ? `<p class="error" id="otp-err" role="alert">${icon('alert')}<span>${o.errore}${bloccato ? ` Riprova tra <strong class="tnum" data-blocco>${mmss(o.bloccatoFino - ora)}</strong>.` : ''}</span></p>` : ''}
      ${o.inVerifica ? '<p class="antibot is-on mt8" role="status"><span class="spin dark" aria-hidden="true"></span>Verifica del codice</p>' : ''}
      <div class="resend mt12">
        ${pronto && !bloccato
          ? `<span>Non ti è arrivato?</span><button type="button" class="btn-link" data-action="rimanda" id="rimanda">Rimanda il codice</button>`
          : `<span>Non ti è arrivato? Puoi chiederne un altro tra <strong class="tnum" data-rimanda>${mmss(Math.max(o.rimandaDa, bloccato ? o.bloccatoFino : 0) - ora)}</strong>.</span>`}
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-start">
        <button type="button" class="btn-link" data-action="cambia-numero">${sms ? 'Cambia numero' : 'Cambia email o numero'}</button>
        ${altriMetodi()}
      </div>
      ${bloccato ? `<p class="micro mt8">Hai bisogno di aiuto? Chiama lo <a href="${esc(STUDIO.telHref)}">${esc(STUDIO.telefono)}</a>.</p>` : ''}
      ${demoHint('usa il codice <b>123456</b>. Un codice diverso mostra l\'errore; dopo 5 errori i tentativi si bloccano per 15 minuti.')}`,
  };
}

// ----- S07 I tuoi dati (solo per chi è nuovo; chi è in archivio conferma con un tocco) -----
// Quali campi chiedere lo decide la configurazione (config.dati: obbligatorio, facoltativo, nascosto).
// Nome e cognome sono sempre obbligatori. Senza verifica c'è il blocco "Come ti contattiamo".
const RE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const RE_CF = /^[A-Z0-9]{16}$/i; // controllo di base: 16 lettere e cifre (il controllo completo, omocodie comprese, è lato server)
function validaNascita(v) {
  const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(String(v).trim());
  if (!String(v).trim()) return 'Scrivi la data di nascita.';
  if (!m) return 'Scrivi la data nel formato gg/mm/aaaa, ad esempio 14/03/1968.';
  const g = +m[1], mm = +m[2], a = +m[3]; const dt = new Date(a, mm - 1, g);
  if (dt.getFullYear() !== a || dt.getMonth() !== mm - 1 || dt.getDate() !== g) return 'Questa data non esiste: controlla giorno e mese.';
  if (dt > OGGI) return 'La data di nascita non può essere nel futuro.';
  if (a < 1900) return 'Controlla l\'anno di nascita.';
  return null;
}
const campoDato = k => cfg().dati[k] || 'nascosto';   // 'obbligatorio' | 'facoltativo' | 'nascosto'
// Paziente riconosciuto dopo la verifica (senza verifica nessuno è riconosciuto: il numero è solo un indizio per lo studio).
const pazienteRiconosciuto = () => (verificaRichiesta() ? state.sessione.paziente : null);
// Canali di conferma e promemoria possibili: l'email solo se il campo email c'è.
function canaliPossibili() {
  const senza = !verificaRichiesta();
  return CANALI.filter(c => (c.id === 'email' ? senza || campoDato('email') !== 'nascosto' : true));
}
function erroriDati() {
  const x = state.draft.dati; const e = {};
  const cell = String(x.cellulare || '').trim(); const mail = String(x.email || '').trim();
  if (x.nome.trim().length < 2) e['dati-nome'] = 'Scrivi il tuo nome.';
  if (x.cognome.trim().length < 2) e['dati-cognome'] = 'Scrivi il tuo cognome.';
  if (!verificaRichiesta()) {
    // Come ti contattiamo: regola dello studio (senza_verifica.recapito).
    const rec = cfg().senza_verifica.recapito;
    if (cell) { const ec = validaCell(cell); if (ec) e['dati-cell'] = ec; }
    else if (rec === 'cellulare') e['dati-cell'] = 'Scrivi il tuo cellulare: ti avvisiamo lì se l\'appuntamento cambia.';
    if (mail) { if (!RE_EMAIL.test(mail)) e['dati-email'] = 'Controlla l\'email: deve essere come nome@esempio.it.'; }
    else if (rec === 'email') e['dati-email'] = 'Scrivi la tua email: ti avvisiamo lì se l\'appuntamento cambia.';
    if (rec === 'almeno_uno' && !cell && !mail) e['dati-cell'] = 'Scrivi almeno un recapito: il cellulare oppure l\'email.';
    if (!e['dati-cell'] && !e['dati-email'] && (cell || mail)) {
      if (x.canale === 'email' && !mail) e['dati-email'] = 'Per avere conferma e promemoria via email scrivi l\'indirizzo, oppure scegli WhatsApp o SMS.';
      if (x.canale !== 'email' && !cell) e['dati-cell'] = 'Per avere conferma e promemoria sul telefono scrivi il cellulare, oppure scegli Email.';
    }
  } else if (campoDato('email') !== 'nascosto') {
    if (!mail) {
      if (campoDato('email') === 'obbligatorio') e['dati-email'] = 'Scrivi la tua email: ti mandiamo lì la conferma.';
      else if (x.canale === 'email') e['dati-email'] = 'Per avere conferma e promemoria via email scrivi l\'indirizzo, oppure scegli WhatsApp o SMS.';
    } else if (!RE_EMAIL.test(mail)) e['dati-email'] = 'Controlla l\'email: deve essere come nome@esempio.it.';
  }
  if (campoDato('nascita') !== 'nascosto' && (x.nascita.trim() || campoDato('nascita') === 'obbligatorio')) {
    const n = validaNascita(x.nascita); if (n) e['dati-nascita'] = n;
  }
  if (campoDato('codice_fiscale') !== 'nascosto') {
    const cf = String(x.cf || '').trim();
    if (!cf) { if (campoDato('codice_fiscale') === 'obbligatorio') e['dati-cf'] = 'Scrivi il codice fiscale.'; }
    else if (!RE_CF.test(cf)) e['dati-cf'] = 'Il codice fiscale ha 16 caratteri tra lettere e numeri: controllalo.';
  }
  if (campoDato('note') === 'obbligatorio' && !x.nota.trim()) e['dati-nota'] = 'Scrivi una nota per lo studio.';
  if (!x.privacy) e['dati-privacy'] = 'Per continuare conferma di aver letto l\'informativa privacy.';
  return e;
}
// Paziente in archivio: i dati arrivano dalla sua scheda (dopo la verifica del codice).
function assicuraDatiPaziente() {
  const p = pazienteRiconosciuto();
  if (p && !state.draft.dati.nome) Object.assign(state.draft.dati, { nome: p.nome || '', cognome: p.cognome || '', email: p.email || '', nascita: p.nascita || '', cf: p.cf || '', canale: p.canale || state.draft.dati.canale, privacy: true });
}
const datiCompleti = () => { assicuraDatiPaziente(); return Object.keys(erroriDati()).length === 0; };

function sceltaCanale() {
  const possibili = canaliPossibili();
  if (!possibili.some(x => x.id === state.draft.dati.canale)) state.draft.dati.canale = possibili[0].id;
  const c = state.draft.dati.canale;
  return `<fieldset class="mt8"><legend class="label" style="margin-bottom:8px">Dove vuoi ricevere conferma e promemoria?</legend>
    <div class="chips">${possibili.map(x => `<label class="chip chip-radio ${c === x.id ? 'is-on' : ''}" for="canale-${x.id}">
      <input type="radio" name="canale" id="canale-${x.id}" value="${x.id}" data-change="canale" ${c === x.id ? 'checked' : ''}>${c === x.id ? icon('check') : ''}${x.nome}</label>`).join('')}</div>
    <p class="hint mt8">Ti scriviamo solo per questa prenotazione. Niente pubblicità.</p></fieldset>`;
}

// Blocco "Come ti contattiamo" (prenotazione senza verifica): cellulare ed email secondo senza_verifica.recapito.
function bloccoContatti(x, er) {
  const rec = cfg().senza_verifica.recapito;
  const facCell = rec === 'email' || rec === 'facoltativo'; const facMail = rec === 'cellulare' || rec === 'facoltativo';
  const regola = { almeno_uno: 'Scrivi almeno uno tra cellulare ed email. ', cellulare: '', email: '', facoltativo: '' }[rec] || '';
  const errC = er['dati-cell'];
  return `<fieldset class="mt8" aria-describedby="contatti-aiuto"><legend class="legend" style="margin-bottom:4px">Come ti contattiamo</legend>
      <p class="hint" id="contatti-aiuto" style="margin:0 0 12px">${regola}Ci serve per avvisarti se l'appuntamento cambia.</p>
      <div class="field ${errC ? 'has-error' : ''}">
        <label class="label" for="dati-cell">Cellulare${facCell ? ' <span class="opt-txt">(facoltativo)</span>' : ''}</label>
        <div class="phone"><span class="phone-pre" aria-hidden="true">+39</span>
          <input class="input" id="dati-cell" type="tel" inputmode="tel" autocomplete="tel-national" value="${esc(x.cellulare)}" placeholder="347 123 4567"
            data-input="dati.cellulare" data-valida="dati.cellulare" ${rec === 'cellulare' ? 'aria-required="true"' : ''} aria-describedby="dati-cell-aiuto${errC ? ' dati-cell-err' : ''}" ${errC ? 'aria-invalid="true"' : ''}></div>
        <p class="hint" id="dati-cell-aiuto">Prefisso +39 già inserito.</p>
        ${errC ? `<p class="error" id="dati-cell-err">${icon('alert')}<span>${errC}</span></p>` : ''}
      </div>
      ${campo({ id: 'dati-email', label: 'Email', tipo: 'email', valore: x.email, autocomplete: 'email', inputmode: 'email', input: 'dati.email', errore: er['dati-email'], facoltativo: facMail, richiesto: rec === 'email', extra: 'spellcheck="false" autocapitalize="off"' })}
    </fieldset>`;
}

function sDati() {
  assicuraDatiPaziente();
  const d = state.draft; const paz = pazienteRiconosciuto(); const senza = !verificaRichiesta();
  const perAltro = d.perChi === 'altro' ? `<p class="micro on-bg" style="margin:-12px 0 16px">Sono i dati di chi prenota. In visita verrà ${esc(d.assistito.nome)} ${esc(d.assistito.cognome)}.</p>` : '';
  // Paziente riconosciuto: scheda in sola lettura. Se alla scheda manca un dato che lo studio chiede (es. codice fiscale) si apre il modulo.
  if (paz && !state.ui.correggi && Object.keys(erroriDati()).length === 0) {
    // Il genere si usa solo se è in scheda; altrimenti un saluto neutro.
    const saluto = paz.genere === 'f' ? `Bentornata ${esc(paz.nome)}` : paz.genere === 'm' ? `Bentornato ${esc(paz.nome)}` : `Ciao ${esc(paz.nome)}, ti abbiamo riconosciuto`;
    const riga = (label, val, cls = '') => `<div><dt class="recap-label">${label}</dt><dd class="recap-val ${cls}" style="font-weight:500">${val}</dd></div>`;
    return {
      cta: { label: 'Continua', azione: azioni.continuaDati },
      html: `${titolo(saluto, paz.genere ? 'Ti abbiamo riconosciuto dal numero. Controlla che i dati siano giusti.' : 'Controlla che i dati siano giusti.')}
        ${perAltro}${bannerRiserva()}
        <div class="card">
          <div style="display:flex;gap:14px;align-items:center"><span class="avatar navy" aria-hidden="true">${iniziali(paz.nome, paz.cognome)}</span>
            <div><p class="h3">${esc(paz.nome)} ${esc(paz.cognome)}</p><p class="micro">Paziente del ${esc(STUDIO.nome)}</p></div></div>
          <div class="divider"></div>
          <dl class="stack-sm">
            ${riga('Cellulare', `+39 ${formattaCell(state.sessione.cellulare)} ${cellVerificato() ? `<span class="badge badge-ok" style="margin-left:6px">${icon('check')}Verificato</span>` : ''}`, 'tnum')}
            ${campoDato('email') !== 'nascosto' && d.dati.email ? riga('Email', esc(d.dati.email)) : ''}
            ${campoDato('nascita') !== 'nascosto' && d.dati.nascita ? riga('Data di nascita', esc(d.dati.nascita), 'tnum') : ''}
            ${campoDato('codice_fiscale') !== 'nascosto' && d.dati.cf ? riga('Codice fiscale', esc(d.dati.cf), 'tnum') : ''}
          </dl>
          <button type="button" class="btn-link mt8" data-action="correggi">I dati non sono giusti? Correggili</button>
        </div>
        <div class="card">${sceltaCanale()}</div>
        <p class="micro on-bg mt12">Hai già letto l'<a href="#privacy" data-action="nota-legale">informativa privacy dello studio</a>.</p>`,
    };
  }
  const x = d.dati; const er = state.ui.tentato.dati ? erroriDati() : state.ui.errori;
  const fac = k => campoDato(k) === 'facoltativo';
  const mostra = k => campoDato(k) !== 'nascosto';
  const campoNota = campoDato('note');
  return {
    cta: { label: 'Continua', azione: azioni.continuaDati },
    html: `${titolo('I tuoi dati', senza ? 'Servono allo studio per preparare la visita e per avvisarti se l\'appuntamento cambia.' : 'Servono allo studio per preparare la visita e per mandarti la conferma.')}
      ${perAltro}${bannerRiserva()}
      <form novalidate data-submit="dati" class="card">
        <div class="grid-2">
          ${campo({ id: 'dati-nome', label: 'Nome', valore: x.nome, autocomplete: 'given-name', input: 'dati.nome', errore: er['dati-nome'] })}
          ${campo({ id: 'dati-cognome', label: 'Cognome', valore: x.cognome, autocomplete: 'family-name', input: 'dati.cognome', errore: er['dati-cognome'] })}
        </div>
        ${senza ? bloccoContatti(x, er) : `<div class="field"><span class="label">Cellulare</span>
          <p class="recap-val tnum" style="font-weight:500;display:flex;align-items:center;gap:8px;min-height:24px">+39 ${formattaCell(state.sessione.cellulare || '')} ${cellVerificato() ? badge('Verificato', 'ok', 'check') : ''}</p></div>
        ${mostra('email') ? campo({ id: 'dati-email', label: 'Email', tipo: 'email', valore: x.email, autocomplete: 'email', inputmode: 'email', input: 'dati.email', errore: er['dati-email'], facoltativo: fac('email'), extra: 'spellcheck="false" autocapitalize="off"' }) : ''}`}
        ${mostra('nascita') ? campo({ id: 'dati-nascita', label: 'Data di nascita', valore: x.nascita, autocomplete: 'bday', inputmode: 'numeric', placeholder: 'gg/mm/aaaa', aiuto: 'Richiesta dallo studio.', input: 'dati.nascita', errore: er['dati-nascita'], facoltativo: fac('nascita') }) : ''}
        ${mostra('codice_fiscale') ? campo({ id: 'dati-cf', label: 'Codice fiscale', valore: x.cf, autocomplete: 'off', aiuto: 'Richiesto dallo studio. 16 caratteri, lo trovi sulla tessera sanitaria.', input: 'dati.cf', errore: er['dati-cf'], facoltativo: fac('codice_fiscale'), extra: 'spellcheck="false" autocapitalize="characters" maxlength="16"' }) : ''}
        ${sceltaCanale()}
        ${campoNota !== 'nascosto' ? `<div class="field mt20 ${er['dati-nota'] ? 'has-error' : ''}">
          <label class="label" for="dati-nota">Nota per lo studio${campoNota === 'facoltativo' ? ' <span class="opt-txt">(facoltativa)</span>' : ''}</label>
          <p class="hint" id="dati-nota-aiuto">Non serve scrivere dettagli sulla tua salute: ne parlerai in visita.</p>
          <textarea class="textarea" id="dati-nota" rows="3" maxlength="300" data-input="dati.nota" ${campoNota === 'obbligatorio' ? 'data-valida="dati.nota" aria-required="true"' : ''}
            aria-describedby="dati-nota-aiuto${er['dati-nota'] ? ' dati-nota-err' : ''}" ${er['dati-nota'] ? 'aria-invalid="true"' : ''}>${esc(x.nota)}</textarea>
          ${er['dati-nota'] ? `<p class="error" id="dati-nota-err">${icon('alert')}<span>${er['dati-nota']}</span></p>` : ''}
        </div>` : ''}
        ${casella({ id: 'dati-privacy', change: 'privacy', checked: x.privacy, errore: er['dati-privacy'], label: `Ho letto l'<a href="#privacy" data-action="nota-legale">informativa privacy</a> del ${esc(STUDIO.nome)}.` })}
        <button type="submit" hidden></button>
      </form>
      ${demoHint('<button type="button" data-action="demo-compila">Compila con i dati di Rosa</button>')}`,
  };
}

// ----- S09 Riepilogo -----
function rigaRecap({ ic, label, val, sub, passo, aria }) {
  if (!val) return '';
  return `<div class="recap-row">${icon(ic)}<div class="recap-main"><p class="recap-label">${label}</p><p class="recap-val">${val}</p>${sub ? `<p class="recap-sub">${sub}</p>` : ''}</div>
    ${passo ? `<button type="button" class="btn-link" data-action="modifica" data-passo="${passo}" aria-label="Modifica ${aria}">Modifica</button>` : ''}</div>`;
}
// Recapiti di chi prenota: verificati dalla sessione, oppure scritti nel blocco "Come ti contattiamo".
function recapiti(d) {
  const cell = verificaRichiesta() ? state.sessione.cellulare : normalizzaCell(d.dati.cellulare);
  return { cellulare: cell || null, email: String(d.dati.email || '').trim() || null };
}
// "su WhatsApp e via email", "via email", "su SMS"... null se non c'è nessun recapito.
function doveScriviamo(canale, cellulare, email) {
  const c = CANALI.find(x => x.id === canale);
  if (canale === 'email') return email ? 'via email' : null;
  const parti = [c && cellulare ? `su ${c.nome}` : null, email ? 'via email' : null].filter(Boolean);
  return parti.length ? parti.join(' e ') : null;
}
function righeRiepilogo(d, modificabile) {
  // Con il link d'invito prestazione, professionista e sede sono fissati dallo studio.
  // Le scelte automatiche (una sola possibilità o passo spento) si vedono ma non hanno "Modifica".
  const invito = state.catalogo && state.catalogo.modalita === 'invito';
  const conModifica = passo => modificabile && !invito && serve(passo) && passo;
  const pres = prestazione(d.prestazione); const pro = PROFESSIONISTI[d.proAssegnato || d.professionista]; const sede = SEDI[sedeDi(d)];
  const nomePaz = d.perChi === 'altro' ? `${esc(d.assistito.nome)} ${esc(d.assistito.cognome)}` : `${esc(d.dati.nome)} ${esc(d.dati.cognome)}`;
  const canale = CANALI.find(c => c.id === d.dati.canale);
  const r = recapiti(d);
  const contatti = [r.cellulare ? `<span class="tnum">+39 ${formattaCell(r.cellulare)}</span>` : '', r.email ? esc(r.email) : ''].filter(Boolean).join(' · ');
  const dove = doveScriviamo(d.dati.canale, r.cellulare, r.email);
  const rigaContatti = verificaRichiesta()
    ? rigaRecap({ ic: 'chat', label: 'Conferma e promemoria', val: canale ? canale.nome : '', sub: contatti, passo: modificabile && 'dati', aria: 'i contatti' })
    : rigaRecap({ ic: 'chat', label: 'Ti contatteremo a', val: contatti || 'Nessun recapito', sub: contatti ? (dove ? `Conferma e promemoria ${dove}` : '') : 'Lo studio non potrà avvisarti se l\'appuntamento cambia.', passo: modificabile && 'dati', aria: 'i contatti' });
  return [
    rigaRecap({ ic: 'steth', label: 'Prestazione', val: esc(pres?.nome || ''), sub: `${pres?.durata} minuti${pres?.prezzo == null ? '' : ' · ' + pres?.prezzo + ' €, paghi in studio'}`, passo: conModifica('prestazione'), aria: 'la prestazione' }),
    rigaRecap({ ic: 'user', label: 'Professionista', val: pro ? nomePro(pro) : 'Il primo disponibile', sub: d.professionista === 'primo' && pro ? 'Il primo disponibile per questo orario' : '', passo: conModifica('professionista'), aria: 'il professionista' }),
    rigaRecap({ ic: sede?.online ? 'video' : 'pin', label: 'Sede', val: sede?.online ? 'Videochiamata' : `${esc(sede?.nome || '')}`, sub: sede?.online ? sede.indirizzo : `${esc(indirizzo(sede))} · <a href="${linkMaps(sede)}" target="_blank" rel="noopener">Apri in Maps</a>`, passo: conModifica('sede'), aria: 'la sede' }),
    rigaRecap({ ic: 'calendar', label: 'Data e ora', val: `<span class="tnum">${maiuscola(dataLungaAnno(d.data))}, ${d.ora}</span>`, sub: traQuanto(d.data) ? maiuscola(traQuanto(d.data)) : '', passo: modificabile && 'orario', aria: 'data e ora' }),
    rigaRecap({ ic: d.perChi === 'altro' ? 'users' : 'user', label: 'Paziente', val: nomePaz, sub: d.perChi === 'altro' ? `Prenotata da ${esc(d.dati.nome)} ${esc(d.dati.cognome)}` : '', passo: modificabile && passoAttivo('perchi') && 'perchi', aria: 'il paziente' }),
    rigaContatti,
  ].join('');
}
// Diventa una richiesta da confermare: nuovi pazienti in "approvazione"; senza verifica, TUTTE in "approvazione".
function richiestaDaApprovare() {
  return state.catalogo && state.catalogo.modalita === 'approvazione' && !pazienteRiconosciuto();
}
function sRiepilogo() {
  const d = state.draft; const scaduta = d.riserva && d.riserva.scaduta;
  const richiesta = richiestaDaApprovare(); const senza = !verificaRichiesta();
  const r = recapiti(d); const dove = doveScriviamo(d.dati.canale, r.cellulare, r.email);
  const notaRichiesta = senza
    ? `<p><strong>Lo studio conferma ogni prenotazione online.</strong> La tua sarà una richiesta: la conferma arriva entro ${cfg().regole.preavviso_modifica_ore} ore.</p>`
    : `<p><strong>Sei un nuovo paziente dello studio.</strong> La tua sarà una richiesta: lo studio la conferma entro ${cfg().regole.preavviso_modifica_ore} ore e intanto l'orario resta tenuto per te.</p>`;
  const notaStudio = cfg().testi.nota_riepilogo;
  const o = state.ui;
  return {
    cta: senza
      ? { label: richiesta ? 'Invia la richiesta' : 'Conferma la prenotazione', azione: azioni.inviaPrenotazione }
      : { label: richiesta ? 'Invia la richiesta' : 'Conferma la prenotazione', azione: azioni.inviaPrenotazione, pronto: riservaValida(), avviso: scaduta ? 'Il tempo per confermare è scaduto. Tocca "Riprova con lo stesso orario".' : 'Tocca "Riprova con lo stesso orario" per tenere l\'orario.' },
    html: `${titolo('Controlla e conferma', 'Tocca Modifica su una riga per cambiarla: il resto non si perde.')}
      ${bannerRiserva()}
      <div class="card recap">${righeRiepilogo(d, true)}</div>
      ${richiesta ? `<div class="mt16">${nota('info', 'info', notaRichiesta)}</div>` : ''}
      ${notaStudio ? `<div class="mt16">${nota('info', 'info', `<p>${esc(notaStudio)}</p>`)}</div>` : ''}
      <ul class="policy">
        <li>${icon('calendar')}<span>Puoi spostare o annullare gratis fino a ${cfg().regole.preavviso_modifica_ore} ore prima.</span></li>
        <li>${icon('euro')}<span>Paghi in studio, il giorno della visita.</span></li>
        ${dove ? `<li>${icon('chat')}<span>Ti mandiamo conferma e promemoria ${dove}.</span></li>` : ''}
      </ul>
      ${senza ? `<div class="trap" aria-hidden="true"><label for="rp-sito">Non compilare questo campo</label><input id="rp-sito" tabindex="-1" autocomplete="off"></div>
      <p class="antibot mt16 ${o.antibotConferma ? 'is-on' : ''}" role="status">${o.antibotConferma ? '<span class="spin dark" aria-hidden="true"></span>Verifica di sicurezza in corso' : `${icon('shield')}Protetto da una verifica automatica anti bot, senza puzzle.`}</p>` : ''}`,
  };
}

// ----- Orario appena preso (409 alla conferma finale) -----
function sPreso() {
  const alt = state.ui.alternative; const d = state.draft;
  const scelta = state.ui.altScelta;
  return {
    cta: { label: 'Scegli questo orario', azione: azioni.scegliAlternativa, pronto: alt.length > 0 },
    html: `<div class="mark wait" style="margin-left:0">${icon('clock')}</div>
      ${titolo('Questo orario è appena stato preso', `Mentre confermavi, un'altra persona ha prenotato ${dataLunga(d.data)} alle ${d.ora}. Ecco gli orari più vicini.`)}
      <fieldset><legend class="sr-only">Orari più vicini</legend><div class="rcards">
        ${alt.map((a, i) => `<label class="rcard ${scelta === i ? 'is-selected' : ''}" for="alt-${i}">
          <input type="radio" name="alt" id="alt-${i}" value="${i}" data-change="alternativa" ${scelta === i ? 'checked' : ''}>
          <span class="icon-tile ${scelta === i ? '' : 'neutral'}">${icon('calendar')}</span>
          <span class="opt-body"><span class="opt-title tnum">${maiuscola(dataLunga(a.data))}, ${a.ora}</span><span class="opt-sub">${nomePro(PROFESSIONISTI[a.professionista])} · ${(SEDI[a.sede]?.nome || '')}</span></span>
          <span class="rcard-mark" aria-hidden="true"></span></label>`).join('')}
      </div></fieldset>
      <button type="button" class="btn-link mt12" data-action="tutti-orari">Vedi tutti gli orari</button>`,
  };
}

// ----- Prestazione già prenotata (1 per prestazione) -----
function sDoppione() {
  const p = state.ui.doppione; const pro = PROFESSIONISTI[p.professionista];
  return {
    html: `<div class="mark neutral" style="margin-left:0">${icon('calendar-check')}</div>
      ${titolo('Hai già un appuntamento per questa prestazione', `${maiuscola(dataLunga(p.data))} alle ${p.ora} con ${conArticolo(pro)}. Vuoi spostarlo invece di prenotarne un altro?`)}
      <div class="btn-row mt8">
        ${verificato()
          ? `<button type="button" class="btn btn-primary btn-lg btn-block" data-action="sposta-esistente" data-ref="${p.ref}">Sposta quello esistente</button>`
          : `<a class="btn btn-primary btn-lg btn-block" href="${esc(STUDIO.telHref)}">${icon('phone')}Chiama lo ${esc(STUDIO.telefono)}</a>`}
        <button type="button" class="btn btn-secondary btn-block" data-action="ricomincia">Prenota un'altra prestazione</button>
      </div>
      ${verificato() ? '' : '<p class="micro on-bg mt16">Per spostarlo puoi anche usare il link nel messaggio di conferma.</p>'}`,
  };
}

// ----- Limite di prenotazioni raggiunto -----
function sLimite() {
  const max = (state.catalogo && state.catalogo.regole.max_attive) || 2;
  return {
    html: `<div class="mark neutral" style="margin-left:0">${icon('info')}</div>
      ${titolo(`Hai già ${max} prenotazioni in arrivo`, `Con questo studio puoi avere al massimo ${max} prenotazioni online in arrivo. Per prenotarne un'altra sposta o annulla una di quelle che hai, oppure chiama lo studio.`)}
      <div class="btn-row mt8">
        ${conMiePrenotazioni() ? '<button type="button" class="btn btn-primary btn-lg btn-block" data-action="apri-mie">Vedi le tue prenotazioni</button>' : ''}
        <a class="btn ${conMiePrenotazioni() ? 'btn-secondary' : 'btn-primary btn-lg'} btn-block" href="${esc(STUDIO.telHref)}">${icon('phone')}Chiama lo ${esc(STUDIO.telefono)}</a>
      </div>`,
  };
}

// ----- Modalità a: numero non in archivio -----
function sNonAmmesso() {
  return {
    html: `<div class="mark neutral" style="margin-left:0">${icon('phone')}</div>
      ${titolo('Per la prima visita chiama lo studio', `Le prenotazioni online del ${esc(STUDIO.nome)} sono per chi è già paziente. Il numero +39 ${formattaCell(state.sessione.cellulare || '')} non risulta in archivio: chiama e la segreteria ti trova l'orario.`)}
      ${contattiStudio()}
      <button type="button" class="btn-link mt12" data-action="altro-numero">Sei già paziente? Usa un altro numero</button>`,
  };
}

// ----- S10a / S10b Esito -----
function sEsito() {
  const p = state.draft.esito; const pres = prestazione(p.prestazione); const pro = PROFESSIONISTI[p.professionista]; const sede = SEDI[p.sede];
  const attesa = p.stato === 'in_attesa';
  const nomePaz = `${esc(p.paziente.nome)} ${esc(p.paziente.cognome)}`;
  const dove = p.notificata ? doveScriviamo(p.canale, p.cellulare, p.email) : null;
  // Senza verifica il dispositivo non è riconosciuto: si gestisce dal link nei messaggi, da "Le mie prenotazioni"
  // (se lo studio ha almeno un metodo di verifica) oppure per telefono. Il codice va conservato.
  const gestibile = verificato();
  const testa = attesa
    ? `<div class="mark wait">${icon('hourglass')}</div>${titolo('Richiesta inviata', `Lo studio la conferma di solito entro poche ore, al massimo entro 24.${dove ? ` Ti scriviamo ${dove}.` : ' Conserva il codice prenotazione.'}`)}`
    : `<div class="mark ok"><svg viewBox="0 0 24 24" aria-hidden="true"><path class="draw" d="M20 6 9 17l-5-5"/></svg></div>
       ${titolo(`Ci vediamo ${dataLunga(p.data)} alle ${p.ora}`, `La prenotazione è confermata.${dove ? ` Ti abbiamo mandato una conferma ${dove}.` : ' Conserva il codice prenotazione.'}`)}`;
  const timeline = attesa ? `<ol class="timeline" aria-label="Stato della richiesta">
      <li class="tl-step done"><span class="tl-dot">${icon('check')}</span><div><p class="tl-title">Richiesta inviata</p><p class="tl-sub">Oggi alle ${oraDi(OGGI.getHours() * 60 + OGGI.getMinutes())}</p></div></li>
      <li class="tl-step now"><span class="tl-dot">${icon('clock')}</span><div><p class="tl-title">Lo studio la conferma</p><p class="tl-sub">Di solito entro poche ore</p></div></li>
      <li class="tl-step"><span class="tl-dot">${icon('calendar-check')}</span><div><p class="tl-title">Ricevi la conferma</p><p class="tl-sub">${dove ? maiuscola(dove) : 'Chiedi allo studio con il tuo codice'}</p></div></li>
    </ol>
    <div class="mt20" style="text-align:left">${nota('warn', 'info', `<p>L'orario di <strong>${dataLunga(p.data)} alle ${p.ora}</strong> è tenuto per te ma <strong>non è ancora confermato</strong>.</p>`)}</div>` : '';
  return {
    html: `<div class="result">${testa}${timeline}</div>
      <div class="card ticket">
        <div class="ticket-head"><div><p class="micro">Codice prenotazione</p><p class="code">${p.ref}</p>${gestibile ? '' : '<p class="micro">Conservalo: ti serve se chiami lo studio.</p>'}</div>${badgeStato(p.stato)}</div>
        <div class="recap-row">${icon('calendar')}<div class="recap-main"><p class="recap-label">Data e ora</p><p class="recap-val tnum">${maiuscola(dataLungaAnno(p.data))}, ${p.ora}</p><p class="recap-sub">${p.durata || pres?.durata} minuti · ${maiuscola(traQuanto(p.data))}</p></div></div>
        ${pres ? `<div class="recap-row">${icon('steth')}<div class="recap-main"><p class="recap-label">Prestazione</p><p class="recap-val">${esc(pres?.nome || '')}</p><p class="recap-sub">${nomePro(pro)}</p></div></div>` : ''}
        ${!pres && pro ? rigaRecap({ic:'user',label:'Professionista',val:nomePro(pro)}) : ''}
        ${sede ? `<div class="recap-row">${icon(sede?.online ? 'video' : 'pin')}<div class="recap-main"><p class="recap-label">Sede</p><p class="recap-val">${sede?.online ? 'Videochiamata' : esc(sede?.nome || '')}</p><p class="recap-sub">${esc(indirizzo(sede))}</p></div></div>` : ''}
        <div class="recap-row">${icon('user')}<div class="recap-main"><p class="recap-label">Paziente</p><p class="recap-val">${nomePaz}</p>${p.prenotante ? `<p class="recap-sub">Prenotata da ${esc(p.prenotante.nome)} ${esc(p.prenotante.cognome)}</p>` : ''}</div></div>
      </div>
      <div class="actions three">
        ${menuCalendario(p, 'esito')}
        ${!sede || sede.online ? '' : `<a class="btn btn-secondary btn-block" href="${linkMaps(sede)}" target="_blank" rel="noopener">${icon('nav')}Come arrivare</a>`}
        ${gestibile || conMiePrenotazioni() ? `<button type="button" class="btn btn-secondary btn-block" data-action="${gestibile ? 'gestisci' : 'apri-mie'}" data-ref="${p.ref}">${attesa ? 'Gestisci la richiesta' : 'Gestisci la prenotazione'}</button>` : ''}
      </div>
      ${attesa && gestibile ? `<p class="center mt16"><button type="button" class="btn-link danger" data-action="annulla" data-ref="${p.ref}">Annulla la richiesta</button></p>` : ''}
      ${gestibile ? '' : `<p class="micro on-bg center mt16">Per spostare o annullare ${dove ? 'usa il link che ti abbiamo mandato, oppure chiama' : 'chiama'} lo studio allo <a href="${esc(STUDIO.telHref)}">${esc(STUDIO.telefono)}</a>.</p>`}
      ${standalone() ? '' : `<div class="card install-card"><span class="app-tile" aria-hidden="true">SC</span>
        <div class="opt-body"><p class="opt-title">L'app dello studio</p><p class="opt-sub">${conMiePrenotazioni() ? 'Ritrovi le tue prenotazioni' : 'Prenoti di nuovo'} con un tocco dalla schermata Home.</p></div>
        <button type="button" class="btn btn-secondary" data-action="installa">Installa l'app</button></div>`}`,
  };
}
// "Aggiungi al calendario": file .ics vero, Google, Outlook. Nessuna prestazione nel titolo.
function menuCalendario(p, dove, primario = true) {
  const aperto = state.ui.menuAperto === `${dove}-${p.ref}`;
  const id = `cal-menu-${dove}`;
  return `<div>
    <button type="button" class="btn ${primario ? 'btn-primary btn-lg' : 'btn-secondary'} btn-block" id="cal-btn-${dove}" data-action="menu-calendario" data-menu="${dove}-${p.ref}" aria-expanded="${aperto}" aria-controls="${id}">
      ${icon('calendar-plus')}<span>Aggiungi al calendario</span>${icon('chev-down', 'ico-sm')}</button>
    <div class="menu" id="${id}" ${aperto ? '' : 'hidden'}>
      <button type="button" data-action="ics" data-ref="${p.ref}">${icon('download')}<span>Apple Calendar, Outlook e altri<small>Scarica il file .ics</small></span></button>
      <a href="${linkGoogle(p)}" target="_blank" rel="noopener">${icon('ext')}<span>Google Calendar<small>Si apre in una nuova scheda</small></span></a>
      <a href="${linkOutlook(p)}" target="_blank" rel="noopener">${icon('ext')}<span>Outlook.com<small>Si apre in una nuova scheda</small></span></a>
    </div></div>`;
}

// ----- S11 Le mie prenotazioni -----
function caricaMie() {
  if (state.cache.mieInCorso) return;
  state.cache.mieInCorso = true;
  api.mie().then(r => { state.cache.mie = r.prenotazioni; })
    .catch(e => { if (e.status === 401) { state.sessione = { cellulare: null, paziente: null }; } else erroreRete(e); })
    .finally(() => { state.cache.mieInCorso = false; ridisegnaSe(['mie', 'dettaglio', 'sposta', 'sposta-conferma', 'annullata']); });
}
function prenotazioneCorrente() {
  if (state.cache.mie === null) { caricaMie(); return null; }
  return state.cache.mie.find(p => p.ref === state.ui.ref) || null;
}
const cronologico = (a, b) => (a.data + pad(minutiDi(a.ora))).localeCompare(b.data + pad(minutiDi(b.ora)));
function gruppiMie(lista) {
  return {
    prossime: lista.filter(p => (p.stato === 'confermata' || p.stato === 'in_attesa') && p.data >= OGGI_ISO).sort(cronologico),
    passate: lista.filter(p => p.stato === 'svolta' || ((p.stato === 'confermata') && p.data < OGGI_ISO)).sort(cronologico).reverse(),
    annullate: lista.filter(p => p.stato === 'annullata' || p.stato === 'rifiutata').sort(cronologico).reverse(),
  };
}
function cardBooking(p, tab) {
  const pres = prestazione(p.prestazione); const pro = PROFESSIONISTI[p.professionista]; const sede = SEDI[p.sede];
  const d = daIso(p.data);
  return `<li><button type="button" class="card bk" id="bk-${p.ref}" data-action="gestisci" data-ref="${p.ref}">
      <span class="date-tile ${tab === 'prossime' ? '' : 'past'}" aria-hidden="true"><b>${d.getDate()}</b><span>${MESI_BREVI[d.getMonth()]}</span></span>
      <span class="bk-body"><span class="bk-when">${maiuscola(dataLunga(p.data))}, ${p.ora}</span>
        <span class="bk-what">${esc(pres?.nome || '')} · ${nomeProBreve(pro)} · ${sede?.online ? 'Video' : esc(sede?.nome || '')}</span>
        ${p.prenotante ? `<span class="bk-what">Per ${esc(p.paziente.nome)} ${esc(p.paziente.cognome)}</span>` : ''}
        ${badgeStato(p.stato)}</span>
      <span class="opt-end">${icon('chev-right')}</span></button></li>`;
}
function sMie() {
  const intest = titolo('Le tue prenotazioni', state.sessione.cellulare ? `Accesso con il numero <span class="tnum">+39 ${formattaCell(state.sessione.cellulare)}</span>.` : '');
  if (state.cache.mie === null) { caricaMie(); return { html: intest + scheletro(3), laterale: 'studio' }; }
  const g = gruppiMie(state.cache.mie); const tab = state.ui.mieTab;
  const tabs = [['prossime', 'Prossime'], ['passate', 'Passate'], ['annullate', 'Annullate']];
  const lista = g[tab];
  const vuoto = {
    prossime: `<div class="card empty"><div class="mark ok">${icon('calendar')}</div><p class="h3">Nessuna prenotazione in arrivo</p><p class="micro">Quando prenoti, la trovi qui.</p><button type="button" class="btn btn-primary mt20" data-action="ricomincia">Prenota una visita</button></div>`,
    passate: '<div class="card empty"><p class="h3">Nessuna visita passata</p><p class="micro">Qui trovi le visite già fatte, per prenotarle di nuovo.</p></div>',
    annullate: '<div class="card empty"><p class="h3">Nessuna prenotazione annullata</p></div>',
  }[tab];
  return {
    laterale: 'studio',
    html: `${intest}
      <div class="tabs" role="tablist" aria-label="Prenotazioni" data-roving="tab">${tabs.map(([id, nome]) => `<button type="button" role="tab" class="tab" id="tab-${id}" data-action="tab-mie" data-tab="${id}"
        aria-selected="${tab === id}" aria-controls="pannello-mie" tabindex="${tab === id ? 0 : -1}">${nome}${g[id].length ? ` <span class="count">${g[id].length}</span>` : ''}</button>`).join('')}</div>
      <div role="tabpanel" id="pannello-mie" aria-labelledby="tab-${tab}">
        ${lista.length ? `<ul class="stack">${lista.map(p => cardBooking(p, tab)).join('')}</ul>` : vuoto}
      </div>
      <div class="btn-row mt24">
        ${tab !== 'prossime' || lista.length ? '<button type="button" class="btn btn-secondary btn-block" data-action="ricomincia">Prenota una nuova visita</button>' : ''}
        <button type="button" class="btn-link" data-action="esci" style="justify-self:center">${icon('logout', 'ico-sm')}Esci da questo dispositivo</button>
      </div>`,
  };
}

// ----- S12 Dettaglio -----
function sDettaglio() {
  const p = prenotazioneCorrente();
  if (!p) return { html: titolo('La tua prenotazione') + scheletro(2), laterale: 'studio' };
  const pres = prestazione(p.prestazione); const pro = PROFESSIONISTI[p.professionista]; const sede = SEDI[p.sede];
  const attiva = p.stato === 'confermata' || p.stato === 'in_attesa';
  const futura = p.data >= OGGI_ISO;
  const ore = (daIso(p.data).getTime() + minutiDi(p.ora) * 60000 - OGGI.getTime()) / 3600000;
  const tardi = attiva && ore < cfg().regole.preavviso_modifica_ore;
  const motivoTardi = `Mancano meno di ${cfg().regole.preavviso_modifica_ore} ore: per spostare o annullare chiama lo studio.`;
  const azione = (ic, label, act, extra = '') => `<button type="button" class="act" data-action="${act}" data-ref="${p.ref}" ${tardi ? `aria-disabled="true" aria-describedby="motivo-tardi"` : ''} ${extra}>${icon(ic)}<span>${label}${tardi ? `<small>Non disponibile online</small>` : ''}</span><span class="opt-end">${icon('chev-right')}</span></button>`;
  const contattiAperti = state.ui.menuAperto === `contatti-${p.ref}`;
  return {
    laterale: 'studio',
    html: `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">${badgeStato(p.stato)}${attiva && futura ? badge(maiuscola(traQuanto(p.data)), 'neutral', 'clock') : ''}</div>
      ${titolo(`${maiuscola(dataLunga(p.data))}, ${p.ora}`, p.stato === 'in_attesa' ? 'Lo studio deve ancora confermare la richiesta. Ti scriviamo appena lo fa.' : p.stato === 'annullata' ? 'Questa prenotazione è stata annullata.' : '')}
      <div class="card recap">
        ${rigaRecap({ ic: 'steth', label: 'Prestazione', val: esc(pres?.nome || ''), sub: `${pres?.durata} minuti${pres?.prezzo == null ? '' : ' · ' + pres?.prezzo + ' €, paghi in studio'}` })}
        ${rigaRecap({ ic: 'user', label: 'Professionista', val: nomePro(pro) })}
        ${rigaRecap({ ic: sede?.online ? 'video' : 'pin', label: 'Sede', val: sede?.online ? 'Videochiamata' : (sede?.nome || ''), sub: indirizzo(sede) })}
        ${rigaRecap({ ic: 'user', label: 'Paziente', val: `${esc(p.paziente.nome)} ${esc(p.paziente.cognome)}`, sub: p.prenotante ? `Prenotata da ${esc(p.prenotante.nome)} ${esc(p.prenotante.cognome)}` : '' })}
        ${rigaRecap({ ic: 'info', label: 'Codice prenotazione', val: `<span class="code" style="font-size:17px">${p.ref}</span>` })}
      </div>
      ${attiva && futura ? `
        ${tardi ? `<div class="mt16" id="motivo-tardi">${nota('warn', 'info', `<p>${motivoTardi}</p>`)}</div>` : ''}
        <div class="card act-card mt16"><div class="act-list">
          ${cfg().regole.spostamento_consentito ? azione('move', 'Sposta l\'appuntamento', 'sposta') : ''}
          <div class="act-wrap">
            <button type="button" class="act" data-action="menu-calendario" data-menu="det-${p.ref}" aria-expanded="${state.ui.menuAperto === `det-${p.ref}`}" aria-controls="cal-menu-det" id="cal-btn-det">${icon('calendar-plus')}<span>Aggiungi al calendario</span><span class="opt-end">${icon('chev-down')}</span></button>
            <div class="menu" id="cal-menu-det" ${state.ui.menuAperto === `det-${p.ref}` ? '' : 'hidden'}>
              <button type="button" data-action="ics" data-ref="${p.ref}">${icon('download')}<span>Apple Calendar, Outlook e altri<small>Scarica il file .ics</small></span></button>
              <a href="${linkGoogle(p)}" target="_blank" rel="noopener">${icon('ext')}<span>Google Calendar<small>Si apre in una nuova scheda</small></span></a>
              <a href="${linkOutlook(p)}" target="_blank" rel="noopener">${icon('ext')}<span>Outlook.com<small>Si apre in una nuova scheda</small></span></a>
            </div>
          </div>
          ${!sede || sede.online ? '' : `<a class="act" href="${linkMaps(sede)}" target="_blank" rel="noopener">${icon('nav')}<span>Come arrivare<small>${esc(indirizzo(sede))}</small></span><span class="opt-end">${icon('ext', 'ico-sm')}</span></a>`}
          <div class="act-wrap">
            <button type="button" class="act" data-action="menu-contatti" data-menu="contatti-${p.ref}" aria-expanded="${contattiAperti}" aria-controls="menu-contatti" id="contatti-btn">${icon('phone')}<span>Contatta lo studio</span><span class="opt-end">${icon('chev-down')}</span></button>
            <div class="menu" id="menu-contatti" ${contattiAperti ? '' : 'hidden'}>
              <a href="${esc(STUDIO.telHref)}">${icon('phone')}<span>Chiama<small class="tnum">${esc(STUDIO.telefono)}</small></span></a>
              <a href="${esc(STUDIO.waHref)}" target="_blank" rel="noopener">${icon('chat')}<span>WhatsApp<small class="tnum">${esc(STUDIO.whatsapp)}</small></span></a>
              <a href="mailto:${esc(STUDIO.email)}">${icon('mail')}<span>Email<small>${esc(STUDIO.email)}</small></span></a>
            </div>
          </div>
        </div></div>
        <button type="button" class="btn btn-danger btn-block mt16" data-action="annulla" data-ref="${p.ref}" ${tardi ? 'aria-disabled="true" aria-describedby="motivo-tardi"' : ''}>${icon('x-circle')}${p.stato === 'in_attesa' ? 'Annulla la richiesta' : 'Annulla la prenotazione'}</button>`
      : `<button type="button" class="btn btn-primary btn-lg btn-block mt20" data-action="prenota-di-nuovo" data-ref="${p.ref}">Prenota di nuovo</button>`}`,
  };
}

// ----- S13 Sposta: conferma -----
function sSpostaConferma() {
  const p = prenotazioneCorrente(); const n = state.ui.sposta;
  if (!p || !n) return { html: scheletro(2) };
  return {
    cta: { label: 'Conferma lo spostamento', azione: azioni.confermaSposta },
    html: `${titolo('Confermi lo spostamento?', 'Se il nuovo orario non fosse più libero, il vecchio resta valido.')}
      <div class="move">
        <div class="move-box old"><p class="move-label">Da</p><p class="move-when">${maiuscola(dataLunga(p.data))}, ${p.ora}</p></div>
        <span class="move-arrow">${icon('arrow-down')}</span>
        <div class="move-box new"><p class="move-label">A</p><p class="move-when">${maiuscola(dataLunga(n.data))}, ${n.ora}</p></div>
      </div>
      <p class="micro on-bg mt16">${esc((prestazione(p.prestazione)?.nome || 'Appuntamento'))} con ${conArticolo(PROFESSIONISTI[p.professionista])}, ${SEDI[p.sede]?.online ? 'in videochiamata' : `sede di ${(SEDI[p.sede]?.nome || '')}`}. Ti mandiamo la conferma del nuovo orario.</p>`,
  };
}

// ----- S14 esito annullamento -----
function sAnnullata() {
  const p = state.ui.annullata;
  if (!p) return { html: scheletro(1) };
  return {
    laterale: 'studio',
    html: `<div class="result"><div class="mark neutral">${icon('check')}</div>
      ${titolo('Prenotazione annullata', `L'appuntamento di ${dataLunga(p.data)} alle ${p.ora} è annullato e l'orario è tornato libero. Ti abbiamo mandato una conferma. Vuoi prenotare un altro orario?`)}</div>
      <div class="btn-row mt8">
        <button type="button" class="btn btn-primary btn-lg btn-block" data-action="prenota-di-nuovo" data-ref="${p.ref}">Prenota un altro orario</button>
        <button type="button" class="btn btn-secondary btn-block" data-action="ics-annulla" data-ref="${p.ref}">${icon('calendar-x')}Togli dal calendario (.ics)</button>
        <button type="button" class="btn-link" style="justify-self:center" data-action="apri-mie">Torna alle tue prenotazioni</button>
      </div>`,
  };
}

// ----- S15 Chiedi allo studio -----
function erroriContatto() {
  const c = state.ui.contatto; const e = {};
  if (c.nome.trim().length < 3) e['ct-nome'] = 'Scrivi nome e cognome.';
  const cell = validaCell(c.cellulare); if (cell) e['ct-cell'] = cell;
  if (c.testo.trim().length < 5) e['ct-testo'] = 'Scrivi in breve cosa ti serve.';
  if (!c.privacy) e['ct-privacy'] = 'Per continuare conferma di aver letto l\'informativa privacy.';
  return e;
}
function validaCell(v) {
  const c = normalizzaCell(v);
  if (!c) return 'Scrivi il tuo numero di cellulare.';
  if (!/^3\d{8,9}$/.test(c)) return 'Scrivi un cellulare italiano, ad esempio 347 123 4567.';
  return null;
}
function sContatto() {
  const c = state.ui.contatto; const er = state.ui.tentato.contatto ? erroriContatto() : state.ui.errori;
  return {
    laterale: 'studio',
    cta: { label: 'Invia la richiesta', azione: azioni.inviaContatto },
    html: `${titolo('Chiedi allo studio', 'Scrivi in breve cosa ti serve: la segreteria ti richiama e ti consiglia la prestazione giusta.')}
      <form novalidate data-submit="contatto" class="card">
        ${campo({ id: 'ct-nome', label: 'Nome e cognome', valore: c.nome, autocomplete: 'name', input: 'contatto.nome', errore: er['ct-nome'] })}
        ${campo({ id: 'ct-cell', label: 'Cellulare', tipo: 'tel', valore: c.cellulare, autocomplete: 'tel-national', inputmode: 'tel', placeholder: '347 123 4567', input: 'contatto.cellulare', errore: er['ct-cell'] })}
        <div class="field ${er['ct-testo'] ? 'has-error' : ''}">
          <label class="label" for="ct-testo">Cosa ti serve?</label>
          <p class="hint" id="ct-testo-aiuto">Bastano poche parole, ad esempio "controllo di un neo". Non serve scrivere dettagli sulla tua salute.</p>
          <textarea class="textarea" id="ct-testo" rows="3" maxlength="300" data-input="contatto.testo" data-valida="contatto.testo" aria-required="true" aria-describedby="ct-testo-aiuto${er['ct-testo'] ? ' ct-testo-err' : ''}" ${er['ct-testo'] ? 'aria-invalid="true"' : ''}>${esc(c.testo)}</textarea>
          ${er['ct-testo'] ? `<p class="error" id="ct-testo-err">${icon('alert')}<span>${er['ct-testo']}</span></p>` : ''}
        </div>
        ${casella({ id: 'ct-privacy', change: 'contatto-privacy', checked: c.privacy, errore: er['ct-privacy'], label: `Ho letto l'<a href="#privacy" data-action="nota-legale">informativa privacy</a> del ${esc(STUDIO.nome)}.` })}
        <div class="trap" aria-hidden="true"><label for="ct-sito">Non compilare questo campo</label><input id="ct-sito" tabindex="-1" autocomplete="off"></div>
        <button type="submit" hidden></button>
      </form>`,
  };
}
function sContattoOk() {
  return {
    laterale: 'studio',
    html: `<div class="result"><div class="mark ok"><svg viewBox="0 0 24 24" aria-hidden="true"><path class="draw" d="M20 6 9 17l-5-5"/></svg></div>
      ${titolo('Lo studio ti ricontatta', `Di solito entro un giorno lavorativo, al numero <span class="tnum">+39 ${formattaCell(state.ui.contattoInviato || '')}</span>.`)}</div>
      <button type="button" class="btn btn-secondary btn-block mt8" data-action="ricomincia">Torna alle prestazioni</button>`,
  };
}

// Mappa delle schermate. passo: segmento della barra di avanzamento; laterale: contenuto della colonna destra su desktop.
const SCHERMATE = {
  benvenuto:         { render: sBenvenuto, indietro: false },
  prestazione:       { render: sPrestazione },
  professionista:    { render: sProfessionista },
  sede:              { render: sSede },
  orario:            { render: sOrario },
  perchi:            { render: sPerChi },
  telefono:          { render: sTelefono },
  codice:            { render: sCodice },
  dati:              { render: sDati },
  riepilogo:         { render: sRiepilogo },
  preso:             { render: sPreso },
  doppione:          { render: sDoppione, laterale: 'studio' },
  limite:            { render: sLimite, laterale: 'studio' },
  nonammesso:        { render: sNonAmmesso, laterale: 'studio' },
  esito:             { render: sEsito, indietro: false, laterale: 'studio' },
  'mie-accesso':     { render: sTelefono, laterale: 'studio' },
  'mie-codice':      { render: sCodice, laterale: 'studio' },
  mie:               { render: sMie },
  dettaglio:         { render: sDettaglio },
  sposta:            { render: sOrario, laterale: 'sposta' },
  'sposta-conferma': { render: sSpostaConferma, laterale: 'sposta' },
  annullata:         { render: sAnnullata },
  contatto:          { render: sContatto },
  'contatto-ok':     { render: sContattoOk, indietro: false },
};


/* ==========================================================================
   Render generale: schermata, barra di avanzamento, CTA, colonna destra
   ========================================================================== */

const $ = sel => document.querySelector(sel);
let ctaCorrente = null;

function render({ nuova = false } = {}) {
  let s = state.ui.screen;
  const redir = guardia(s);
  if (redir && redir !== s) { vai(redir, { replace: true }); return; }
  const def = SCHERMATE[s] || SCHERMATE.benvenuto;

  // Conserva focus e scroll della striscia tra un render e l'altro.
  const attivo = document.activeElement;
  const focusId = !nuova && attivo && attivo.id && $('#screen').contains(attivo) ? attivo.id : null;
  const zonaCta = !nuova && attivo && attivo.dataset && attivo.dataset.action === 'cta' ? attivo.closest('#bar,#side,#cta-inline') : null;
  const stripScroll = $('.strip') ? $('.strip').scrollLeft : null;

  const out = def.render() || {};
  $('#screen').innerHTML = `<div class="screen ${nuova ? 'enter' : ''}">${out.html || ''}</div>`;

  // Barra di avanzamento (solo nel flusso di prenotazione): segmenti dai passi davvero mostrati.
  const nelFlussoPrenota = SCHERMATE_FLUSSO.includes(s) || s === 'preso';
  const segs = nelFlussoPrenota ? segmenti() : [];
  const passo = nelFlussoPrenota ? segmentoCorrente(segs, s) : 0;
  $('#progresso').innerHTML = passo ? `<div class="progress"><div class="progress-bar" aria-hidden="true">${segs.map((_, i) => `<span class="seg ${i + 1 < passo ? 'done' : i + 1 === passo ? 'now' : ''}"></span>`).join('')}</div>
      <p class="progress-label">Passo ${passo} di ${segs.length}: ${segs[passo - 1].label}</p></div>` : '';

  // Testata
  disegnaTestata();
  const indietroOk = def.indietro !== false && s !== 'benvenuto';
  $('#indietro').hidden = !indietroOk;
  $('#topbar').classList.toggle('has-back', indietroOk);
  const inMie = ['mie', 'mie-accesso', 'mie-codice', 'dettaglio'].includes(s);
  $('#mie-btn').hidden = !conMiePrenotazioni() && !inMie;   // senza metodi di verifica "Le mie prenotazioni" sparisce
  $('#mie-btn').toggleAttribute('aria-current', inMie);
  if (inMie) $('#mie-btn').setAttribute('aria-current', 'page');

  disegnaCta(out.cta, !!passo);
  disegnaLaterale(out.laterale || def.laterale || (passo ? 'riepilogo' : 'studio'), out.cta, !!passo);

  const visible=cfg().comunicazioni.visibili||{};
  document.querySelectorAll('.contact-list a').forEach(a=>{const kind=a.href.startsWith('tel:')?'telefono':a.href.startsWith('mailto:')?'email':'whatsapp';a.hidden=visible[kind]===false||(kind==='whatsapp'&&!STUDIO.whatsapp);});
  document.querySelectorAll('#side .sede-list').forEach(el=>el.hidden=visible.sedi===false);
  document.title = `${titoloDocumento(s)} · ${esc(STUDIO.nome)}`;

  if (nuova) {
    window.scrollTo(0, 0);
    const h = $('#titolo');
    if (h && avviato) h.focus({ preventScroll: true }); // a ogni cambio di passo il focus va sul titolo
    if (s === 'esito') programmaInstalla();
  } else {
    if (stripScroll !== null && $('.strip')) $('.strip').scrollLeft = stripScroll;
    if (focusId && document.getElementById(focusId)) document.getElementById(focusId).focus({ preventScroll: true });
    else if (zonaCta) { const b = zonaCta.querySelector('[data-action="cta"]'); if (b) b.focus({ preventScroll: true }); }
  }
  aggiornaSelezioneStriscia();
  aggiornaDemo();
}
let avviato = false;
// Testata dello studio: con aspetto.logo_url il logo (alto 40/48 px) al posto del monogramma; altrimenti monogramma e nome.
let testataCorrente = '';
function disegnaTestata() {
  const a = cfg().aspetto;
  const sedi = sediStudio().map(s => s.nome);
  const sotto = sedi.length > 1 ? `${sedi.slice(0, -1).join(', ')} e ${sedi[sedi.length - 1]}` : sedi[0] || '';
  const testi = `<span class="brand-txt"><span class="brand-name">${esc(STUDIO.nome)}</span>${sotto ? `<span class="brand-sub">${esc(sotto)}</span>` : ''}</span>`;
  // Se il nome è scritto accanto, il logo è decorativo (alt vuoto); se il logo è da solo, l'alt è il nome dello studio.
  const html = a.logo_url
    ? `<img class="brand-logo" src="${esc(a.logo_url)}" alt="${a.mostra_nome_con_logo ? '' : esc(a.logo_alt || STUDIO.nome)}" height="48">${a.mostra_nome_con_logo ? testi : ''}`
    : `<span class="mono" aria-hidden="true">${esc(STUDIO.monogramma)}</span>${testi}`;
  if (html === testataCorrente) return;
  testataCorrente = html;
  $('#topbar .brand').innerHTML = html;
}
function titoloDocumento(s) {
  return ({ benvenuto: 'Prenota una visita', prestazione: 'Prestazione', professionista: 'Professionista', sede: 'Sede', orario: 'Data e orario', perchi: 'Per chi è la visita',
    telefono: 'Verifica che sei tu', codice: 'Verifica', dati: 'I tuoi dati', riepilogo: 'Riepilogo', esito: 'Prenotazione inviata', mie: 'Le tue prenotazioni',
    'mie-accesso': 'Le tue prenotazioni', 'mie-codice': 'Verifica', dettaglio: 'La tua prenotazione', sposta: 'Sposta', 'sposta-conferma': 'Conferma lo spostamento',
    annullata: 'Prenotazione annullata', contatto: 'Chiedi allo studio', 'contatto-ok': 'Richiesta inviata', preso: 'Orario non più libero', limite: 'Limite raggiunto',
    doppione: 'Hai già un appuntamento', nonammesso: 'Chiama lo studio' })[s] || 'Prenota una visita';
}
function aggiornaSelezioneStriscia() {
  const sel = $('.strip [aria-checked="true"]'); const strip = $('.strip');
  if (!sel || !strip) return;
  const l = sel.offsetLeft - strip.offsetLeft; const r = l + sel.offsetWidth;
  if (l < strip.scrollLeft || r > strip.scrollLeft + strip.clientWidth) strip.scrollLeft = l - 16;
}
function ridisegnaSe(schermate) { if (schermate.includes(state.ui.screen)) render(); }

function bottoneCta(cta) {
  const off = cta.pronto === false; const busy = state.ui.busy;
  return `<button type="button" class="btn btn-primary btn-lg btn-block ${busy ? 'is-busy' : ''}" data-action="cta" ${off ? 'aria-disabled="true"' : ''} ${busy ? 'aria-busy="true"' : ''}>
    <span class="spin" aria-hidden="true"></span><span class="btn-label">${cta.label}</span></button>`;
}
function disegnaCta(cta, nelFlusso) {
  ctaCorrente = cta || null;
  const bar = $('#bar');
  if (!cta) { bar.hidden = true; $('#cta-inline').innerHTML = ''; aggiornaAltezzaBarra(); return; }
  bar.hidden = false;
  $('#bar-line').textContent = nelFlusso ? rigaRiepilogo() : '';
  $('#bar-cta').innerHTML = bottoneCta(cta);
  $('#cta-inline').innerHTML = nelFlusso ? '' : bottoneCta(cta);
  aggiornaAltezzaBarra();
}
function setBusy(v) {
  state.ui.busy = v;
  document.querySelectorAll('[data-action="cta"]').forEach(b => { b.classList.toggle('is-busy', v); if (v) b.setAttribute('aria-busy', 'true'); else b.removeAttribute('aria-busy'); });
}

// Colonna destra su desktop: riepilogo che si riempie (flusso) oppure scheda dello studio.
function disegnaLaterale(tipo, cta, nelFlusso) {
  const side = $('#side');
  if (tipo === 'riepilogo') {
    const d = state.draft; const pres = prestazione(d.prestazione); const pro = PROFESSIONISTI[d.proAssegnato || d.professionista]; const sede = SEDI[sedeDi(d)];
    const riga = (ic, label, val, sub) => `<div class="sum-row ${val ? '' : 'is-empty'}">${icon(ic)}<div><p class="sum-label">${label}</p><p class="sum-val">${val || 'Da scegliere'}</p>${val && sub ? `<p class="sum-sub">${sub}</p>` : ''}</div></div>`;
    const perChi = verificato() || d.perChi === 'altro' || d.dati.nome
      ? riga(d.perChi === 'altro' ? 'users' : 'user', 'Paziente', d.perChi === 'altro' ? (d.assistito.nome ? `${esc(d.assistito.nome)} ${esc(d.assistito.cognome)}` : 'Un\'altra persona') : (d.dati.nome ? `${esc(d.dati.nome)} ${esc(d.dati.cognome)}` : 'Tu'))
      : '';
    side.innerHTML = `<section class="summary" aria-labelledby="sum-t">
      <h2 class="summary-title" id="sum-t">La tua prenotazione</h2>
      <div class="sum-list">
        ${riga('steth', 'Prestazione', pres && esc(pres?.nome || ''), pres && `${pres?.durata} minuti · ${pres?.prezzo == null ? '' : pres?.prezzo + ' €'}`)}
        ${riga('user', 'Professionista', pro ? nomePro(pro) : d.professionista === 'primo' ? 'Il primo disponibile' : null)}
        ${riga(sede && sede?.online ? 'video' : 'pin', 'Sede', sede && (sede?.online ? 'Videochiamata' : sede.nome), sede && !sede?.online && indirizzo(sede))}
        ${riga('calendar', 'Quando', d.data && d.ora && `<span class="tnum">${maiuscola(dataLunga(d.data))}, ${d.ora}</span>`, d.data && d.ora && maiuscola(traQuanto(d.data)))}
        ${perChi}
      </div>
      ${state.ui.screen !== 'riepilogo' && state.ui.screen !== 'dati' && state.ui.screen !== 'orario' ? bannerRiserva(true) : ''}
      <div class="side-cta">${cta ? bottoneCta(cta) : ''}</div>
      ${rassicurazioni()}
    </section>
    <p class="side-help">Serve aiuto? Chiama lo <a href="${esc(STUDIO.telHref)}">${esc(STUDIO.telefono)}</a></p>`;
  } else if (tipo === 'sposta') {
    const p = prenotazioneCorrente();
    side.innerHTML = p ? `<section class="summary" aria-labelledby="sum-t"><h2 class="summary-title" id="sum-t">Stai spostando</h2>
      <div class="sum-list">
        <div class="sum-row">${icon('calendar')}<div><p class="sum-label">Orario attuale</p><p class="sum-val tnum">${maiuscola(dataLunga(p.data))}, ${p.ora}</p></div></div>
        <div class="sum-row ${state.ui.sposta ? '' : 'is-empty'}">${icon('move')}<div><p class="sum-label">Nuovo orario</p><p class="sum-val tnum">${state.ui.sposta ? `${maiuscola(dataLunga(state.ui.sposta.data))}, ${state.ui.sposta.ora}` : 'Da scegliere'}</p></div></div>
        <div class="sum-row">${icon('steth')}<div><p class="sum-label">Prestazione</p><p class="sum-val">${esc((prestazione(p.prestazione)?.nome || 'Appuntamento'))}</p><p class="sum-sub">${nomePro(PROFESSIONISTI[p.professionista])}</p></div></div>
      </div><div class="side-cta">${cta ? bottoneCta(cta) : ''}</div></section>` : '';
    $('#cta-inline').innerHTML = '';
  } else {
    side.innerHTML = `<section class="summary" aria-labelledby="studio-t">
      <h2 class="summary-title" id="studio-t">${esc(STUDIO.nome)}</h2>
      <ul class="sede-list">${sediStudio().map(s => `<li>${icon('pin')}<span><strong>${esc(s.nome)}</strong>${esc(indirizzo(s))}</span></li>`).join('')}</ul>
      <div class="divider"></div>
      <p class="sum-label">Contatti della segreteria</p>
      <div class="contact-list">
        <a href="${esc(STUDIO.telHref)}">${icon('phone')}<span class="tnum">${esc(STUDIO.telefono)}</span></a>
        <a href="${esc(STUDIO.waHref)}" target="_blank" rel="noopener">${icon('chat')}<span>WhatsApp <span class="tnum">${esc(STUDIO.whatsapp)}</span></span></a>
        <a href="mailto:${esc(STUDIO.email)}">${icon('mail')}<span style="word-break:break-all">${esc(STUDIO.email)}</span></a>
      </div>
      ${rassicurazioni()}
    </section>`;
  }
}


/* ==========================================================================
   9. AZIONI
   ========================================================================== */

const azioni = {
  // --- Flusso di prenotazione ---
  async confermaOrario() {
    // Dispositivo già verificato: l'orario si riserva subito. Altrimenti nessun blocco finché il codice non è verificato.
    // Senza verifica nessuna riserva: l'orario si blocca solo alla conferma, con controllo atomico.
    if (verificaRichiesta() && verificato() && !riservaValida() && !(await azioni.prendiRiserva())) return;
    avanti('orario');
  },
  // Riserva di 10 minuti (solo con numero verificato). Gestisce tutti i "no" del server.
  async prendiRiserva(opz = {}) {
    const d = state.draft;
    setBusy(true);
    try {
      if (d.riserva && !d.riserva.scaduta) api.rilasciaRiserva(d.riserva.token).catch(() => {});
      const r = await api.riserva({ prestazione: d.prestazione, professionista: d.proAssegnato, sede: sedeDi(d), data: d.data, ora: d.ora });
      d.riserva = { token: r.token, scade: r.scade_il, scaduta: false, avvisi: {}, data: d.data, ora: d.ora, pro: d.proAssegnato, sede: sedeDi(d) };
      annuncia(`Orario riservato per te per ${(state.catalogo && state.catalogo.regole.riserva_minuti) || 10} minuti.`);
      return true;
    } catch (e) {
      gestisciErroreRiserva(e, opz);
      return false;
    } finally { setBusy(false); }
  },
  continuaPerChi() {
    state.ui.tentato.perchi = true;
    const e = erroriPerChi();
    if (Object.keys(e).length) { render(); focusPrimoErrore(e); return; }
    state.ui.tentato.perchi = false;
    avanti('perchi');
  },
  async inviaCodice(contesto) {
    const o = state.ui.otp;
    if (state.ui.busy || o.antibot) return;
    const errori = {};
    const errC = validaCell(state.ui.cellulareInput); if (errC) errori.cell = errC;
    if (o.metodo === 'email' && !RE_EMAIL.test(String(o.email).trim())) errori.email = 'Scrivi un indirizzo email completo, ad esempio nome@esempio.it.';
    if (Object.keys(errori).length) { state.ui.errori = errori; render(); focusPrimoErrore(errori); return; }
    state.ui.errori = {};
    const c = normalizzaCell(state.ui.cellulareInput);
    const trappola = ($('#sito-web') || {}).value || '';
    o.antibot = true; setBusy(true); render();
    annuncia('Verifica di sicurezza in corso');
    try {
      const token = await turnstile();
      const r = await api.otpInvia({ cellulare: c, metodo: o.metodo, email: o.email, contesto, turnstile_token: token, trappola });
      Object.assign(o, { contesto, cellulare: c, inviato: true, rimandaDa: Date.now() + 60000, errore: null, cifre: ['', '', '', '', '', ''], antibot: false,
        messaggio: r.messaggio || '', waLink: r.wa_link || '', waAperto: false, scade: Date.now() + 600000 });
      setBusy(false);
      vai(contesto === 'mie' ? 'mie-codice' : 'codice');
      annuncia(o.metodo === 'email' ? `Ti abbiamo mandato il codice a ${o.email}.` : o.metodo === 'sms' ? `Ti abbiamo mandato un SMS al ${formattaCell(c)}.` : 'Ora manda il messaggio già scritto su WhatsApp.');
      return;
    } catch (e) {
      o.antibot = false;
      if (e.status === 422 && (e.body.errore === 'tetto_sms' || e.body.errore === 'metodo_non_attivo')) {
        // Tetto giornaliero degli SMS raggiunto: il metodo sparisce fino a domani, si sceglie un altro modo.
        await ricaricaCatalogo();
        state.ui.errori = { cell: 'Oggi non possiamo mandare altri SMS di verifica. Scegli un altro modo per verificare.' };
      } else if (e.status === 429) state.ui.errori = { cell: 'Hai chiesto troppi codici per questo numero. Riprova tra un\'ora oppure chiama lo studio.' };
      else if (e.status === 423) state.ui.errori = { cell: 'Per sicurezza i tentativi per questo numero sono bloccati per 15 minuti.' };
      else if (e.status === 422 && e.body.errore === 'email_non_valida') state.ui.errori = { email: 'Scrivi un indirizzo email completo, ad esempio nome@esempio.it.' };
      else if (e.status === 422 && e.body.errore === 'email_usa_e_getta') state.ui.errori = { email: 'Questo indirizzo email non è accettato. Usa la tua email personale.' };
      else if (e.status === 422) state.ui.errori = { cell: 'Scrivi un cellulare italiano, ad esempio 347 123 4567.' };
      else erroreRete(e);
      setBusy(false); render(); focusPrimoErrore(state.ui.errori);
    }
  },
  async rimandaCodice() {
    const o = state.ui.otp;
    try {
      setBusy(true);
      const token = await turnstile();
      await api.otpInvia({ cellulare: o.cellulare, metodo: o.metodo, email: o.email, contesto: o.contesto, turnstile_token: token });
      Object.assign(o, { rimandaDa: Date.now() + 60000, errore: null, cifre: ['', '', '', '', '', ''] });
      toast(o.metodo === 'sms' ? 'Ti abbiamo mandato un nuovo SMS.' : 'Ti abbiamo mandato un nuovo codice.');
    } catch (e) {
      if (e.status === 429) { o.errore = 'Hai chiesto troppi codici per questo numero. Riprova tra un\'ora oppure chiama lo studio.'; o.rimandaDa = Date.now() + 3600000; }
      else if (e.status === 423) { o.bloccatoFino = e.body.bloccato_fino; o.errore = 'Per sicurezza abbiamo bloccato i tentativi per 15 minuti.'; }
      else erroreRete(e);
    } finally { setBusy(false); render(); const b = $('#otp-0'); if (b && !b.disabled) b.focus(); }
  },
  async verificaCodice() {
    const o = state.ui.otp; const codice = o.cifre.join('');
    if (codice.length < 6 || o.inVerifica) return;
    o.inVerifica = true; setBusy(true);
    const zona = $('.otp'); if (zona) zona.setAttribute('aria-busy', 'true');
    try {
      const r = await api.otpVerifica({ cellulare: o.cellulare, codice });
      o.inVerifica = false;
      await azioni.verificaRiuscita(r);
    } catch (e) {
      o.inVerifica = false; setBusy(false);
      o.cifre = ['', '', '', '', '', ''];
      if (e.status === 422) { const n = e.body.tentativi_rimasti; o.errore = `Codice non corretto. ${n === 1 ? 'Ti resta 1 tentativo.' : `Ti restano ${n} tentativi.`}`; }
      else if (e.status === 423) { o.bloccatoFino = e.body.bloccato_fino; o.errore = 'Per sicurezza abbiamo bloccato i tentativi per 15 minuti.'; }
      else if (e.status === 410) { o.errore = 'Il codice è scaduto. Chiedine uno nuovo.'; o.rimandaDa = 0; }
      else erroreRete(e);
      render();
      const b = $('#otp-0'); if (b && !b.disabled) b.focus();
      annuncia(o.errore || '', true);
    }
  },
  // Verifica riuscita (codice email giusto, oppure messaggio WhatsApp ricevuto dal gateway).
  async verificaRiuscita(r) {
    const o = state.ui.otp;
    o.inviato = false; o.errore = null;
    state.sessione = { cellulare: o.cellulare, paziente: r.paziente, metodo: o.metodo };
    state.ui.correggi = false;
    if (r.paziente) {
      const p = r.paziente;
      state.draft.dati = { ...state.draft.dati, nome: p.nome, cognome: p.cognome, email: p.email, nascita: p.nascita, canale: p.canale || state.draft.dati.canale, privacy: true };
    } else if (o.metodo === 'email' && o.email && !state.draft.dati.email) {
      state.draft.dati = { ...state.draft.dati, email: o.email.trim() };
    }
    invalidaCache();
    setBusy(false);
    annuncia(o.metodo === 'email' ? 'Email verificata.' : 'Numero verificato.'); // WhatsApp e SMS provano il numero
    if (o.contesto === 'mie') { vai('mie', { replace: true }); return; }
    if (state.draft.data && state.draft.ora && !(await azioni.prendiRiserva({ replace: true }))) return;
    avanti('codice', { replace: true });
  },
  apriWhatsapp() {
    // In produzione: <a href> a wa.me con il testo già scritto (su desktop il QR). Nella demo il numero è finto e non si apre nulla.
    state.ui.otp.waAperto = true;
    toast('Nella demo WhatsApp non si apre: usa "Simula messaggio ricevuto".');
  },
  async controllaWhatsapp() {
    const o = state.ui.otp;
    if (o.inVerifica || !o.inviato || o.metodo !== 'whatsapp') return;
    o.inVerifica = true;
    try {
      const r = await api.otpStato({ cellulare: o.cellulare });
      o.inVerifica = false;
      if (r && r.verificato) await azioni.verificaRiuscita(r);
    } catch (e) {
      o.inVerifica = false;
      if (e.status === 410) { o.inviato = false; toast('La richiesta è scaduta. Riprova la verifica.', 'warn'); vai(o.contesto === 'mie' ? 'mie-accesso' : 'telefono', { replace: true }); }
    }
  },
  async simulaWhatsapp() {
    const o = state.ui.otp;
    try { await api.simulaWhatsapp({ cellulare: o.cellulare, testo: o.messaggio }); } catch (e) { erroreRete(e); return; }
    await azioni.controllaWhatsapp();
  },
  continuaDati() {
    const paz = pazienteRiconosciuto();
    if (paz && !state.ui.correggi && datiCompleti()) { avanti('dati'); return; }
    state.ui.tentato.dati = true;
    const e = erroriDati();
    if (Object.keys(e).length) { render(); focusPrimoErrore(e); return; }
    state.ui.tentato.dati = false;
    avanti('dati');
  },
  // Prenotazione senza registrazione: Turnstile, poi creazione con controllo atomico dell'orario (nessuna riserva prima).
  async inviaSenzaVerifica() {
    const d = state.draft;
    if (state.ui.busy || state.ui.antibotConferma) return;
    d.idemKey = d.idemKey || uuid();
    const trappola = ($('#rp-sito') || {}).value || '';
    const r0 = recapiti(d);
    const paz = d.perChi === 'altro'
      ? { nome: d.assistito.nome.trim(), cognome: d.assistito.cognome.trim(), nascita: d.assistito.nascita }
      : { nome: d.dati.nome.trim(), cognome: d.dati.cognome.trim(), nascita: d.dati.nascita };
    state.ui.antibotConferma = true; setBusy(true); render();
    annuncia('Verifica di sicurezza in corso');
    try {
      const token = await turnstile();
      const r = await api.creaPrenotazione({
        slot: { prestazione: d.prestazione, professionista: d.proAssegnato, sede: sedeDi(d), data: d.data, ora: d.ora },
        contatto: { cellulare: r0.cellulare, email: r0.email }, turnstile_token: token, trappola,
        paziente: paz,
        prenotante: d.perChi === 'altro' ? { nome: d.dati.nome.trim(), cognome: d.dati.cognome.trim() } : null,
        anagrafica: { nome: d.dati.nome.trim(), cognome: d.dati.cognome.trim(), email: r0.email, nascita: d.dati.nascita, cf: d.dati.cf.trim().toUpperCase() },
        canale: d.dati.canale, nota: d.dati.nota.trim(),
      }, d.idemKey);
      state.ui.antibotConferma = false;
      d.esito = r.prenotazione; d.completata = true; d.idemKey = null;
      invalidaCache(); setBusy(false);
      vai('esito', { replace: true });
      annuncia(d.esito.stato === 'in_attesa' ? 'Richiesta inviata allo studio.' : 'Prenotazione confermata.');
    } catch (e) {
      state.ui.antibotConferma = false; setBusy(false); d.idemKey = null;
      if (e.status === 403 && e.body.errore === 'verifica_antibot_fallita') { render(); toast('La verifica di sicurezza non è riuscita. Riprova.', 'warn'); }
      else if (e.status === 422 && e.body.errore !== 'limite_raggiunto') { state.ui.tentato.dati = true; toast('Controlla i tuoi recapiti.', 'warn'); vai('dati'); }
      else gestisciErroreRiserva(e);
    }
  },
  async inviaPrenotazione() {
    const d = state.draft;
    if (!verificaRichiesta()) return azioni.inviaSenzaVerifica();
    if (!riservaValida()) { toast('L\'orario non è più tenuto per te. Tocca "Riprova con lo stesso orario".', 'warn'); return; }
    d.idemKey = d.idemKey || uuid(); // Idempotency-Key: un doppio tocco non crea due prenotazioni
    setBusy(true);
    const paz = d.perChi === 'altro'
      ? { nome: d.assistito.nome.trim(), cognome: d.assistito.cognome.trim(), nascita: d.assistito.nascita }
      : { nome: d.dati.nome.trim(), cognome: d.dati.cognome.trim(), nascita: d.dati.nascita };
    try {
      const r = await api.creaPrenotazione({
        riserva: d.riserva.token,
        paziente: paz,
        prenotante: d.perChi === 'altro' ? { nome: d.dati.nome.trim(), cognome: d.dati.cognome.trim() } : null,
        anagrafica: { nome: d.dati.nome.trim(), cognome: d.dati.cognome.trim(), email: d.dati.email.trim(), nascita: d.dati.nascita, cf: d.dati.cf.trim().toUpperCase() },
        email: d.dati.email.trim(), canale: d.dati.canale, nota: d.dati.nota.trim(),
      }, d.idemKey);
      d.esito = r.prenotazione; d.completata = true; d.riserva = null; d.idemKey = null;
      invalidaCache();
      setBusy(false);
      vai('esito', { replace: true });
      annuncia(d.esito.stato === 'in_attesa' ? 'Richiesta inviata allo studio.' : 'Prenotazione confermata.');
    } catch (e) {
      setBusy(false); d.idemKey = null;
      if (e.status === 409 && e.body.errore === 'slot_non_disponibile') {
        d.riserva = null; state.ui.alternative = e.body.alternative || []; state.ui.altScelta = 0; invalidaCache();
        vai('preso');
      } else if (e.status === 410) { if (d.riserva) d.riserva.scaduta = true; render(); }
      else erroreRete(e);
    }
  },
  async scegliAlternativa() {
    const a = state.ui.alternative[state.ui.altScelta]; if (!a) return;
    const d = state.draft;
    Object.assign(d, { data: a.data, ora: a.ora, proAssegnato: a.professionista, sedeAssegnata: a.sede });
    state.ui.giorno = a.data;
    if (!verificaRichiesta() || await azioni.prendiRiserva({ replace: true })) vai('riepilogo', { replace: true });
  },

  // --- Sposta e annulla ---
  continuaSposta() { vai('sposta-conferma'); },
  async confermaSposta() {
    const p = prenotazioneCorrente(); const n = state.ui.sposta; if (!p || !n) return;
    setBusy(true);
    try {
      await api.sposta(p.ref, { data: n.data, ora: n.ora });
      invalidaCache(); state.ui.sposta = null; setBusy(false);
      caricaMie();
      tornaA('dettaglio');
      setTimeout(() => toast(`Fatto: ci vediamo ${dataLunga(n.data)} alle ${n.ora}. Ti abbiamo mandato la conferma.`), 120);
    } catch (e) {
      setBusy(false);
      if (e.status === 409 && e.body.errore === 'slot_non_disponibile') {
        invalidaCache(); state.ui.sposta = null; caricaMie();
        toast('Questo orario è appena stato prenotato. Il tuo appuntamento resta com\'era: scegline un altro.', 'warn');
        history.back();
      } else if (e.status === 409) { toast(`Mancano meno di ${cfg().regole.preavviso_modifica_ore} ore: per spostare chiama lo studio.`, 'warn'); }
      else erroreRete(e);
    }
  },
  async confermaAnnulla(ref) {
    const motivo = ($('input[name="motivo"]:checked') || {}).value || null;
    const btn = $('#conferma-annulla'); if (btn) { btn.classList.add('is-busy'); btn.setAttribute('aria-busy', 'true'); }
    try {
      const r = await api.annulla(ref, { motivo });
      chiudiSheet();
      invalidaCache();
      state.ui.annullata = r.prenotazione;
      if (state.draft.esito && state.draft.esito.ref === ref) state.draft.esito = r.prenotazione;
      caricaMie();
      vai('annullata', { ref });
    } catch (e) {
      if (btn) { btn.classList.remove('is-busy'); btn.removeAttribute('aria-busy'); }
      chiudiSheet();
      if (e.status === 409) toast(`Mancano meno di ${cfg().regole.preavviso_modifica_ore} ore: per annullare chiama lo studio.`, 'warn'); else erroreRete(e);
    }
  },

  // --- Chiedi allo studio ---
  async inviaContatto() {
    state.ui.tentato.contatto = true;
    const e = erroriContatto();
    if (Object.keys(e).length) { render(); focusPrimoErrore(e); return; }
    setBusy(true);
    try {
      await turnstile();
      await api.contatto({ nome: state.ui.contatto.nome.trim(), cellulare: normalizzaCell(state.ui.contatto.cellulare), testo: state.ui.contatto.testo.trim() });
      state.ui.contattoInviato = normalizzaCell(state.ui.contatto.cellulare);
      state.ui.contatto = { nome: '', cellulare: '', testo: '', privacy: false }; state.ui.tentato.contatto = false;
      setBusy(false); vai('contatto-ok', { replace: true });
    } catch (err) { setBusy(false); erroreRete(err); }
  },
};

function gestisciErroreRiserva(e, opz = {}) {
  const b = e.body || {};
  if (e.status === 403 && b.errore === 'solo_pazienti_in_archivio') return vai('nonammesso', opz);
  if (e.status === 422 && b.errore === 'limite_raggiunto') { state.ui.limite = b; return vai('limite', opz); }
  if (e.status === 409 && b.errore === 'prestazione_gia_prenotata') { state.ui.doppione = b.prenotazione; return vai('doppione', opz); }
  if (e.status === 409 && b.errore === 'slot_non_disponibile') {
    state.draft.riserva = null; state.ui.alternative = b.alternative || []; state.ui.altScelta = 0; invalidaCache();
    return vai('preso', opz);
  }
  if (e.status === 503) { ricaricaCatalogo().then(() => vai('benvenuto', { replace: true })); return; }
  if (e.status === 401) { state.sessione = { cellulare: null, paziente: null }; return vai('telefono', opz); }
  erroreRete(e);
}
function erroreRete(e) {
  if (e && e.status === 0) toast('Sei offline. Quando torna la connessione, riprova.', 'warn');
  else toast('Qualcosa non ha funzionato. Riprova tra qualche secondo.', 'warn');
}
function focusPrimoErrore(errori) {
  const n = Object.keys(errori).length;
  annuncia(n === 1 ? 'C\'è un campo da correggere.' : `Ci sono ${n} campi da correggere.`, true);
  const el = document.querySelector('#screen [aria-invalid="true"]');
  if (el) { el.focus(); el.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); }
}

// Professionista e sede con una sola scelta si selezionano da soli in avanti() (riempiAutomatici).
function scegliPrestazione(id) { scegli('prestazione', id); }
function rilasciaRiservaCorrente() {
  const r = state.draft.riserva;
  if (r && !r.scaduta) api.rilasciaRiserva(r.token).catch(() => {});
  state.draft.riserva = null;
}
function ricominciaConLaStessa(p) {
  state.draft = nuovaBozza(state.draft);
  Object.assign(state.draft, { prestazione: p.prestazione, professionista: p.professionista, sede: p.sede });
  state.ui.editing = false;
  vai('orario');
}

// Delegazione dei click: ogni elemento con data-action chiama la funzione omonima.
const CLICK = {
  cta() { if (!ctaCorrente || state.ui.busy) return; if (ctaCorrente.pronto === false) { toast(ctaCorrente.avviso || 'Completa il passo per continuare.', 'warn'); return; } ctaCorrente.azione(); },
  indietro,
  home() { if (state.ui.screen !== 'benvenuto') vai('benvenuto'); },
  'apri-mie'() { vai('mie'); },
  tutte() { state.ui.specialita = 'Tutte'; vai('prestazione'); },
  contatto() { state.ui.tentato.contatto = false; vai('contatto'); },
  'pulisci-ricerca'() { state.ui.query = ''; aggiornaRicerca(); const i = $('#screen input[type="search"]'); if (i) { i.value = ''; i.focus(); } },
  specialita(el) { state.ui.specialita = el.dataset.val; render(); },
  prestazione(el) { scegliPrestazione(el.dataset.id); },
  professionista(el) { scegli('professionista', el.dataset.id); },
  sede(el) { scegli('sede', el.dataset.id); },
  inizia() { state.ui.editing = false; avanti('benvenuto'); },
  'usa-invito'() {
    const inv = state.catalogo.invito;
    state.draft = nuovaBozza();
    Object.assign(state.draft, { prestazione: inv.prestazione, professionista: inv.professionista, sede: inv.sede });
    if (!state.ui.cellulareInput) state.ui.cellulareInput = formattaCell(NUMERO_ROSA);
    vai('orario');
  },
  giorno(el) {
    if (el.getAttribute('aria-disabled') === 'true') return;
    selezionaGiorno(el.dataset.iso, el.id);
  },
  'vai-giorno'(el) { selezionaGiorno(el.dataset.iso, null); const t = $(`#g-${el.dataset.iso}`) || $(`#c-${el.dataset.iso}`); if (t && t.offsetParent) t.focus(); },
  pagina(el) {
    if (el.getAttribute('aria-disabled') === 'true') return;
    const p = parametriOrario(); const disp = dispPer(p);
    const nuova = Math.min(Math.max(paginaDi(state.ui.giorno) + Number(el.dataset.delta), 0), MAX_PAGINA());
    const inizio = inizioPagina(nuova); const fine = piuGiorni(inizio, 13);
    const primo = primoLiberoTra(disp, inizio, fine);
    state.ui.giorno = primo || (inizio < piuGiorni(OGGI_ISO, 1) ? piuGiorni(OGGI_ISO, 1) : inizio);
    state.ui.scegliPrimoTra = primo ? null : { inizio, fine };
    if (state.ui.screen === 'sposta') state.ui.sposta = null; else Object.assign(state.draft, { data: null, ora: null });
    render();
  },
  mese(el) {
    if (el.getAttribute('aria-disabled') === 'true') return;
    const p = parametriOrario(); const disp = dispPer(p);
    const [y, m] = state.ui.giorno.slice(0, 7).split('-').map(Number);
    const primo = new Date(y, m - 1 + Number(el.dataset.delta), 1);
    let inizio = isoDi(primo); if (inizio <= OGGI_ISO) inizio = piuGiorni(OGGI_ISO, 1);
    let fine = isoDi(new Date(primo.getFullYear(), primo.getMonth() + 1, 0)); if (fine > ULTIMO_GIORNO()) fine = ULTIMO_GIORNO();
    const primoM = primoLiberoTra(disp, inizio, fine);
    state.ui.giorno = primoM || inizio;
    state.ui.scegliPrimoTra = primoM ? null : { inizio, fine };
    if (state.ui.screen === 'sposta') state.ui.sposta = null; else Object.assign(state.draft, { data: null, ora: null });
    render();
  },
  ora(el) {
    const v = { data: state.ui.giorno, ora: el.dataset.ora };
    if (state.ui.screen === 'sposta') state.ui.sposta = v;
    else Object.assign(state.draft, { data: v.data, ora: v.ora, proAssegnato: el.dataset.pro, sedeAssegnata: el.dataset.sede, durata: Number(el.dataset.durata) || null });
    render();
    annuncia(`Selezionato ${dataLunga(v.data)} alle ${v.ora}.`);
  },
  'primo-orario'() {
    const p = parametriOrario(); const primo = dispPer(p).primo; if (!primo) return;
    state.ui.giorno = primo.data;
    if (state.ui.screen === 'sposta') state.ui.sposta = { data: primo.data, ora: primo.ora };
    else Object.assign(state.draft, { data: primo.data, ora: primo.ora, proAssegnato: primo.professionista, sedeAssegnata: primo.sede, durata: primo.durata || null });
    render();
    annuncia(`Selezionato il primo orario libero: ${dataLunga(primo.data)} alle ${primo.ora}.`);
  },
  oltre30() { const p = parametriOrario(); state.ui.oltre30.add(chiaveDisp(p)); render(); const h = $('#titolo'); if (h) h.focus(); },
  avvisami() { toast('Fatto: ti scriviamo appena si libera un posto prima.'); }, // Lista d'attesa: Fase 2 (S17)
  'cambia-pro'() { vai('professionista'); },
  'riprova-riserva'() { azioni.prendiRiserva().then(ok => { if (ok) render(); }); },
  'demo-numero'(el) { state.ui.cellulareInput = formattaCell(el.dataset.n); state.ui.errori = {}; render(); const i = $('#cell'); if (i) i.focus(); },
  'altro-numero'() {
    api.esci().catch(() => {});
    rilasciaRiservaCorrente();
    state.sessione = { cellulare: null, paziente: null }; state.ui.cellulareInput = ''; state.ui.otp = nuovoOtp(); invalidaCache();
    vai(state.ui.screen === 'nonammesso' ? 'telefono' : state.ui.screen, { replace: true });
  },
  rimanda() { azioni.rimandaCodice(); },
  'simula-wa'() { azioni.simulaWhatsapp(); },
  'usa-metodo'(el) { const o = state.ui.otp; o.metodo = el.dataset.metodo; o.inviato = false; o.errore = null; tornaAllaVerifica(); },
  'cambia-numero'() { state.ui.otp.inviato = false; tornaAllaVerifica(); },
  correggi() {
    state.ui.correggi = true; render();
    const i = $('#dati-nome'); if (i) i.focus();
  },
  'demo-compila'() {
    Object.assign(state.draft.dati, DATI_ROSA, { privacy: true }, verificaRichiesta() ? {} : { cellulare: formattaCell(NUMERO_ROSA) });
    state.ui.errori = {}; state.ui.tentato.dati = false; render();
  },
  modifica(el) {
    state.ui.editing = true;
    if (el.dataset.passo === 'dati') state.ui.correggi = !!pazienteRiconosciuto();
    vai(el.dataset.passo);
  },
  alternativa() {},
  'tutti-orari'() { state.ui.editing = true; Object.assign(state.draft, { data: null, ora: null }); vai('orario', { replace: true }); },
  'sposta-esistente'(el) { state.cache.mie = null; caricaMie(); vai('dettaglio', { ref: el.dataset.ref }); },
  ricomincia() { state.draft = nuovaBozza(state.draft); state.ui.query = ''; state.ui.specialita = 'Tutte'; vai('prestazione'); },

  // --- Esito, calendario, gestione ---
  'menu-calendario'(el) { toggleMenu(el); },
  'menu-contatti'(el) { toggleMenu(el); },
  ics(el) { const p = trovaPrenotazione(el.dataset.ref); if (p) scaricaIcs(p); },
  'ics-annulla'(el) { const p = trovaPrenotazione(el.dataset.ref); if (p) scaricaIcs(p, true); },
  gestisci(el) { if (state.cache.mie === null) caricaMie(); vai('dettaglio', { ref: el.dataset.ref }); },
  'tab-mie'(el) { state.ui.mieTab = el.dataset.tab; render(); },
  esci() {
    api.esci().catch(() => {});
    state.sessione = { cellulare: null, paziente: null }; state.cache.mie = null; state.ui.otp = nuovoOtp(); state.ui.cellulareInput = '';
    toast('Sei uscito: su questo dispositivo le prenotazioni non sono più visibili.');
    vai('benvenuto');
  },
  sposta(el) {
    if (el.getAttribute('aria-disabled') === 'true') { toast(`Mancano meno di ${cfg().regole.preavviso_modifica_ore} ore: per spostare chiama lo studio.`, 'warn'); return; }
    state.ui.sposta = null; state.ui.giornoChiave = null; vai('sposta', { ref: el.dataset.ref });
  },
  annulla(el) {
    if (el.getAttribute('aria-disabled') === 'true') { toast(`Mancano meno di ${cfg().regole.preavviso_modifica_ore} ore: per annullare chiama lo studio.`, 'warn'); return; }
    apriAnnulla(el.dataset.ref, el);
  },
  'conferma-annulla'(el) { azioni.confermaAnnulla(el.dataset.ref); },
  'prenota-di-nuovo'(el) { const p = trovaPrenotazione(el.dataset.ref); if (p) ricominciaConLaStessa(p); },
  installa() { apriInstalla(); },
  'chiudi-sheet'() { chiudiSheet(); },
  'tab-os'(el) { disegnaInstalla(el.dataset.os); const b = $(`#os-${el.dataset.os}`); if (b) b.focus(); },
  async 'prompt-installa'() {
    if (!promptInstallazione) return;
    promptInstallazione.prompt();
    const scelta = await promptInstallazione.userChoice.catch(() => ({}));
    promptInstallazione = null; chiudiSheet();
    if (scelta && scelta.outcome === 'accepted') toast('Fatto: trovi l\'app dello studio nella schermata Home.');
  },
  'nota-legale'() { window.open(BOOT.privacy, '_blank', 'noopener,noreferrer'); },

  // --- Pannello demo ---
  'demo-apri'() { state.demo.aperto = true; disegnaDemo(); const s = $('#demo-modalita'); if (s) s.focus(); },
  'demo-chiudi'() { state.demo.aperto = false; disegnaDemo(); const b = $('#demo-toggle'); if (b) b.focus(); },
  'demo-approva'() {
    const r = FakeServer.demo.approva(); if (!r) return;
    const p = r.prenotazione;
    invalidaCache();
    if (state.draft.esito && state.draft.esito.ref === p.ref) state.draft.esito = p;
    if (verificato() && state.sessione.cellulare === p.cellulare && !state.sessione.paziente) state.sessione.paziente = r.paziente;
    render();
    toast(`Lo studio ha confermato la prenotazione ${p.ref}. Ti abbiamo scritto su WhatsApp e via email.`);
  },
  'demo-installa'() { apriInstalla(true); },
  'demo-reset'() { resetDemo(); },
  'cfg-preset'(el) { demoPreset(el.dataset.preset); },
  'cfg-sposta'(el) { if (!el.disabled) demoSpostaPasso(el.dataset.id, Number(el.dataset.delta)); },
};

// La copia più aggiornata è quella delle "mie prenotazioni" (dopo uno spostamento l'esito è vecchio).
// Torna alla schermata "Verifica che sei tu" (o all'accesso a Le mie prenotazioni) senza perdere i dati scritti.
function tornaAllaVerifica() {
  const ctx = state.ui.otp.contesto; const dest = ctx === 'mie' ? 'mie-accesso' : 'telefono';
  if (pila[idx - 1] === dest) history.back(); else vai(dest, { replace: true });
}

function trovaPrenotazione(ref) {
  const daMie = (state.cache.mie || []).find(p => p.ref === ref);
  if (daMie) return daMie;
  if (state.ui.annullata && state.ui.annullata.ref === ref) return state.ui.annullata;
  if (state.draft.esito && state.draft.esito.ref === ref) return state.draft.esito;
  return null;
}
function toggleMenu(el) {
  const k = el.dataset.menu; state.ui.menuAperto = state.ui.menuAperto === k ? null : k;
  const menu = document.getElementById(el.getAttribute('aria-controls'));
  el.setAttribute('aria-expanded', String(state.ui.menuAperto === k));
  if (menu) { menu.hidden = state.ui.menuAperto !== k; if (!menu.hidden) { const f = menu.querySelector('a,button'); if (f) f.focus(); } }
}
function selezionaGiorno(iso, focusId) {
  state.ui.giorno = iso; state.ui.scegliPrimoTra = null;
  if (state.ui.screen === 'sposta') state.ui.sposta = null;
  else if (state.draft.data !== iso) Object.assign(state.draft, { data: null, ora: null });
  render();
  if (focusId) { const el = document.getElementById(focusId); if (el) el.focus({ preventScroll: true }); }
  const p = parametriOrario(); const g = dispPer(p).giorni[iso];
  if (g) annuncia(`${maiuscola(dataLunga(iso))}: ${g.orari.length ? `${g.orari.length} ${g.orari.length === 1 ? 'orario libero' : 'orari liberi'}` : 'nessun orario libero'}.`);
}

document.addEventListener('click', e => {
  const el = e.target.closest('[data-action]');
  if (!el) return;
  const fn = CLICK[el.dataset.action];
  if (!fn) return;
  const href = el.getAttribute('href');
  if (el.tagName === 'A' && href && href.startsWith('#')) e.preventDefault();
  fn(el, e);
});

// Input: aggiorna lo stato senza ridisegnare (il focus resta dov'è).
const INPUT = {
  cerca(el) { state.ui.query = el.value; aggiornaRicerca(); },
  cellulare(el) { state.ui.cellulareInput = el.value; if (state.ui.errori.cell) { delete state.ui.errori.cell; } },
  'assistito.nome'(el) { state.draft.assistito.nome = el.value; },
  'assistito.cognome'(el) { state.draft.assistito.cognome = el.value; },
  'assistito.nascita'(el) { el.value = formattaData(el.value); state.draft.assistito.nascita = el.value; },
  'dati.nome'(el) { state.draft.dati.nome = el.value; },
  'dati.cognome'(el) { state.draft.dati.cognome = el.value; },
  'dati.email'(el) { state.draft.dati.email = el.value; },
  'dati.cellulare'(el) { state.draft.dati.cellulare = el.value; },
  'dati.cf'(el) { const p = el.selectionStart; el.value = el.value.toUpperCase().replace(/\s/g, ''); try { el.setSelectionRange(p, p); } catch (e) { /* tipo non supportato */ } state.draft.dati.cf = el.value; },
  'dati.nascita'(el) { el.value = formattaData(el.value); state.draft.dati.nascita = el.value; },
  'dati.nota'(el) { state.draft.dati.nota = el.value; },
  'contatto.nome'(el) { state.ui.contatto.nome = el.value; },
  'contatto.cellulare'(el) { state.ui.contatto.cellulare = el.value; },
  'contatto.testo'(el) { state.ui.contatto.testo = el.value; },
  otp(el) { inputOtp(el); },
  emailVer(el) { state.ui.otp.email = el.value; if (state.ui.errori.email) delete state.ui.errori.email; },
};
function formattaData(v) { // aggiunge le barre mentre si scrive: 14031968 -> 14/03/1968
  const n = v.replace(/\D/g, '').slice(0, 8);
  if (v.includes('/')) return v.replace(/[^\d/]/g, '').slice(0, 10);
  return n.length > 4 ? `${n.slice(0, 2)}/${n.slice(2, 4)}/${n.slice(4)}` : n.length > 2 ? `${n.slice(0, 2)}/${n.slice(2)}` : n;
}
document.addEventListener('input', e => {
  const el = e.target.closest('[data-input]'); if (!el) return;
  const fn = INPUT[el.dataset.input]; if (fn) fn(el, e);
});

// Change: radio e caselle (ridisegnano, il focus resta sull'elemento grazie all'id).
const CHANGE = {
  perchi(el) { state.draft.perChi = el.value; state.ui.tentato.perchi = false; state.ui.errori = {}; render(); },
  autorizzato(el) { state.draft.autorizzato = el.checked; if (state.ui.tentato.perchi) render(); },
  canale(el) { state.draft.dati.canale = el.value; render(); },
  metodo(el) { state.ui.otp.metodo = el.value; delete state.ui.errori.email; render(); const f = $(el.value === 'email' ? '#email-ver' : `#metodo-${el.value}`); if (f) f.focus(); },
  privacy(el) { state.draft.dati.privacy = el.checked; if (state.ui.tentato.dati) render(); },
  'contatto-privacy'(el) { state.ui.contatto.privacy = el.checked; if (state.ui.tentato.contatto) render(); },
  alternativa(el) { state.ui.altScelta = Number(el.value); render(); },
  'demo-modalita'(el) { demoCambiaModalita(el.value); },
  'demo-paziente'(el) { demoPaziente(el.value); },
  'demo-preso'(el) { FakeServer.demo.flags().orarioPreso = el.checked; },
  'demo-sospese'(el) { FakeServer.demo.config().sospese = el.checked; ricaricaCatalogo().then(() => { state.draft = nuovaBozza(state.draft); vai('benvenuto'); }); },
  'demo-limite'(el) { FakeServer.demo.flags().limite = el.checked; },
  // --- Configurazione dello studio (pannello demo) ---
  'cfg-attivo'(el) { demoAttivaPasso(el.dataset.id, el.checked); },
  'cfg-scelta'(el) {
    const nomi = { primo_disponibile: '"Il primo disponibile"', salta_professionista_se_unico: 'Salto del professionista unico', salta_sede_se_unica: 'Salto della sede unica' };
    cambiaConfig(c => { c.scelte[el.dataset.k] = el.checked; }, `${nomi[el.dataset.k]}: ${el.checked ? 'acceso' : 'spento'}.`);
  },
  'cfg-logo'(el) {
    // Il logo d'esempio contiene già il nome: accendendolo il nome accanto si spegne (si può riaccendere).
    cambiaConfig(c => { c.aspetto.logo_url = el.checked ? LOGO_DEMO : null; c.aspetto.mostra_nome_con_logo = !el.checked; },
      el.checked ? 'Logo dello studio acceso: sostituisce il monogramma nella testata.' : 'Logo tolto: torna il monogramma con il nome.');
  },
  'cfg-nome-logo'(el) { cambiaConfig(c => { c.aspetto.mostra_nome_con_logo = el.checked; }, el.checked ? 'Nome dello studio accanto al logo.' : 'Solo il logo nella testata.'); },
  'cfg-testi'(el) {
    cambiaConfig(c => { for (const k of Object.keys(c.testi)) c.testi[k] = el.checked ? TESTI_DEMO[k] : null; },
      el.checked ? 'Testi personalizzati: titolo e sottotitolo del benvenuto, nota nel riepilogo.' : 'Testi predefiniti.');
  },
  'cfg-senza'(el) {
    cambiaConfig(c => impostaVerifica(c, !el.checked),
      el.checked ? 'Prenotazione senza registrazione: niente verifica, i recapiti si chiedono in "I tuoi dati".' : 'Verifica accesa: il paziente conferma chi è prima dei dati.');
  },
  'cfg-recapito'(el) { cambiaConfig(c => { c.senza_verifica.recapito = el.value; }, `Recapito senza verifica: ${RECAPITI[el.value].toLowerCase()}.`); },
  'cfg-metodo'(el) { demoMetodo(el.dataset.m, el.checked); },
  'cfg-dato'(el) {
    const nomi = { email: 'Email', nascita: 'Data di nascita', codice_fiscale: 'Codice fiscale', note: 'Nota per lo studio' };
    cambiaConfig(c => { c.dati[el.dataset.k] = el.value; }, `Campo "${nomi[el.dataset.k]}": ${el.value}.`);
  },
  'demo-unico'(el) {
    FakeServer.demo.flags().unico = el.checked; state.demo.errori = null;
    ripartiDemo(el.checked ? 'Studio con un solo professionista e una sede: Dott.ssa Conti a Empoli. Si selezionano da soli.' : 'Studio completo: quattro professionisti, due sedi.');
  },
};
document.addEventListener('change', e => {
  const el = e.target.closest('[data-change]'); if (!el) return;
  const fn = CHANGE[el.dataset.change]; if (fn) fn(el, e);
});

// Validazione in linea all'uscita dal campo (solo se già scritto qualcosa, o dopo il primo invio).
const VALIDA = {
  'dati.nome': () => ['dati-nome', erroriDati()['dati-nome']],
  'dati.cognome': () => ['dati-cognome', erroriDati()['dati-cognome']],
  'dati.email': () => ['dati-email', erroriDati()['dati-email']],
  'dati.nascita': () => ['dati-nascita', erroriDati()['dati-nascita']],
  'dati.cellulare': () => ['dati-cell', erroriDati()['dati-cell']],
  'dati.cf': () => ['dati-cf', erroriDati()['dati-cf']],
  'dati.nota': () => ['dati-nota', erroriDati()['dati-nota']],
  'assistito.nome': () => ['as-nome', erroriPerChi()['as-nome']],
  'assistito.cognome': () => ['as-cognome', erroriPerChi()['as-cognome']],
  'assistito.nascita': () => ['as-nascita', erroriPerChi()['as-nascita']],
  'contatto.nome': () => ['ct-nome', erroriContatto()['ct-nome']],
  'contatto.cellulare': () => ['ct-cell', erroriContatto()['ct-cell']],
  'contatto.testo': () => ['ct-testo', erroriContatto()['ct-testo']],
};
document.addEventListener('focusout', e => {
  const el = e.target.closest && e.target.closest('[data-valida]'); if (!el) return;
  const f = VALIDA[el.dataset.valida]; if (!f) return;
  if (!el.value.trim() && !el.hasAttribute('aria-invalid')) return;
  const [id, err] = f();
  const prima = state.ui.errori[id];
  if (err) state.ui.errori[id] = err; else delete state.ui.errori[id];
  if (prima !== state.ui.errori[id]) aggiornaErroreCampo(id, err); // solo il campo: nessun ridisegno, il tocco sul bottone non si perde
});
function aggiornaErroreCampo(id, err) {
  const input = document.getElementById(id); if (!input) return;
  const field = input.closest('.field'); if (!field) return;
  let p = document.getElementById(`${id}-err`);
  if (err) {
    if (!p) { p = document.createElement('p'); p.className = 'error'; p.id = `${id}-err`; field.appendChild(p); }
    p.innerHTML = `${icon('alert')}<span>${err}</span>`;
    field.classList.add('has-error'); input.setAttribute('aria-invalid', 'true');
  } else {
    if (p) p.remove();
    field.classList.remove('has-error'); input.removeAttribute('aria-invalid');
  }
  const desc = [document.getElementById(`${id}-aiuto`) ? `${id}-aiuto` : '', err ? `${id}-err` : ''].filter(Boolean).join(' ');
  if (desc) input.setAttribute('aria-describedby', desc); else input.removeAttribute('aria-describedby');
}

// Codice email: incollare o l'autocompletamento riempiono tutte le caselle; il focus seleziona la cifra.
document.addEventListener('paste', e => {
  const el = e.target.closest && e.target.closest('[data-input="otp"]'); if (!el) return;
  const t = ((e.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '');
  if (!t) return;
  e.preventDefault();
  const primo = t.length >= 6 ? $('#otp-0') : el;
  primo.value = t.slice(0, 6); inputOtp(primo);
});
document.addEventListener('focusin', e => { if (e.target.matches && e.target.matches('[data-input="otp"]')) e.target.select(); });

// Invio da tastiera nei form (tasto Invio).
document.addEventListener('submit', e => {
  const f = e.target.closest('form[data-submit]'); if (!f) return;
  e.preventDefault();
  CLICK.cta();
});

// Tastiera: frecce nei radiogroup (giorni, orari) e nei tab; Backspace nel codice.
document.addEventListener('keydown', e => {
  if (e.target.matches && e.target.matches('[data-input="otp"]')) { keydownOtp(e); return; }
  const gruppo = e.target.closest && e.target.closest('[data-roving]'); if (!gruppo) return;
  const tipo = gruppo.dataset.roving;
  const succ = ['ArrowRight', 'ArrowDown'].includes(e.key), prec = ['ArrowLeft', 'ArrowUp'].includes(e.key);
  if (!succ && !prec && e.key !== 'Home' && e.key !== 'End') return;
  e.preventDefault();
  // Il focus si sposta PRIMA del click: così il ridisegno lo conserva sull'elemento nuovo.
  const attiva = b => { b.focus(); b.click(); };
  if (tipo === 'griglia') { // calendario: sinistra/destra un giorno, su/giù una settimana
    let passo = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 7, ArrowUp: -7 }[e.key] || 0;
    let iso = e.target.dataset.iso;
    for (let i = 0; i < 31 && passo; i++) {
      iso = piuGiorni(iso, passo);
      const b = gruppo.querySelector(`[data-iso="${iso}"]`);
      if (!b) break;
      if (b.getAttribute('aria-disabled') !== 'true') { attiva(b); break; }
      if (Math.abs(passo) === 7) passo = passo > 0 ? 1 : -1;
    }
    return;
  }
  const items = [...gruppo.querySelectorAll('[role="radio"],[role="tab"]')].filter(b => b.getAttribute('aria-disabled') !== 'true');
  let i = items.indexOf(e.target);
  if (e.key === 'Home') i = 0; else if (e.key === 'End') i = items.length - 1; else i = (i + (succ ? 1 : -1) + items.length) % items.length;
  if (items[i]) attiva(items[i]);
});

// Codice a 6 caselle: incolla e autocompletamento distribuiscono le cifre.
function inputOtp(el) {
  const o = state.ui.otp; let i = Number(el.dataset.i);
  const cifre = el.value.replace(/\D/g, '');
  if (cifre.length > 1) {
    if (cifre.length >= 6) i = 0; // codice intero (incollato o autocompletato): si parte dalla prima casella
    cifre.slice(0, 6 - i).split('').forEach((c, k) => { o.cifre[i + k] = c; const b = $(`#otp-${i + k}`); if (b) b.value = c; });
    const prossima = Math.min(i + cifre.length, 5); const b = $(`#otp-${prossima}`); if (b) b.focus();
  } else {
    o.cifre[i] = cifre; el.value = cifre;
    if (cifre && i < 5) $(`#otp-${i + 1}`).focus();
  }
  if (o.errore) { o.errore = null; const err = $('#otp-err'); if (err) err.remove(); const z = $('.otp'); if (z) z.classList.remove('is-error'); document.querySelectorAll('[data-input="otp"]').forEach(b => { b.removeAttribute('aria-invalid'); b.removeAttribute('aria-describedby'); }); }
  const pronto = o.cifre.join('').length === 6;
  document.querySelectorAll('[data-action="cta"]').forEach(b => { if (pronto) b.removeAttribute('aria-disabled'); else b.setAttribute('aria-disabled', 'true'); });
  if (ctaCorrente) ctaCorrente.pronto = pronto;
  if (pronto) azioni.verificaCodice();
}
function keydownOtp(e) {
  const i = Number(e.target.dataset.i); const o = state.ui.otp;
  if (e.key === 'Backspace' && !e.target.value && i > 0) { e.preventDefault(); const b = $(`#otp-${i - 1}`); o.cifre[i - 1] = ''; b.value = ''; b.focus(); }
  else if (e.key === 'ArrowLeft' && i > 0) { e.preventDefault(); $(`#otp-${i - 1}`).focus(); }
  else if (e.key === 'ArrowRight' && i < 5) { e.preventDefault(); $(`#otp-${i + 1}`).focus(); }
  else if (e.key === 'Enter') { e.preventDefault(); CLICK.cta(); }
}

function aggiornaRicerca() {
  const home = $('#risultati'); const elenco = $('#elenco');
  if (home) home.innerHTML = risultatiHome();
  if (elenco) elenco.innerHTML = elencoPrestazioni();
  const clear = $('#screen .clear'); if (clear) clear.hidden = !state.ui.query;
  const esito = $('#screen [id$="-esito"]');
  if (esito) { const n = filtraPrestazioni().length; esito.textContent = state.ui.query.trim() ? (n ? `${n} ${n === 1 ? 'prestazione trovata' : 'prestazioni trovate'}` : 'Nessuna prestazione trovata') : ''; }
}


/* ==========================================================================
   10. SERVIZI UI
   ========================================================================== */

// --- Annunci per lettori di schermo ---
function annuncia(testo, urgente = false) {
  const el = $(urgente ? '#sr-a' : '#sr'); if (!el) return;
  el.textContent = ''; setTimeout(() => { el.textContent = testo; }, 40);
}

// --- Toast ---
let toastTimer = null;
function toast(testo, tipo = 'ok') {
  const t = $('#toast');
  t.className = `toast show ${tipo === 'warn' ? 'warn' : ''}`;
  t.innerHTML = `${icon(tipo === 'warn' ? 'info' : 'check')}<span>${esc(testo)}</span>`;
  clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.className = 'toast'; }, 4800);
}

// --- Altezza della barra CTA fissa (serve a padding, toast e pannello demo) ---
function aggiornaAltezzaBarra() {
  const bar = $('#bar');
  const h = bar.hidden || getComputedStyle(bar).display === 'none' ? 0 : bar.offsetHeight;
  document.documentElement.style.setProperty('--bar-h', `${h}px`);
}
window.addEventListener('resize', aggiornaAltezzaBarra);
if (window.matchMedia) { const mq = matchMedia('(min-width: 1024px)'); if (mq.addEventListener) mq.addEventListener('change', aggiornaAltezzaBarra); }

// --- Timer: riserva di 10 minuti, rimando del codice, blocco dei tentativi ---
setInterval(() => {
  const r = state.draft.riserva; const ora = Date.now();
  if (r && !r.scaduta) {
    const resto = r.scade - ora;
    if (resto <= 0) {
      r.scaduta = true; annuncia('Il tempo per confermare è scaduto.', true);
      ridisegnaSe(['orario', 'dati', 'riepilogo', 'perchi', 'telefono']);
    } else {
      document.querySelectorAll('[data-hold-time]').forEach(el => { el.textContent = mmss(resto); });
      if (resto < 60000 && !r.avvisi.uno) { r.avvisi.uno = true; annuncia('Resta un minuto per confermare l\'orario.'); }
      else if (resto < 300000 && !r.avvisi.cinque) { r.avvisi.cinque = true; annuncia('Restano 5 minuti per confermare l\'orario.'); }
    }
  }
  const o = state.ui.otp;
  // WhatsApp: ogni 3 secondi la pagina chiede se il messaggio è arrivato (in produzione polling o SSE).
  if (['codice', 'mie-codice'].includes(state.ui.screen) && o.metodo === 'whatsapp' && o.inviato && Math.floor(ora / 1000) % 3 === 0) azioni.controllaWhatsapp();
  if (['codice', 'mie-codice'].includes(state.ui.screen) && o.metodo !== 'whatsapp') { // codice via email o SMS
    const fine = Math.max(o.rimandaDa, o.bloccatoFino > ora ? o.bloccatoFino : 0);
    document.querySelectorAll('[data-rimanda]').forEach(el => { el.textContent = mmss(fine - ora); });
    document.querySelectorAll('[data-blocco]').forEach(el => { el.textContent = mmss(o.bloccatoFino - ora); });
    const rimandaVisibile = !!$('#rimanda');
    const deveEssere = fine <= ora;
    if (rimandaVisibile !== deveEssere) {
      if (o.bloccatoFino && o.bloccatoFino <= ora) { o.bloccatoFino = 0; o.errore = null; }
      render();
    }
  }
}, 1000);

// --- Bottom sheet (dialog nativo: focus intrappolato, Esc chiude) ---
let aperturaDa = null;
function apriSheet(html, opener) {
  const dlg = $('#sheet');
  aperturaDa = opener || document.activeElement;
  dlg.innerHTML = `<div class="sheet-in"><div class="grab" aria-hidden="true"></div>
    <button type="button" class="icon-btn sheet-x" data-action="chiudi-sheet" aria-label="Chiudi">${icon('x')}</button>${html}</div>`;
  if (!dlg.open) { if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', ''); }
  const f = dlg.querySelector('[autofocus]') || dlg.querySelector('#sheet-titolo');
  if (f) { f.setAttribute('tabindex', '-1'); f.focus(); }
}
function chiudiSheet() {
  const dlg = $('#sheet'); if (dlg.open) { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); }
}
$('#sheet').addEventListener('close', () => {
  if (aperturaDa && document.contains(aperturaDa)) aperturaDa.focus();
  aperturaDa = null;
});
$('#sheet').addEventListener('click', e => { if (e.target === e.currentTarget) chiudiSheet(); }); // tocco sullo sfondo

// --- S14 Annulla: bottom sheet su mobile, dialogo su desktop. Mai confirm() nativo ---
function apriAnnulla(ref, opener) {
  const p = trovaPrenotazione(ref); if (!p) return;
  const pro = PROFESSIONISTI[p.professionista];
  const richiesta = p.stato === 'in_attesa';
  apriSheet(`<div class="sheet-head"><h2 class="h2" id="sheet-titolo">${richiesta ? 'Vuoi annullare la richiesta?' : 'Vuoi annullare la prenotazione?'}</h2></div>
    <p class="sheet-text"><span class="tnum">${maiuscola(dataLunga(p.data))} alle ${p.ora}</span> con ${conArticolo(pro)}. L'orario torna libero per altri pazienti.</p>
    <fieldset><legend class="label" style="margin-bottom:10px">Perché la annulli? <span class="opt-txt">(facoltativo)</span></legend>
      <div class="chips">${MOTIVI_ANNULLO.map((m, i) => `<label class="chip chip-radio" for="motivo-${i}"><input type="radio" name="motivo" id="motivo-${i}" value="${m}" data-change="motivo">${m}</label>`).join('')}</div>
    </fieldset>
    <div class="sheet-actions">
      <button type="button" class="btn btn-primary btn-lg btn-block" data-action="chiudi-sheet">Tieni l'appuntamento</button>
      <button type="button" class="btn btn-danger btn-block" id="conferma-annulla" data-action="conferma-annulla" data-ref="${p.ref}"><span class="spin dark" aria-hidden="true"></span><span class="btn-label">${richiesta ? 'Annulla la richiesta' : 'Annulla la prenotazione'}</span></button>
    </div>`, opener);
}
CHANGE.motivo = el => { document.querySelectorAll('#sheet .chip-radio').forEach(l => l.classList.toggle('is-on', l.contains(el))); };

// --- S20 Installa l'app ---
let promptInstallazione = null;
window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); promptInstallazione = e; }); // Android/Chrome: prompt nativo
const standalone = () => true; // No installable public patient application in this phase.
const iOS = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
function programmaInstalla() {
  // Dopo la conferma, una volta per sessione; mai in standalone. "Non ora" in produzione lo nasconde per 30 giorni.
  if (state.ui.installMostrato || standalone()) return;
  state.ui.installMostrato = true;
  setTimeout(() => { if (state.ui.screen === 'esito' && !$('#sheet').open) apriInstalla(); }, 1600);
}
function apriInstalla(forza) {
  if (standalone() && !forza) return;
  apriSheet('<div id="installa-corpo"></div>', document.activeElement);
  disegnaInstalla(iOS() ? 'ios' : 'android');
  const h = $('#sheet-titolo'); if (h) h.focus();
}
function disegnaInstalla(os) {
  const box = $('#installa-corpo'); if (!box) return;
  const passi = os === 'ios'
    ? [['Tocca <strong>Condividi</strong> in basso nel browser Safari', `<span class="glyph">${icon('share')}</span>`],
       ['Scegli <strong>Aggiungi alla schermata Home</strong>', `<span class="glyph">${icon('plus-square')}</span>`],
       ['Apri l\'app dello studio dalla Home', '<span class="glyph"><span class="mini-tile">SC</span></span>']]
    : [['Tocca il <strong>menu</strong> del browser in alto a destra', `<span class="glyph">${icon('more')}</span>`],
       ['Scegli <strong>Installa app</strong> oppure <strong>Aggiungi a schermata Home</strong>', `<span class="glyph">${icon('plus-square')}</span>`],
       ['Apri l\'app dello studio dalla Home', '<span class="glyph"><span class="mini-tile">SC</span></span>']];
  box.innerHTML = `<div class="app-tile lg" aria-hidden="true" style="margin:4px 0 16px">SC</div>
    <div class="sheet-head"><h2 class="h2" id="sheet-titolo" tabindex="-1">Installa l'app dello studio</h2></div>
    <p class="sheet-text">${conMiePrenotazioni() ? 'Ritrovi le tue prenotazioni' : 'Prenoti di nuovo'} con un tocco dalla schermata Home. Niente store e niente password.</p>
    <div class="seg-ctl" role="tablist" aria-label="Il tuo telefono">
      <button type="button" role="tab" id="os-android" data-action="tab-os" data-os="android" aria-selected="${os === 'android'}" aria-controls="os-pannello" tabindex="${os === 'android' ? 0 : -1}">Android</button>
      <button type="button" role="tab" id="os-ios" data-action="tab-os" data-os="ios" aria-selected="${os === 'ios'}" aria-controls="os-pannello" tabindex="${os === 'ios' ? 0 : -1}">iPhone</button>
    </div>
    <div role="tabpanel" id="os-pannello" aria-labelledby="os-${os}">
      ${os === 'android' && promptInstallazione
        ? '<p class="sheet-text" style="margin-bottom:0">Il telefono ti chiede una conferma e l\'app compare nella schermata Home.</p>'
        : `<ol class="steps">${passi.map(([t, g], i) => `<li><b>${i + 1}</b><span>${t}</span>${g}</li>`).join('')}</ol>
           ${os === 'android' ? '<p class="micro mt12">Su Samsung Internet: menu, poi Aggiungi pagina a, poi Schermata Home.</p>' : '<p class="micro mt12">Su iPhone funziona con Safari.</p>'}`}
    </div>
    <div class="sheet-actions">
      ${os === 'android' && promptInstallazione ? '<button type="button" class="btn btn-primary btn-lg btn-block" data-action="prompt-installa">Installa l\'app</button>' : ''}
      <button type="button" class="btn btn-secondary btn-block" data-action="chiudi-sheet">Non ora</button>
    </div>`;
  const tabs = box.querySelector('[role="tablist"]'); if (tabs) tabs.dataset.roving = 'tab';
}

// --- File .ics (RFC 5545): VTIMEZONE Europe/Rome, CRLF, righe ripiegate a 75 ottetti, niente prestazione ---
const SUMMARY_ICS = `Appuntamento presso ${esc(STUDIO.nome)}`;
const linkGestione = p => new URL(BOOT.api.replace(/\/api$/, '/paziente') + '?ref=' + encodeURIComponent(p.ref), location.href).href;
const testoIcs = s => String(s).replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\r?\n/g, '\\n');
const compatta = (iso, min) => `${iso.replace(/-/g, '')}T${pad(Math.floor(min / 60))}${pad(min % 60)}00`;
function piega(riga) {
  const enc = new TextEncoder();
  if (enc.encode(riga).length <= 75) return riga;
  const parti = []; let corrente = '';
  for (const ch of riga) {
    if (enc.encode(corrente + ch).length > 75) { parti.push(corrente); corrente = ` ${ch}`; } else corrente += ch;
  }
  parti.push(corrente);
  return parti.join('\r\n');
}
function creaIcs(p, annullo = false) {
  const sede = SEDI[p.sede];
  const inizio = minutiDi(p.ora); const fine = inizio + (p.durata || (prestazione(p.prestazione)?.durata || p.durata));
  const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
  const luogo = sede?.online ? 'Videochiamata: il link arriva il giorno prima' : `${esc(STUDIO.nome)}, ${esc(indirizzo(sede))}`;
  const descr = [`Codice prenotazione: ${p.ref}`, `Per spostare o annullare: ${linkGestione(p)}`, `Telefono dello studio: ${esc(STUDIO.telefono)}`].join('\n');
  const righe = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Ambulatorio Facile//Prenotazione online//IT', 'CALSCALE:GREGORIAN', `METHOD:${annullo ? 'CANCEL' : 'PUBLISH'}`,
    'BEGIN:VTIMEZONE', 'TZID:Europe/Rome', 'X-LIC-LOCATION:Europe/Rome',
    'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
    'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
    'END:VTIMEZONE',
    'BEGIN:VEVENT',
    `UID:${p.ref}@${STUDIO.tenantKey}.ambulatoriofacile.it`,
    `SEQUENCE:${(p.ics_sequence || 0) + (annullo && p.stato !== 'annullata' ? 1 : 0)}`,
    `DTSTAMP:${stamp}`,
    `DTSTART;TZID=Europe/Rome:${compatta(p.data, inizio)}`,
    `DTEND;TZID=Europe/Rome:${compatta(p.data, fine)}`,
    `SUMMARY:${testoIcs(SUMMARY_ICS)}`,
    `LOCATION:${testoIcs(luogo)}`,
    `DESCRIPTION:${testoIcs(descr)}`,
    `STATUS:${annullo ? 'CANCELLED' : p.stato === 'in_attesa' ? 'TENTATIVE' : 'CONFIRMED'}`,
    'TRANSP:OPAQUE',
    ...(annullo ? [] : ['BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Promemoria appuntamento', 'TRIGGER:-P1D', 'END:VALARM']),
    'END:VEVENT', 'END:VCALENDAR',
  ];
  return righe.map(piega).join('\r\n') + '\r\n';
}
function scaricaIcs(p, annullo = false) {
  const blob = new Blob([creaIcs(p, annullo)], { type: 'text/calendar;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href = url; a.download = `appuntamento-${p.ref}${annullo ? '-annullato' : ''}.ics`;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 5000);
  toast(annullo ? 'File scaricato: aprilo per togliere l\'appuntamento dal calendario.' : 'File scaricato: aprilo per aggiungere l\'appuntamento al calendario.');
}
function linkGoogle(p) {
  const sede = SEDI[p.sede]; const i = minutiDi(p.ora); const f = i + (p.durata || 30);
  return `https://calendar.google.com/calendar/render?${query({ action: 'TEMPLATE', text: SUMMARY_ICS, dates: `${compatta(p.data, i)}/${compatta(p.data, f)}`, ctz: 'Europe/Rome',
    location: sede?.online ? 'Videochiamata' : indirizzo(sede), details: `Codice prenotazione: ${p.ref}\nPer spostare o annullare: ${linkGestione(p)}` })}`;
}
function linkOutlook(p) {
  const sede = SEDI[p.sede]; const i = minutiDi(p.ora); const f = i + (p.durata || 30); const tz = offsetRoma(p.data);
  const t = m => `${p.data}T${pad(Math.floor(m / 60))}:${pad(m % 60)}:00${tz}`;
  return `https://outlook.live.com/calendar/0/action/compose?${query({ subject: SUMMARY_ICS, startdt: t(i), enddt: t(f),
    location: sede?.online ? 'Videochiamata' : indirizzo(sede), body: `Codice prenotazione: ${p.ref}. Per spostare o annullare: ${linkGestione(p)}` })}`;
}

// --- S22 Offline ---
function statoRete() {
  const b = $('#offline');
  b.hidden = navigator.onLine;
  if (!navigator.onLine) b.innerHTML = `${icon('wifioff', 'ico-sm')}<span>Sei offline: le prenotazioni salvate restano visibili.</span>`;
}
window.addEventListener('online', statoRete);
window.addEventListener('offline', statoRete);

async function ricaricaCatalogo() {
  try { state.catalogo = await api.catalogo(); } catch (e) { erroreRete(e); }
}


/* ==========================================================================
   11. PANNELLO DEMO (fuori dal design: grigio, monospazio, tratteggiato)
   ========================================================================== */

function disegnaDemo() {}
function aggiornaDemo() {}

/* ==========================================================================
   12. AVVIO
   ========================================================================== */

for (const handlers of [CLICK,CHANGE]) for (const key of Object.keys(handlers)) if (/^(demo-|cfg-)/.test(key)) delete handlers[key];
CLICK['simula-wa']=()=>{};
CLICK['esci']=()=>{location.href=BOOT.back;};

CLICK.avvisami=()=>{location.href=STUDIO.telHref;};

(async function avvio() {
  try { history.replaceState({ screen: 'benvenuto', idx: 0 }, '', '#benvenuto'); } catch (e) { /* file:// */ }
  state.ui.cellulareInput = ''; // No example patient data in the live form.
  disegnaDemo();
  statoRete();
  render();
  await ricaricaCatalogo();
  render();
  avviato = true;
  const params=new URLSearchParams(location.search); const combination=combinazioni().find(c=>['prestazione','professionista','sede'].every(key=>!params.get(key)||params.get(key)===c[key])); if(combination){for(const key of ['prestazione','professionista','sede'])if(params.get(key))state.draft[key]=combination[key];render();}
  const bookingRef=new URLSearchParams(location.search).get('ref'); if(bookingRef && /^[A-Z2-9]{6}$/.test(bookingRef)) vai('dettaglio',{ref:bookingRef});
  if (window.ResizeObserver) new ResizeObserver(aggiornaAltezzaBarra).observe($('#bar'));
})();

