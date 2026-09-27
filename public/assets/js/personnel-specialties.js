(function () {
  'use strict';
  function init() {
    var root = document.getElementById('personnel-specialty-select');
    if (!root) return;
    var select = root.querySelector('select'), trigger = root.querySelector('.ps-trigger');
    var panel = root.querySelector('.ps-panel'), search = root.querySelector('#ps-search');
    var list = root.querySelector('.ps-options'), chips = root.querySelector('.ps-chips');
    var empty = root.querySelector('.ps-empty'), status = root.querySelector('.ps-status');
    var original = new Set(), generation = 0, pendingRefresh = false;
    function selected() { return Array.from(select.options).filter(function (o) { return o.selected; }); }
    function normal(value) { return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('it'); }
    function close(focus) { panel.hidden = true; trigger.setAttribute('aria-expanded', 'false'); if (focus) trigger.focus(); }
    function updateSummary() {
      var values = selected();
      root.querySelector('#ps-summary').textContent = values.length ? values.length + (values.length === 1 ? ' specialità selezionata' : ' specialità selezionate') : 'Seleziona una o più specialità';
      chips.replaceChildren();
      values.forEach(function (o) {
        var chip = document.createElement('span'), text = document.createElement('span'), remove = document.createElement('button');
        chip.className = 'ps-chip'; text.textContent = o.textContent + (o.dataset.active === '0' ? ' · Archiviata' : '');
        remove.type = 'button'; remove.textContent = '×'; remove.setAttribute('aria-label', 'Rimuovi ' + o.textContent);
        remove.addEventListener('click', function () { o.selected = false; updateSummary(); renderOptions(); trigger.focus(); });
        chip.append(text, remove); chips.append(chip);
      });
    }
    function renderOptions() {
      var query = normal(search.value.trim()), count = 0;
      list.replaceChildren();
      Array.from(select.options).forEach(function (o) {
        var archived = o.dataset.active === '0';
        if ((archived && !original.has(o.value) && !o.selected) || !normal(o.textContent).includes(query)) return;
        count++;
        var label = document.createElement('label'), checkbox = document.createElement('input'), text = document.createElement('span');
        label.className = 'ps-option' + (o.selected ? ' ps-selected' : ''); checkbox.type = 'checkbox'; checkbox.checked = o.selected;
        checkbox.disabled = archived && !original.has(o.value); text.textContent = o.textContent;
        checkbox.addEventListener('change', function () {
          if (checkbox.checked && selected().length >= 15) { checkbox.checked = false; status.textContent = 'Puoi selezionare al massimo 15 specialità.'; return; }
          o.selected = checkbox.checked; label.classList.toggle('ps-selected', o.selected); status.textContent = '';
          updateSummary(); select.dispatchEvent(new Event('change', {bubbles:true}));
        });
        label.append(checkbox, text);
        if (archived) { var note = document.createElement('small'); note.textContent = 'Archiviata'; label.append(note); }
        list.append(label);
      });
      empty.hidden = count > 0;
      empty.textContent = query ? 'Nessuna specialità trovata. Puoi aggiungerla da “Gestisci specialità”.' : 'Nessuna specialità disponibile. Inseriscila da “Gestisci specialità”.';
    }
    function setOptions(options, ids) {
      generation++; close(false); search.value = ''; status.textContent = '';
      original = new Set((ids || []).map(String)); select.replaceChildren();
      (options || []).forEach(function (item) {
        var o = new Option(item.name, String(item.id), original.has(String(item.id)), original.has(String(item.id)));
        o.dataset.active = item.active ? '1' : '0'; o.disabled = !item.active && !original.has(o.value); select.add(o);
      });
      updateSummary(); renderOptions();
    }
    async function refresh() {
      var requestGeneration = generation, button = root.querySelector('.ps-refresh');
      button.disabled = true; status.textContent = 'Aggiornamento elenco…';
      try {
        var response = await fetch(root.dataset.optionsUrl, {credentials:'same-origin', headers:{Accept:'application/json'}});
        if (!response.ok) throw new Error('refresh');
        var data = await response.json(); if (!data.ok || !Array.isArray(data.options)) throw new Error('refresh');
        if (requestGeneration !== generation) return;
        var values = selected(), ids = values.map(function (o) { return o.value; }), before = original;
        // Keep unavailable selected values visible until server-side validation, never silently discard them.
        values.forEach(function (o) { if (!data.options.some(function (item) { return String(item.id) === o.value; })) data.options.push({id:o.value, name:o.textContent, active:false}); });
        setOptions(data.options, ids); original = before; renderOptions(); status.textContent = 'Elenco aggiornato.';
      } catch (e) { if (requestGeneration === generation) status.textContent = 'Non è stato possibile aggiornare l’elenco. Riprova.'; }
      finally { button.disabled = false; }
    }
    trigger.addEventListener('click', function () {
      if (!panel.hidden) { close(false); return; }
      search.value = ''; renderOptions(); panel.hidden = false; trigger.setAttribute('aria-expanded', 'true'); search.focus();
    });
    search.addEventListener('input', renderOptions);
    search.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    root.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) { e.preventDefault(); close(true); }
      if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !panel.hidden) {
        var checks = Array.from(list.querySelectorAll('input:not(:disabled)'));
        var index = checks.indexOf(document.activeElement), next = index + (e.key === 'ArrowDown' ? 1 : -1);
        e.preventDefault(); if (next < 0) search.focus(); else if (checks.length) checks[Math.min(next, checks.length - 1)].focus();
      }
    });
    root.addEventListener('focusout', function (e) { if (!root.contains(e.relatedTarget)) close(false); });
    document.addEventListener('click', function (e) { if (!root.contains(e.target)) close(false); });
    root.querySelector('.ps-done').addEventListener('click', function () { close(true); });
    root.querySelector('.ps-refresh').addEventListener('click', refresh);
    root.querySelector('.ps-manage').addEventListener('click', function () { pendingRefresh = true; });
    window.addEventListener('focus', function () { if (pendingRefresh && !select.matches(':disabled')) { pendingRefresh = false; refresh(); } });
    select.form.addEventListener('reset', function () { generation++; close(false); setTimeout(function () { updateSummary(); renderOptions(); }, 0); });
    original = new Set(selected().map(function (o) { return o.value; })); updateSummary(); renderOptions();
    root.classList.add('ps-ready'); root.querySelector('.ps-enhanced').hidden = false;
    root.querySelector('#ps-label').htmlFor = ''; root.specialtySelect = {setOptions:setOptions};
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
