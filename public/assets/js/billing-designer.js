(() => {
 'use strict';
 const seed=JSON.parse(document.getElementById('bd-initial').textContent), catalog=seed.catalog;
 const form=document.getElementById('bd-form'), canvas=document.getElementById('bd-canvas'), props=document.getElementById('bd-properties'), status=document.getElementById('bd-status');
 let model=seed.model, selected=0, past=[], future=[], drag=null, dirty=false, busy=false;
 let liveTimer=null, liveRevision=0, livePending=false, pendingMode=null;
 function scheduleLive(){liveRevision++;livePending=true;clearTimeout(liveTimer);$('bd-live-status').textContent='Aggiornamento…';liveTimer=setTimeout(()=>preview('live'),450);}
 function fitLive(){const frame=$('bd-live-frame'),viewport=$('bd-live-viewport');if(!frame.contentDocument)return;const scale=Math.min(1,viewport.clientWidth/794);const height=Math.max(1123,frame.contentDocument.documentElement.scrollHeight);frame.style.width='794px';frame.style.height=height+'px';frame.style.transform='scale('+scale+')';$('bd-live-paper').style.height=(height*scale)+'px';}
 const dedicated=new Set(['line_items','notes','terms','footer','issuer_extra']);
 const $=id=>document.getElementById(id);
 const node=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
 function button(text,action,title){const b=node('button',text,'bd-mini');b.type='button';b.title=title||text;b.setAttribute('aria-label',title||text);b.onclick=action;return b;}
 function snapshot(){const fields={};form.querySelectorAll('[name]').forEach(el=>{if(el.name!==seed.csrfName&&el.name!=='designer_json')fields[el.name]=el.type==='checkbox'?el.checked:el.value;});return JSON.stringify({model,fields});}
 function restore(raw){const state=JSON.parse(raw);model=state.model;Object.entries(state.fields).forEach(([key,value])=>{const el=form.elements[key];if(el) {if(el.type==='checkbox')el.checked=value;else el.value=value;}});}
 function changed(fn,refreshProperties=true){past.push(snapshot());if(past.length>50)past.shift();future=[];fn();dirty=true;render(refreshProperties);}
 function newBlock(){return {name:'Nuovo blocco',span:12,background:'#ffffff',color:'#202b3c',align:'left',font:11,padding:3,border:false,page_break:false,elements:[]};}
 function addBlock(){if(model.blocks.length>=40)return message('Puoi usare fino a 40 blocchi.');changed(()=>{model.blocks.push(newBlock());selected=model.blocks.length-1;});}
 function message(text){status.textContent=text;}
 function select(i){selected=i;render();}
 function warnings(){const types=model.blocks.flatMap(b=>b.elements.map(e=>e.type));const notes=[];if(!types.includes('line_items'))notes.push('La tabella prestazioni non è presente.');if(!types.includes('total'))notes.push('Il totale documento non è presente.');if(model.blocks.some(b=>!b.elements.length))notes.push('I blocchi vuoti vengono mantenuti come spazio.');return notes.join(' ');}
 function moveBlock(from,to){if(to<0||to>=model.blocks.length||from===to)return;changed(()=>{const [b]=model.blocks.splice(from,1);model.blocks.splice(to,0,b);selected=to;});}
 function addElement(type,target=selected){
  const b=model.blocks[target];if(!b)return;
  if(dedicated.has(type) && b.elements.length){if(model.blocks.length>=40)return message('Massimo 40 blocchi.');changed(()=>{const next=newBlock();next.name=catalog[type];next.elements=[{type,text:'',label:'',show_label:false}];model.blocks.splice(target+1,0,next);selected=target+1;});message('Inserito in un blocco dedicato per consentire la stampa su più pagine.');return;}
  if(b.elements.some(e=>dedicated.has(e.type)))return message('Questo blocco contiene un testo lungo o una tabella. Aggiungi un altro blocco.');
  if(b.elements.length>=12)return message('Massimo 12 elementi per blocco.');
  changed(()=>{if(dedicated.has(type))b.span=12;b.elements.push({type,text:type==='text'?'Scrivi qui il tuo testo':'',label:'',show_label:!['title','subtitle','text','logo','divider','spacer','signature'].includes(type)});});
 }
 function transfer(from,index,to,at){
  if(from===to){changed(()=>{const [e]=model.blocks[from].elements.splice(index,1);model.blocks[to].elements.splice(at,0,e);selected=to;});return;}
  const b=model.blocks[to],e=model.blocks[from].elements[index];
  if(b.elements.length>=12 || b.elements.some(x=>dedicated.has(x.type)) || (dedicated.has(e.type)&&b.elements.length))return message('Elemento non spostato: tabelle e testi lunghi richiedono un blocco dedicato.');
  changed(()=>{model.blocks[from].elements.splice(index,1);b.elements.splice(at,0,e);if(dedicated.has(e.type))b.span=12;selected=to;});
 }
 function pointerDrag(handle,payload){
  handle.style.touchAction='none';
  handle.addEventListener('pointerdown',event=>{
   if(event.button!==0)return;
   const startX=event.clientX,startY=event.clientY;let moved=false,target=-1,insertion=0;
   handle.setPointerCapture(event.pointerId);
   const move=e=>{
    if(Math.abs(e.clientX-startX)+Math.abs(e.clientY-startY)<8&&!moved)return;
    moved=true;e.preventDefault();
    const point=document.elementFromPoint(e.clientX,e.clientY),hit=point?.closest('.bd-block');
    target=hit?Array.from(canvas.children).indexOf(hit):-1;
    const targetElement=point?.closest('.bd-element');
    insertion=hit?(targetElement?Array.from(hit.querySelectorAll('.bd-element')).indexOf(targetElement):model.blocks[target].elements.length):0;
    Array.from(canvas.children).forEach((card,i)=>card.classList.toggle('drag-over',i===target));
    if(e.clientY<75)window.scrollBy(0,-18);else if(e.clientY>window.innerHeight-65)window.scrollBy(0,18);
   };
   const end=e=>{
    handle.removeEventListener('pointermove',move);handle.removeEventListener('pointerup',end);handle.removeEventListener('pointercancel',cancel);
    document.querySelectorAll('.drag-over').forEach(n=>n.classList.remove('drag-over'));
    if(!moved||target<0)return;
    if(payload.kind==='block')moveBlock(payload.block,target);
    else if(payload.kind==='palette')addElement(payload.type,target);
    else transfer(payload.block,payload.element,target,insertion);
   };
   const cancel=()=>{target=-1;end();};
   handle.addEventListener('pointermove',move);handle.addEventListener('pointerup',end);handle.addEventListener('pointercancel',cancel);
  });
 }
 function dragHandle(payload,label){const b=button('⠿',()=>{},label);b.classList.add('bd-handle');pointerDrag(b,payload);return b;}
 function render(refreshProperties=true){
  selected=Math.max(0,Math.min(selected,model.blocks.length-1));canvas.replaceChildren();
  model.blocks.forEach((block,i)=>{
   const card=node('section',undefined,'bd-block'+(selected===i?' selected':'')+(block.page_break?' bd-page-break':''));card.style.gridColumn=(block.page_break?'1 / span ':'span ')+block.span;card.setAttribute('aria-label',block.name);card.tabIndex=0;
   card.addEventListener('click',()=>{if(selected!==i)select(i);});card.addEventListener('keydown',e=>{if(e.target===card&&(e.key==='Enter'||e.key===' ')){e.preventDefault();select(i);}});
   card.addEventListener('dragover',e=>{e.preventDefault();card.classList.add('drag-over');});card.addEventListener('dragleave',()=>card.classList.remove('drag-over'));
   card.addEventListener('drop',e=>{e.preventDefault();e.stopPropagation();card.classList.remove('drag-over');const d=drag;drag=null;if(!d)return;if(d.kind==='block')moveBlock(d.block,i);else if(d.kind==='palette')addElement(d.type,i);else transfer(d.block,d.element,i,block.elements.length);});
   const bar=node('div',undefined,'bd-block-bar');bar.append(dragHandle({kind:'block',block:i},'Trascina blocco '+block.name),node('strong',block.name));
   bar.append(button('↑',e=>{e.stopPropagation();moveBlock(i,i-1);},'Sposta blocco su'),button('↓',e=>{e.stopPropagation();moveBlock(i,i+1);},'Sposta blocco giù'));
   const body=node('div',undefined,'bd-block-content');Object.assign(body.style,{background:block.background,color:block.color,textAlign:block.align,fontSize:block.font+'pt',padding:block.padding*2+'px'});
   block.elements.forEach((el,j)=>{
    const row=node('div',undefined,'bd-element');row.append(dragHandle({kind:'element',block:i,element:j},'Trascina '+catalog[el.type]));const label=node('div',el.type==='text'?(el.text||'Testo libero'):((el.show_label&&el.label)?el.label+' · ':'')+catalog[el.type],'bd-element-name');row.append(label);
    const tools=node('div',undefined,'bd-element-tools');tools.append(button('×',e=>{e.stopPropagation();changed(()=>block.elements.splice(j,1));},'Rimuovi '+catalog[el.type]));row.append(tools);body.append(row);
   });
   if(!block.elements.length)body.append(node('div','Seleziona un elemento a sinistra o trascinalo qui.','bd-empty'));
   card.append(bar,body);canvas.append(card);
  });
  $('bd-count').textContent=model.blocks.length+' blocchi';$('bd-undo').disabled=!past.length;$('bd-redo').disabled=!future.length;$('bd-json').value=JSON.stringify(model);if(refreshProperties)renderProperties();message(warnings());scheduleLive();
 }
 function inputControl(label,value,onchange,type='text',options){
  const wrap=node('label',label);const input=node(options?'select':type==='textarea'?'textarea':'input');
  if(options)options.forEach(([v,t])=>{const o=node('option',t);o.value=v;input.append(o);});else if(type!=='textarea')input.type=type;
  if(type==='checkbox')input.checked=!!value;else input.value=value;
  if(type==='textarea'){input.rows=4;}
  input.addEventListener((type==='text'||type==='textarea')?'input':'change',()=>changed(()=>onchange(type==='checkbox'?input.checked:input.value),label==='Blocco selezionato'));
  wrap.append(input);props.append(wrap);return input;
 }
 function renderProperties(){
  props.replaceChildren();const b=model.blocks[selected];if(!b)return;
  inputControl('Blocco selezionato',selected,v=>{selected=+v;},'select',model.blocks.map((b,i)=>[i,(i+1)+'. '+b.name]));
  inputControl('Nome del blocco (solo editor)',b.name,v=>b.name=v);
  inputControl('Larghezza',b.span,v=>{if(b.elements.some(e=>dedicated.has(e.type))&&+v!==12){message('Questo contenuto richiede larghezza intera.');return;}b.span=+v;},'select',[[12,'Intera'],[6,'½ pagina'],[4,'⅓ pagina']]);
  inputControl('Sfondo',b.background,v=>b.background=v,'color');inputControl('Colore testo',b.color,v=>b.color=v,'color');
  inputControl('Allineamento',b.align,v=>b.align=v,'select',[['left','Sinistra'],['center','Centro'],['right','Destra']]);
  inputControl('Dimensione testo',b.font,v=>b.font=+v,'select',[9,10,11,12,13,14].map(n=>[n,n+' pt']));
  inputControl('Spazio interno',b.padding,v=>b.padding=+v,'select',[0,1,2,3,4,5,6].map(n=>[n,n+' mm']));
  inputControl('Bordo visibile',b.border,v=>b.border=v,'checkbox');inputControl('Inizia su una nuova pagina',b.page_break,v=>b.page_break=v,'checkbox');
  props.append(button('Duplica blocco',()=>{if(model.blocks.length>=40)return;changed(()=>{model.blocks.splice(selected+1,0,JSON.parse(JSON.stringify(b)));selected++;});}),button('Elimina blocco',()=>{if(model.blocks.length===1)return message('Mantieni almeno un blocco.');changed(()=>model.blocks.splice(selected,1));}));
  props.append(node('hr'),node('h2','Contenuti del blocco'));
  b.elements.forEach((el,i)=>{
   props.append(node('strong',(i+1)+'. '+catalog[el.type]));
   if(el.type==='text')inputControl('Testo libero',el.text,v=>el.text=v,'textarea');
   const sharedTexts={terms:['branding_terms_text','Testo informativa'],footer:['branding_footer_note','Testo footer'],issuer_extra:['branding_header_extra','Righe professionista']};
   if(sharedTexts[el.type]){
    const [fieldName,caption]=sharedTexts[el.type],field=form.elements[fieldName];
    const inherited=el.type==='terms'&&!field.value.trim()&&!!el.label.trim();
    inputControl(caption,inherited?el.label:field.value,v=>{
     if(el.type==='terms'&&!field.value.trim()&&el.label.trim()){el.label='';}
     field.value=v;
    },'textarea');
    if(inherited)props.append(node('p','Il testo inserito in precedenza come etichetta viene usato come informativa. Puoi modificarlo qui.','bd-help'));
   }
   if(!['logo','divider','spacer','line_items'].includes(el.type)){
    inputControl('Mostra etichetta',el.show_label,v=>el.show_label=v,'checkbox');inputControl('Etichetta personalizzata',el.label||'',v=>el.label=v);
   }
   const actions=node('div',undefined,'bd-element-tools');actions.append(button('↑',()=>{if(i>0)transfer(selected,i,selected,i-1);},'Sposta elemento su'),button('↓',()=>{if(i<b.elements.length-1)transfer(selected,i,selected,i+1);},'Sposta elemento giù'),button('Rimuovi',()=>changed(()=>b.elements.splice(i,1))));props.append(actions);
   const wrap=node('label','Sposta in un altro blocco'), sel=node('select');const empty=node('option','Scegli blocco…');empty.value='';sel.append(empty);model.blocks.forEach((other,j)=>{if(j===selected)return;const o=node('option',(j+1)+'. '+other.name);o.value=j;sel.append(o);});sel.onchange=()=>{if(sel.value!=='')transfer(selected,i,+sel.value,model.blocks[+sel.value].elements.length);};wrap.append(sel);props.append(wrap,node('hr'));
  });
 }
 function renderCatalog(){const q=$('bd-search').value.toLowerCase();$('bd-catalog').replaceChildren();Object.entries(catalog).filter(([,name])=>name.toLowerCase().includes(q)).forEach(([type,name])=>{const b=button('＋ '+name,()=>addElement(type),name);b.className='bd-catalog-item';b.draggable=true;b.ondragstart=e=>{drag={kind:'palette',type};e.dataTransfer.setData('text/plain',type);};b.ondragend=()=>{drag=null;};$('bd-catalog').append(b);});}
 $('bd-add').onclick=addBlock;$('bd-add-bottom').onclick=addBlock;$('bd-search').oninput=renderCatalog;
 $('bd-undo').onclick=()=>{if(!past.length)return;future.push(snapshot());restore(past.pop());dirty=true;render();};
 $('bd-redo').onclick=()=>{if(!future.length)return;past.push(snapshot());restore(future.pop());dirty=true;render();};
 form.addEventListener('focusin',e=>{if(e.target.name&&e.target.name!==seed.csrfName&&e.target.name!=='designer_json') {past.push(snapshot());if(past.length>50)past.shift();future=[];}});
 form.addEventListener('input',e=>{if(e.target.name && e.target.name!=='designer_json'){dirty=true;scheduleLive();}});
 form.addEventListener('change',e=>{if(e.target.name && e.target.name!=='designer_json')scheduleLive();});
 form.addEventListener('submit',e=>{if(busy){e.preventDefault();return;}const error=validate();if(error){e.preventDefault();message(error);return;}$('bd-json').value=JSON.stringify(model);dirty=false;});
 function validate(){
  for(const b of model.blocks){if(b.elements.length>12)return 'Troppi elementi nel blocco '+b.name;if(b.elements.some(e=>dedicated.has(e.type))&&(b.span!==12||b.elements.length!==1))return 'Il blocco '+b.name+' richiede larghezza intera e un solo contenuto.';}
  return '';
 }
 async function preview(mode){
  if(busy){if(mode!=='live')pendingMode=mode;return;}const error=validate();if(error){$('bd-live-status').textContent='Anteprima non aggiornata: '+error;livePending=false;return message(error);}busy=true;livePending=false;clearTimeout(liveTimer);const revision=liveRevision;if(mode!=='live')message('Preparazione '+(mode==='pdf'?'PDF':'anteprima')+'…');
  $('bd-json').value=JSON.stringify(model);const data=new FormData(form);data.set('preview_mode',mode);
  form.querySelector('button[type=submit]').disabled=true;$('bd-preview').disabled=true;$('bd-pdf').disabled=true;
  try {
   const response=await fetch(form.dataset.previewUrl,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
   if(!(response.headers.get('content-type')||'').includes('application/json'))throw Error('Sessione scaduta o risposta non valida. Ricarica la pagina dopo aver copiato eventuali testi non salvati.');
   const result=await response.json();if(result.csrf)form.elements[seed.csrfName].value=result.csrf;if(!response.ok||result.error)throw Error(result.error||'Anteprima non disponibile.');
   if(mode==='pdf'){
    const bytes=Uint8Array.from(atob(result.pdf),c=>c.charCodeAt(0));const url=URL.createObjectURL(new Blob([bytes],{type:'application/pdf'}));const a=node('a');a.href=url;a.download='anteprima-modello.pdf';a.click();setTimeout(()=>URL.revokeObjectURL(url),60000);message('PDF di prova generato: '+result.pages+' pagine. Controlla il file prima di salvare.');
   }else{
    if(revision===liveRevision){$('bd-live-frame').srcdoc=result.html;$('bd-live-status').textContent='Aggiornata';}
    if(mode!=='live'){$('bd-frame').srcdoc=result.html;$('bd-preview-dialog').showModal();message('Anteprima aggiornata con dati di esempio.');}
   }
  }catch(e){$('bd-live-status').textContent='Anteprima non aggiornata: '+e.message;if(mode!=='live')message(e.message);}finally{busy=false;form.querySelector('button[type=submit]').disabled=false;$('bd-preview').disabled=false;$('bd-pdf').disabled=false;if(pendingMode){const next=pendingMode;pendingMode=null;setTimeout(()=>preview(next),0);}else if(livePending){clearTimeout(liveTimer);liveTimer=setTimeout(()=>preview('live'),150);}}
 }
 $('bd-preview').onclick=()=>preview('html');$('bd-pdf').onclick=()=>preview('pdf');$('bd-close-preview').onclick=()=>$('bd-preview-dialog').close();
 window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
 $('bd-live-frame').addEventListener('load',fitLive);
 new ResizeObserver(fitLive).observe($('bd-live-viewport'));
 renderCatalog();render();
})();
