(function(){
'use strict';
const root=document.getElementById('billing-workspace');if(!root)return;
const data=JSON.parse(document.getElementById('bw-data').textContent),byId=new Map(data.map(d=>[String(d.id),d]));
const rows=Array.from(root.querySelectorAll('.bw-row')),selected=new Set();let active=null,reverse=false,cashTab='open',submitting=false;
const get=id=>document.getElementById(id),form=get('bw-bulk-form'),cashView=root.dataset.mode==='cash';
const fields=['search','period','from','to','date-kind','doctor','status','type','ts','collection','recipient','method'];
const value=name=>get('bw-'+name)?.value||'',money=new Intl.NumberFormat('it-IT',{style:'currency',currency:'EUR'});
const eligible=id=>rows.find(r=>r.dataset.id===String(id))?.dataset.tsSelectable==='1';
function open(row){
 active=row?.dataset.id||null;rows.forEach(r=>r.classList.toggle('is-selected',r===row));
 const panel=get('bw-detail');panel.replaceChildren();
 if(row)panel.append(get('bw-detail-'+active).content.cloneNode(true));
 else {const p=document.createElement('p');p.className='bw-detail-card bw-empty';p.textContent='Nessun documento da visualizzare.';panel.append(p);}
}
function inputs(ids){get('bw-bulk-inputs').replaceChildren();ids.forEach(id=>{const input=document.createElement('input');input.type='hidden';input.name='billing_document_ids[]';input.value=id;get('bw-bulk-inputs').append(input);});}
function updateSelection(){
 const visible=rows.filter(r=>!r.hidden),all=get('bw-select-all');
 all.checked=visible.length>0&&visible.every(r=>selected.has(r.dataset.id));
 all.indeterminate=visible.some(r=>selected.has(r.dataset.id))&&!all.checked;all.disabled=!visible.length;
 const tsIds=[...selected].filter(eligible);
 get('bw-selected-count').textContent='('+selected.size+' di '+visible.length+')';
 if(get('bw-send'))get('bw-send').disabled=submitting||!tsIds.length;
 if(get('bw-ts-count'))get('bw-ts-count').textContent='('+tsIds.length+')';
 get('bw-export').disabled=submitting||!selected.size;
 get('bw-selection-status').textContent=selected.size?selected.size+' fatture selezionate per l’export; '+tsIds.length+' disponibili per TS'+(selected.size>tsIds.length?' · '+(selected.size-tsIds.length)+' escluse dall’invio.':'.'):'';
 inputs([...selected]);
}
function matches(d){
 const query=value('search').trim().toLocaleLowerCase('it'),date=d[value('date-kind')||'date']||'';
 return (!query||[d.number,d.patient,d.tax,...(d.services||[])].join(' ').toLocaleLowerCase('it').includes(query))
 &&(!value('from')||date>=value('from'))&&(!value('to')||date&&date<=value('to'))
 &&(!value('doctor')||(value('doctor')==='unassigned'?!d.doctors.length:d.doctors.map(String).includes(value('doctor'))))
 &&(!value('status')||d.status===value('status'))&&(!value('type')||d.type===value('type'))
 &&(!value('recipient')||d.recipient===value('recipient'))&&(!value('method')||d.method===value('method'))
 &&(!value('ts')||(value('ts')==='selectable'?eligible(d.id):value('ts')==='unavailable'?!eligible(d.id):d.ts===value('ts')))
 &&(!value('collection')||(value('collection')==='overdue'?d.overdue:value('collection')==='today'?d.due>0&&d.due_date===root.dataset.today:!d.draft&&!d.email_sent));
}
function render(){
 const totals={net:0,cash:0,due:0,fees:0,drafts:0,overdue:0,today:0},counts={cash:0,due:0,overdue:0,today:0};let count=0;
 rows.forEach(row=>{
  const d=byId.get(row.dataset.id),base=matches(d)&&(!cashView||!d.draft);
  if(base){for(const k of ['net','cash','due','fees'])totals[k]+=Number(d[k])||0;if(d.draft)totals.drafts++;
   if(d.cash!==0)counts.cash++;if(d.due>0)counts.due++;
   if(d.overdue){totals.overdue+=d.due;counts.overdue++;}if(d.due>0&&d.due_date===root.dataset.today){totals.today+=d.due;counts.today++;}}
  const visible=base&&(!cashView||cashTab==='all'||(cashTab==='open'?d.due>0:d.cash!==0));
  row.hidden=!visible;if(visible)count++;else{selected.delete(row.dataset.id);row.querySelector('.bw-select').checked=false;}
 });
 for(const [key,total] of Object.entries(totals)){const el=get('bw-kpi-'+key);if(el)el.textContent=key==='drafts'?String(total):money.format(total/100);}
 for(const [key,n] of Object.entries(counts)){const el=get('bw-kpi-count-'+key);if(el)el.textContent=n+(n===1?' fattura':' fatture');}
 get('bw-count').textContent=count+' documenti';get('bw-empty').hidden=count!==0;
 if(!active||rows.find(r=>r.dataset.id===active)?.hidden)open(rows.find(r=>!r.hidden));updateSelection();
}
function range(){
 const [year,month]=root.dataset.today.split('-').map(Number),pad=n=>String(n).padStart(2,'0');let from='',to='';
 if(value('period')==='month'){from=year+'-'+pad(month)+'-01';to=year+'-'+pad(month)+'-'+new Date(year,month,0).getDate();}
 if(value('period')==='previous'){const d=new Date(year,month-2,1),y=d.getFullYear(),m=d.getMonth()+1;from=y+'-'+pad(m)+'-01';to=y+'-'+pad(m)+'-'+new Date(y,m,0).getDate();}
 if(value('period')==='year'){from=year+'-01-01';to=year+'-12-31';}
 if(value('period')!=='custom'){get('bw-from').value=from;get('bw-to').value=to;}render();
}
function send(ids){
 if(submitting||!ids.length)return;
 if(!confirm('Inviare '+ids.length+' fatture al Sistema TS? I documenti non idonei non saranno inclusi.'))return;
 inputs([]);const payload=document.createElement('input');payload.type='hidden';payload.name='billing_document_ids_json';payload.value=JSON.stringify(ids);get('bw-bulk-inputs').append(payload);submitting=true;if(get('bw-send'))get('bw-send').disabled=true;get('bw-export').disabled=true;root.querySelectorAll('.bw-send-one').forEach(b=>b.disabled=true);form.submit();
}
rows.forEach(row=>{
 row.addEventListener('click',e=>{if(e.target.closest('input,a,.bw-send-one'))return;open(row);});
 row.querySelector('.bw-select').addEventListener('change',e=>{e.target.checked?selected.add(row.dataset.id):selected.delete(row.dataset.id);updateSelection();});
 row.querySelector('.bw-send-one')?.addEventListener('click',()=>{if(eligible(row.dataset.id))send([row.dataset.id]);});
});
fields.forEach(name=>get('bw-'+name)?.addEventListener(name==='search'?'input':'change',()=>{if(name==='period'){range();return;}if(name==='from'||name==='to')get('bw-period').value='custom';render();}));
get('bw-reset').addEventListener('click',()=>{fields.forEach(name=>{const el=get('bw-'+name);if(el&&!el.disabled)el.value=name==='date-kind'?'date':'';});render();});
root.querySelectorAll('[data-cash-tab]').forEach(b=>b.addEventListener('click',()=>{cashTab=b.dataset.cashTab;root.querySelectorAll('[data-cash-tab]').forEach(t=>t.setAttribute('aria-pressed',String(t===b)));render();}));
get('bw-select-all').addEventListener('change',e=>{rows.filter(r=>!r.hidden).forEach(row=>{const box=row.querySelector('.bw-select');box.checked=e.target.checked;box.checked?selected.add(row.dataset.id):selected.delete(row.dataset.id);});updateSelection();});
form.addEventListener('submit',e=>{e.preventDefault();send([...selected].filter(eligible));});
get('bw-export').addEventListener('click',async()=>{
 if(submitting||!selected.size)return;
 const format=value('export-format'),max=format==='pdf'?50:500,ids=[...selected],parts=Math.ceil(ids.length/max);
 if(parts>1&&!confirm('Esportare tutte le '+ids.length+' fatture in '+parts+' file?'))return;
 submitting=true;
 const controls=[...root.querySelectorAll('button,select,input:not([type="hidden"])')],states=controls.map(el=>el.disabled);
 controls.forEach(el=>el.disabled=true);
 try{for(let offset=0;offset<ids.length;offset+=max){
  inputs(ids.slice(offset,offset+max));get('bw-export').disabled=false;
  get('bw-selection-status').textContent='Esportazione file '+(Math.floor(offset/max)+1)+' di '+parts+'…';
  const ok=await window.downloadSelectedBillingCsv(form,get('bw-export'),form.dataset.exportUrl,()=>{},format);
  if(!ok)break;
 }}finally{controls.forEach((el,i)=>el.disabled=states[i]);submitting=false;updateSelection();}
});
get('bw-sort').addEventListener('click',()=>{reverse=!reverse;[...rows].sort((a,b)=>byId.get(a.dataset.id).number.localeCompare(byId.get(b.dataset.id).number,'it',{numeric:true})*(reverse?1:-1)).forEach(r=>r.parentElement.append(r));});
render();
})();
