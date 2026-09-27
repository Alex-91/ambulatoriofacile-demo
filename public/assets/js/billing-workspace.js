(function(){
'use strict';
const root=document.getElementById('billing-workspace');if(!root)return;
const data=JSON.parse(document.getElementById('bw-data').textContent);
const byId=new Map(data.map(d=>[String(d.id),d]));
const rows=Array.from(root.querySelectorAll('.bw-row'));
const selected=new Set();let active=null,reverse=false;
const get=id=>document.getElementById(id);
const money=new Intl.NumberFormat('it-IT',{style:'currency',currency:'EUR'});
const fields=['search','period','doctor','status','type','ts','collection'];
const value=name=>get('bw-'+name)?.value||'';
function open(row){
active=row?.dataset.id||null;
rows.forEach(r=>r.classList.toggle('is-selected',r===row));
const panel=get('bw-detail');panel.replaceChildren();
if(row)panel.append(get('bw-detail-'+active).content.cloneNode(true));
else {const p=document.createElement('p');p.className='bw-detail-card bw-empty';p.textContent='Nessun documento da visualizzare.';panel.append(p);}
}
function updateSelection(){
const eligible=rows.filter(r=>!r.hidden&&r.querySelector('.bw-select'));
const all=get('bw-select-all');if(!all)return;
all.checked=eligible.length>0&&eligible.every(r=>selected.has(r.dataset.id));
all.indeterminate=eligible.some(r=>selected.has(r.dataset.id))&&!all.checked;all.disabled=!eligible.length;
get('bw-selected-count').textContent='('+selected.size+')';get('bw-send').disabled=!selected.size;
get('bw-bulk-inputs').replaceChildren();
selected.forEach(id=>{const input=document.createElement('input');input.type='hidden';input.name='billing_document_ids[]';input.value=id;get('bw-bulk-inputs').append(input);});
}
function render(){
const totals={net:0,cash:0,due:0,fees:0,drafts:0};let count=0;
rows.forEach(row=>{
 const d=byId.get(row.dataset.id),query=value('search').trim().toLocaleLowerCase('it');
 const visible=(!query||[d.number,d.patient,d.tax].join(' ').toLocaleLowerCase('it').includes(query))&&(!value('period')||d.date.startsWith(value('period')))&&(!value('doctor')||d.doctors.map(String).includes(value('doctor')))&&(!value('status')||d.status===value('status'))&&(!value('type')||d.type===value('type'))&&(!value('ts')||(value('ts')==='selectable'?!!row.querySelector('.bw-select'):d.ts===value('ts')))&&(!value('collection')||(value('collection')==='overdue'?d.overdue:!d.draft&&!d.email_sent));
 row.hidden=!visible;
 if(visible){count++;for(const k of ['net','cash','due','fees'])totals[k]+=d[k];if(d.draft)totals.drafts++;}
 else {selected.delete(row.dataset.id);const box=row.querySelector('.bw-select');if(box)box.checked=false;}
});
for(const [key,total] of Object.entries(totals)){const el=get('bw-kpi-'+key);if(el)el.textContent=key==='drafts'?String(total):money.format(total/100);}
get('bw-count').textContent=count+' documenti';get('bw-empty').hidden=count!==0;
if(!active||rows.find(r=>r.dataset.id===active)?.hidden)open(rows.find(r=>!r.hidden));
updateSelection();
}
rows.forEach(row=>{
 row.addEventListener('click',e=>{if(e.target.closest('input'))return;open(row);});
 row.querySelector('.bw-select')?.addEventListener('change',e=>{e.target.checked?selected.add(row.dataset.id):selected.delete(row.dataset.id);updateSelection();});
});
fields.forEach(name=>get('bw-'+name)?.addEventListener(name==='search'?'input':'change',render));
get('bw-reset').addEventListener('click',()=>{fields.forEach(name=>{if(get('bw-'+name))get('bw-'+name).value='';});render();});
get('bw-select-all')?.addEventListener('change',e=>{rows.filter(r=>!r.hidden).forEach(row=>{const box=row.querySelector('.bw-select');if(box){box.checked=e.target.checked;box.checked?selected.add(row.dataset.id):selected.delete(row.dataset.id);}});updateSelection();});
get('bw-bulk-form')?.addEventListener('submit',e=>{if(!selected.size||!confirm('Inviare '+selected.size+' documenti selezionati al Sistema TS?'))e.preventDefault();});
get('bw-sort').addEventListener('click',()=>{reverse=!reverse;const sorted=[...rows].sort((a,b)=>byId.get(a.dataset.id).number.localeCompare(byId.get(b.dataset.id).number,'it',{numeric:true})*(reverse?1:-1));sorted.forEach(r=>r.parentElement.append(r));});

render();
})();
