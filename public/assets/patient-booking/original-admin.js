'use strict';
// Behaviour layer only. The document, inline styles and artwork are the exported A01–A05 originals.
(()=>{
 const boot=window.bookingAdmin;if(!boot)return;
 let state=boot.state,draft=structuredClone(state.draft),csrf=boot.csrf,timer,queue=Promise.resolve(),dirty=false;
 const $=s=>document.querySelector(s),all=s=>[...document.querySelectorAll(s)];
 const value=path=>path.split('.').reduce((v,k)=>v?.[k],draft);
 function set(path,v){const parts=path.split('.');let p=draft;for(const k of parts.slice(0,-1))p=p[k]??={};p[parts.at(-1)]=v;}
 const status=all('p').find(p=>p.textContent.includes('Bozza salvata in automatico'));
 const online=$('#online-attiva');if(online){online.checked=!state.suspended;online.addEventListener('change',async()=>{try{await send('suspend',{suspended:!online.checked});paint();}catch(e){online.checked=!state.suspended;paint();message(e.message,true);}});}
 function message(text,error=false){if(status){status.textContent=text;status.setAttribute('role',error?'alert':'status');status.style.color=error?'#b42318':'#64748b';}else alert(text);}
 async function send(action,payload={}){
  const execute=async()=>{const response=await fetch(boot.editorUrl+'/'+action,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','If-Match':String(state.etag),[boot.csrfHeader]:csrf},body:JSON.stringify(payload)});const result=await response.json();if(result.csrf)csrf=result.csrf;if(!response.ok)throw Error(response.status===409?'Qualcun altro ha modificato la bozza nel frattempo. Ricarica per vedere la versione aggiornata.':result.messaggio||'Salvataggio non riuscito.');state=result.state;return state;};
  const result=queue.then(execute);queue=result.catch(()=>{});return result;
 }
 async function save(){clearTimeout(timer);const snapshot=structuredClone(draft);try{await send('save',{draft:snapshot});if(JSON.stringify(snapshot)===JSON.stringify(draft))dirty=false;message('Bozza salvata in automatico.');}catch(e){message(e.message,true);throw e;}}
 function changed(){dirty=true;clearTimeout(timer);if(status)message('Modifiche non salvate.');timer=setTimeout(()=>save().catch(()=>{}),1000);paint();}
 function paint(){
  all('input[role=switch]').forEach(input=>{const track=input.nextElementSibling;if(!track?.matches('[data-ctl]'))return;const knob=track.firstElementChild;track.style.background=input.checked?'#00795f':'#ffffff';track.style.border=input.checked?'0':'1.5px solid #64748b';if(knob){Object.assign(knob.style,{left:input.checked?'21px':'3.5px',top:input.checked?'3px':'3.5px',width:input.checked?'20px':'16px',height:input.checked?'20px':'16px',background:input.checked?'#ffffff':'#64748b'});}if(track.nextElementSibling){track.nextElementSibling.textContent=input.checked?'Attivo':'Spento';track.nextElementSibling.style.color=input.checked?'#00795f':'#64748b';}});
  all('input[type=radio]').filter(i=>i.name.startsWith('dato-')).forEach(i=>{const l=i.closest('label');Object.assign(l.style,{fontWeight:i.checked?'600':'500',color:i.checked?'#00795f':'#1a1a2e',background:i.checked?'#ffffff':'',boxShadow:i.checked?'0 0 0 1.5px #00795f':''});});
 }
 function bind(id,path,convert){const el=document.getElementById(id);if(!el)return;const check=el.type==='checkbox';if(check)el.checked=!!value(path);else el.value=value(path)??'';el.addEventListener(check||el.tagName==='SELECT'?'change':'input',()=>{set(path,convert?convert(el.value):check?el.checked:el.value);changed();});}
 const bindings={
  'scelta-primo':'scelte.primo_disponibile','scelta-salta-prof':'scelte.salta_professionista_se_unico','scelta-salta-sede':'scelte.salta_sede_se_unica',
  'logo-nome':'aspetto.mostra_nome_con_logo','testo-titolo':'testi.benvenuto_titolo','testo-sottotitolo':'testi.benvenuto_sottotitolo','testo-riepilogo':'testi.nota_riepilogo',
  'url-privacy':'comunicazioni.privacy_url','mostra-prestazione':'comunicazioni.mostra_prestazione_nei_messaggi',
  'finestra':'regole.giorni_prenotabili','sposta':'regole.preavviso_modifica_ore','scadenza':'accesso.ore_scadenza_richieste','sms-tetto':'verifica.sms_tetto_giornaliero',
  'tetto-codici':'protezioni.email_tetto_giornaliero','soglia-avviso':'protezioni.soglia_anomalia_ora','limite-attive':'limiti.attive_per_recapito','limite-prestazione':'limiti.stessa_prestazione','limite-assenze':'limiti.assenze_per_approvazione'
 };
 for(const [id,p]of Object.entries(bindings))bind(id,p,typeof value(p)==='number'?Number:null);
 bind('preavviso','regole.preavviso_minimo_min',v=>Number(v)*60);if($('#preavviso'))$('#preavviso').value=value('regole.preavviso_minimo_min')/60;
 for(const [name,key]of Object.entries({'dato-cellulare':'cellulare','dato-email':'email','dato-nascita':'nascita','dato-cf':'codice_fiscale','dato-note':'note'}))all('input[name='+name+']').forEach(el=>{el.checked=el.value===draft.dati[key];el.addEventListener('change',()=>{draft.dati[key]=el.value;changed();});});
 for(const [id,mode]of Object.entries({'chi-archivio':'archivio','chi-approvazione':'approvazione','chi-tutti':'aperta','chi-invito':'invito','acc-archivio':'archivio','acc-approvazione':'approvazione','acc-aperta':'aperta','acc-invito':'invito','var-archivio':'archivio','var-approvazione':'approvazione','var-aperta':'aperta','var-invito':'invito'})){const el=document.getElementById(id);if(el){el.checked=draft.accesso.modalita===mode;el.addEventListener('change',()=>{draft.accesso.modalita=mode;changed();});}}
 for(const [id,mode]of Object.entries({'rec-uno':'almeno_uno','rec-cell':'cellulare','rec-email':'email','rec-facoltativo':'facoltativo'})){const el=document.getElementById(id);if(el){el.checked=draft.senza_verifica.recapito===mode;el.addEventListener('change',()=>{draft.senza_verifica.recapito=mode;changed();});}}
 for(const method of ['whatsapp','email','sms']){const el=document.getElementById('metodo-'+method);if(el){el.checked=draft.verifica.metodi.includes(method);el.addEventListener('change',()=>{draft.verifica.metodi=el.checked?[...new Set([...draft.verifica.metodi,method])]:draft.verifica.metodi.filter(m=>m!==method);changed();});}}
 for(const id of ['senza-registrazione','senza-registrazione-var']){const el=document.getElementById(id);if(el){el.checked=!draft.verifica.richiesta;el.addEventListener('change',()=>{draft.verifica.richiesta=!el.checked;draft.passi.find(p=>p.id==='verifica').attivo=!el.checked;changed();});}}
 const stepList=$('ol[aria-label="Schermate del paziente, in ordine"]');
 if(stepList){const ids=['prestazione','professionista','sede','orario','perchi','verifica','dati','riepilogo'],rows={};[...stepList.children].forEach((row,i)=>{row.dataset.step=ids[i];rows[ids[i]]=row;const input=row.querySelector('input');if(input){input.checked=!!draft.passi.find(p=>p.id===ids[i])?.attivo;input.addEventListener('change',()=>{draft.passi.find(p=>p.id===ids[i]).attivo=input.checked;if(ids[i]==='verifica')draft.verifica.richiesta=input.checked;changed();});}
  row.querySelectorAll('button[aria-label^="Sposta"]').forEach(button=>button.addEventListener('click',()=>{const order=[...stepList.children],at=order.indexOf(row),delta=button.getAttribute('aria-label').startsWith('Sposta su')?-1:1;if(at+delta<0||at+delta>=order.length-1)return;order.splice(at,1);order.splice(at+delta,0,row);order.forEach(r=>stepList.append(r));orderChanged();}));
  if(ids[i]!=='riepilogo'){row.draggable=true;row.addEventListener('dragstart',e=>e.dataTransfer.setData('text/plain',ids[i]));row.addEventListener('dragover',e=>e.preventDefault());row.addEventListener('drop',e=>{e.preventDefault();const from=rows[e.dataTransfer.getData('text/plain')];if(from&&from!==row){stepList.insertBefore(from,row);orderChanged();}});}
 });
 function orderChanged(){draft.passi=[...stepList.children].map(r=>draft.passi.find(p=>p.id===r.dataset.step));numberSteps();changed();}
 function numberSteps(){[...stepList.children].forEach((r,i)=>{const n=r.querySelector('span[aria-hidden=true]');if(n)n.textContent=i+1;r.querySelectorAll('button[aria-label^="Sposta"]').forEach(b=>b.disabled=r.dataset.step==='riepilogo'||(b.getAttribute('aria-label').startsWith('Sposta su')?i===0:i>=6));});}
 draft.passi.forEach(p=>{if(rows[p.id])stepList.append(rows[p.id]);});numberSteps();
 all('button').filter(b=>b.textContent.trim()==='Ordine predefinito').forEach(b=>b.addEventListener('click',()=>{ids.forEach(id=>stepList.append(rows[id]));orderChanged();}));
 }
 function options(id,bucket,path){const el=document.getElementById(id);if(!el)return;el.replaceChildren(new Option('Nessuna',''));boot.catalog[bucket].forEach(item=>el.add(new Option([item.titolo,item.nome,item.cognome].filter(Boolean).join(' '),item.id)));bind(id,path,v=>v||null);}
 options('prestazione-predefinita','prestazioni','scelte.prestazione_predefinita');options('professionista-predefinito','professionisti','scelte.professionista_predefinito');
 const logo=$('#logo-file');if(logo)logo.addEventListener('change',async()=>{const file=logo.files[0];if(!file)return;if(file.size>1048576){message('Il file supera 1 MB. Caricane uno più leggero.',true);return;}const reader=new FileReader();reader.onload=()=>{draft.aspetto.logo_url=reader.result;changed();};reader.readAsDataURL(file);});
 all('button').forEach(b=>{const text=b.textContent.trim();if(text==='Rimuovi il logo')b.addEventListener('click',()=>{draft.aspetto.logo_url=null;changed();});
  if(text==='Pubblica'||text==='Salva le modifiche')b.addEventListener('click',async()=>{try{await save();await send('publish');message('Pubblicata la revisione '+state.publishedRevision+'. La pagina dei pazienti è aggiornata.');}catch(e){message(e.message,true);}});
  if(text==='Scarta la bozza'||text==='Annulla le modifiche')b.addEventListener('click',async()=>{if(!confirm('Scartare le modifiche della bozza?'))return;clearTimeout(timer);try{await send('discard');dirty=false;location.reload();}catch(e){message(e.message,true);}});
  if(text==='Copia il link'||text==='Copia')b.addEventListener('click',()=>navigator.clipboard.writeText(new URL(boot.patientUrl,location.href).href).catch(e=>message(e.message,true)));
  if(text==='Sospendi le prenotazioni online')b.addEventListener('click',async()=>{try{await send('suspend',{suspended:true});if(online)online.checked=false;paint();message('Prenotazioni online sospese.');}catch(e){message(e.message,true);}});
 });
 all('a[href="#assistenza"]').forEach(a=>{a.href=boot.adminBase+'/A04-configurazione-flusso';a.textContent='Configura il percorso';});
 all('a').filter(a=>a.textContent.trim()==='Configurazione JSON').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();const blob=new Blob([JSON.stringify(draft,null,2)],{type:'application/json'}),url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download='configurazione-prenotazione.json';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}));
 all('input[id^=link-]').forEach(el=>el.value=new URL(boot.patientUrl,location.href).href);
 // A static reference remains available separately; example histories are never presented as saved production revisions.
 const historySection=$('#titolo-cronologia')?.closest('section');
 if(historySection){const list=historySection.querySelector('ol');const rowTemplate=list?.lastElementChild?.cloneNode(true);if(list&&rowTemplate){list.replaceChildren();for(const item of [...state.history].reverse()){const row=rowTemplate.cloneNode(true);const text=row.querySelector('div div');if(text){text.textContent='Versione '+item.revision;if(text.nextElementSibling)text.nextElementSibling.textContent=new Date(item.date).toLocaleString('it-IT')+(item.note?' · '+item.note:'');}const button=row.querySelector('button');if(button){button.setAttribute('aria-label','Ripristina la versione '+item.revision);button.addEventListener('click',async()=>{if(!confirm('Ripristinare la revisione '+item.revision+'? La bozza attuale verrà sostituita. La pagina pubblica non cambia finché non pubblichi.'))return;try{clearTimeout(timer);await send('restore',{revision:item.revision});dirty=false;location.reload();}catch(e){message(e.message,true);}});}list.append(row);}if(!state.history.length){const p=document.createElement('p');p.textContent='Nessuna versione precedente.';list.append(p);}}}
 if(status)message('Configurazione pubblicata: revisione '+state.publishedRevision+'. Le modifiche si salvano in bozza.');
 window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
 paint();
})();
