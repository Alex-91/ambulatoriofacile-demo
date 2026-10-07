(() => {
    'use strict';
    const root = document.querySelector('.clinical-content');
    if (!root) return;
    const panels = Array.from(root.querySelectorAll('.clinical-workspace main > section.card[id]'));
    const navLinks = Array.from(root.querySelectorAll(':scope > header nav a'));
    if (!panels.length) return;
    function selectPanel() {
        const anchor = document.getElementById(location.hash.slice(1));
        const selected = anchor && (anchor.matches('main > section.card') ? anchor : anchor.closest('main > section.card'));
        const panel = (selected && panels.includes(selected) ? selected : null) || panels.find(item => item.id === 'documenti') || panels[0];
        panels.forEach(item => { item.hidden = item !== panel; });
        navLinks.forEach(link => {
            if (link.hash === '#' + panel.id) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
    }
    root.classList.add('has-clinical-tabs');
    window.addEventListener('hashchange', selectPanel);
    selectPanel();

    const toolbar = document.querySelector('.episode-toolbar');
    if (!toolbar) return;
    toolbar.hidden = false;
    const entries = Array.from(document.querySelectorAll('.episode'));
    const search = document.getElementById('episode-search');
    const paragraph = document.getElementById('episode-paragraph');
    const order = document.getElementById('episode-order');
    const state = document.getElementById('episode-state');
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('it');
    function filter() {
        const term = normalize(search.value.trim());
        let visible = 0;
        entries.forEach(entry => {
            const parts = Array.from(entry.querySelectorAll('[data-paragraph]'));
            const matched = !paragraph.value || parts.some(part => part.dataset.paragraph === paragraph.value);
            entry.hidden = !matched || (state && state.value && state.value !== entry.dataset.state) || !normalize(entry.textContent).includes(term);
            parts.forEach(part => { part.hidden = !!paragraph.value && part.dataset.paragraph !== paragraph.value; });
            if (!entry.hidden) visible++;
        });
        document.getElementById('episode-empty').hidden = !entries.length || visible > 0;
    }
    search.addEventListener('input', filter);
    paragraph.addEventListener('change', filter);
    if (state) state.addEventListener('change', filter);
    order.addEventListener('change', () => {
        if (!entries.length) return;
        const parent = entries[0].parentElement;
        const pagination = parent.querySelector('nav');
        entries.sort((a,b) => (a.dataset.date.localeCompare(b.dataset.date) || Number(a.id.slice(9)) - Number(b.id.slice(9))) * (order.value === 'asc' ? 1 : -1));
        entries.forEach(entry => parent.insertBefore(entry, pagination));
    });
    const editor = document.querySelector('#nuovo form');
    if (editor) {
        let dirty = false;
        document.querySelectorAll('[data-appointment]').forEach(link => {
            link.addEventListener('click', event => {
                // Do not replace the appointment of an existing draft or correction.
                if (editor.elements.id.value !== '0' || editor.elements.previous_entry_id.value !== '0') {
                    event.preventDefault();
                    window.alert('Completa o annulla la modifica in corso prima di creare un altro episodio.');
                    return;
                }
                editor.elements.appointment_id.value = link.dataset.appointment;
                dirty = true;
            });
        });
        editor.addEventListener('input', () => { dirty = true; });
        editor.addEventListener('change', () => { dirty = true; });
        editor.addEventListener('submit', () => { dirty = false; });
        window.addEventListener('beforeunload', event => {
            if (!dirty) return;
            event.preventDefault();
            event.returnValue = '';
        });
    }
})();

