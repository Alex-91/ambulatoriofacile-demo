'use strict';
const form=document.querySelector('#workflow-form'),steps=document.querySelector('#workflow-steps');
let dirty=false;
function changed(){dirty=true;document.querySelector('#save-status').textContent='Modifiche non salvate.';}
function sync(){
 const rows=[...steps.children];document.querySelector('#step-order').value=rows.map(r=>r.dataset.step).join(',');
 rows.forEach((r,i)=>{r.querySelector('[data-move=up]').disabled=i===0||r.dataset.step==='riepilogo';r.querySelector('[data-move=down]').disabled=i>=rows.length-2;});
}
steps.addEventListener('click',e=>{const button=e.target.closest('[data-move]');if(!button)return;const row=button.closest('li');if(button.dataset.move==='up'&&row.previousElementSibling)steps.insertBefore(row,row.previousElementSibling);else if(row.nextElementSibling)steps.insertBefore(row.nextElementSibling,row);sync();changed();document.querySelector('#order-message').textContent='Ordine aggiornato. Salva per applicarlo.';});
form.addEventListener('input',changed);form.addEventListener('change',changed);
form.addEventListener('submit',e=>{sync();const rows=[...steps.children],active=rows.filter(r=>r.querySelector('[type=checkbox]').checked).map(r=>r.dataset.step);if(['prestazione','professionista','sede'].some(id=>active.includes(id)&&active.indexOf(id)>active.indexOf('orario'))){e.preventDefault();document.querySelector('#order-message').textContent='Data e orario deve seguire Prestazione, Professionista e Sede.';document.querySelector('#percorso').scrollIntoView();return;}dirty=false;});
window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
document.querySelector('#open-patient').addEventListener('click',()=>{if(dirty)document.querySelector('#save-status').textContent='La pagina paziente mostra la configurazione salvata. Salva prima per vedere queste modifiche.';});
sync();
