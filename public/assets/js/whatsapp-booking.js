(() => {
  'use strict';
  const app=document.getElementById('wb-app'); if(!app)return;
  const form=document.getElementById('wb-config'), chat=document.getElementById('wb-chat'), message=document.getElementById('wb-message');
  const get=id=>document.getElementById(id), value=key=>new FormData(form).get('booking['+key+']');
  let reset=true,busy=false,clickedLabel='';
  const tab=name=>{app.querySelectorAll('[data-section]').forEach(el=>el.hidden=el.dataset.section!==name);app.querySelectorAll('[data-panel]').forEach(el=>el.setAttribute('aria-pressed',String(el.dataset.panel===name)));};
  app.querySelectorAll('[data-panel]').forEach(b=>b.addEventListener('click',()=>tab(b.dataset.panel)));
  // Browser validation must be able to focus fields on another tab.
  form.addEventListener('invalid',e=>{const section=e.target.closest('[data-section]');if(section)tab(section.dataset.section);},true);
  function draw(){
    const single=get('wb-single').checked, mode=value('patient_mode');
    get('wb-order').hidden=single;get('wb-keywords').hidden=value('start_mode')==='auto';get('wb-visits').hidden=single;get('wb-single-help').hidden=!single;
    get('wb-unknown').hidden=!['cf','both'].includes(mode);
    get('wb-patient-help').textContent={name:'Nome e cognome vengono richiesti a ogni prenotazione, senza ricerca per codice fiscale.',birth:'Nome, cognome e data di nascita vengono richiesti a ogni prenotazione.',cf:'Il codice fiscale associa la prenotazione al paziente esistente. Nome e cognome vengono richiesti solo per una nuova anagrafica.',both:'Il codice fiscale permette di cercare il paziente. Nome e cognome vengono richiesti in ogni caso.'}[mode]||'';
    const steps=[value('start_mode')==='auto'?'Primo messaggio':'Parola chiave di avvio',...(single?['Giorno']:value('order')==='visit'?['Prestazione','Giorno']:['Giorno','Prestazione']),'Orario',...(['cf','both'].includes(mode)?['Codice fiscale',mode==='both'?'Nome e cognome':'Gestione anagrafica']:['Nome e cognome',...(mode==='birth'?['Data di nascita']:[])]),'Riepilogo',value('confirmation')==='manual'?'Approvazione della segreteria':'Conferma appuntamento'];
    get('wb-steps').replaceChildren(...steps.map(text=>{const el=document.createElement('li');el.textContent=text;return el;}));
  }
  function bubble(text,kind=''){const p=document.createElement('p');p.textContent=text;p.className=kind;chat.append(p);chat.scrollTop=chat.scrollHeight;return p;}
  function restart(){reset=true;chat.replaceChildren();bubble('Invia un messaggio per iniziare una nuova simulazione.');}
  form.addEventListener('change',()=>{draw();restart();get('wb-save-state').textContent='Modifiche non salvate';});
  get('wb-reset').addEventListener('click',restart);
  get('wb-chat-form').addEventListener('submit',async e=>{
    e.preventDefault();if(busy||!message.value.trim())return;
    if(!form.reportValidity())return;
    const data=new FormData(form), text=message.value.trim();data.set('message',text);data.set('reset',reset?'1':'0');data.set('patient_found',get('wb-preview-found').value);
    const button=e.currentTarget.querySelector('button');busy=true;button.disabled=true;message.disabled=true;chat.querySelectorAll('button').forEach(b=>b.disabled=true);bubble(clickedLabel||text,'wb-patient');clickedLabel='';message.value='';
    try{
      const response=await fetch(app.dataset.previewUrl,{method:'POST',body:data,headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
      const result=await response.json();
      if(result.csrf)document.querySelectorAll('input[name="'+app.dataset.csrfName+'"]').forEach(el=>el.value=result.csrf);
      if(!response.ok)throw new Error(result.error||'Anteprima non disponibile. Ricarica la pagina.');
      reset=false;const reply=bubble(result.reply);
      const options=document.createElement('span');options.className='wb-chat-options';
      for(const choice of result.buttons||[]){const b=document.createElement('button');b.type='button';b.className='btn btn-default';b.textContent=choice.description||choice.title;b.addEventListener('click',()=>{if(busy)return;clickedLabel=b.textContent;message.value=choice.id;get('wb-chat-form').requestSubmit();});options.append(b);}
      reply.append(options);chat.scrollTop=chat.scrollHeight;
    }catch(error){bubble(error.message||'Connessione non disponibile.','wb-error');}
    finally{busy=false;button.disabled=false;message.disabled=false;message.focus();}
  });
  draw();
})();
