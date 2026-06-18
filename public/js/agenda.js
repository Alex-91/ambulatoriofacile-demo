(function () {

    const agendaState = {
        baseUrl: window.AGENDA_CONFIG.baseUrl,
        selectedDot: Number(window.AGENDA_CONFIG.selectedDot || 0),
        selectedDate: window.AGENDA_CONFIG.selectedDate,
        viewMode: window.AGENDA_CONFIG.viewMode || 'day',
        lockToken: null,
        lockTimer: null,
        refreshTimer: null
    };

    function qs(selector) {
        return document.querySelector(selector);
    }

    function qsa(selector) {
        return document.querySelectorAll(selector);
    }

    function showAlert(message) {
        alert(message);
    }

    function formatTime(dateTimeString) {
        if (!dateTimeString) return '';
        const date = new Date(dateTimeString.replace(' ', 'T'));
        return date.toLocaleTimeString('it-IT', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getSelectedWeekdays() {
        const checked = [];
        qsa('.gen_weekday:checked').forEach(function (el) {
            checked.push(el.value);
        });
        return checked.join(',');
    }

    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
            window.jQuery(modal).modal('show');
        } else {
            modal.style.display = 'block';
            modal.classList.add('show');
        }
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
            window.jQuery(modal).modal('hide');
        } else {
            modal.style.display = 'none';
            modal.classList.remove('show');
        }
    }

    function buildFormData(data) {
        const formData = new FormData();
        Object.keys(data).forEach(function (key) {
            formData.append(key, data[key] !== null && data[key] !== undefined ? data[key] : '');
        });
        return formData;
    }

    async function getJSON(url) {
        const response = await fetch(url, {
            credentials: 'same-origin'
        });
        return await response.json();
    }

    async function postJSON(url, data) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            body: buildFormData(data)
        });
        return await response.json();
    }

    function renderAgenda(slots) {
        const grid = qs('#agendaGrid');
        if (!grid) return;

        if (!slots || !slots.length) {
            grid.innerHTML = '<div class="text-center text-muted py-5">Nessuno slot disponibile</div>';
            return;
        }

        let html = '<div class="row">';

        slots.forEach(function (slot) {
            let slotClass = 'agenda-slot-libero';
            let title = 'Slot libero';
            let subtitle = '';
            let badge = slot.stato || 'LIBERO';

            if (slot.tipo_slot === 'DOMICILIARE') {
                slotClass = 'agenda-slot-domiciliare';
            }

            if (slot.stato === 'BLOCCATO') {
                slotClass = 'agenda-slot-bloccato';
            }

            if (slot.stato === 'PRENOTATO') {
                slotClass = 'agenda-slot-prenotato';
            }

            if (slot.cognome || slot.nome) {
                title = (slot.cognome || '') + ' ' + (slot.nome || '');
            }

            if (slot.motivo_visita) {
                subtitle = slot.motivo_visita;
            }

            html += `
                <div class="col-xl-3 col-lg-4 col-md-6 col-12 mb-3">
                    <div class="agenda-slot-card ${slotClass}" 
                         data-slot='${JSON.stringify(slot).replace(/'/g, "&apos;")}'>
                        <div class="agenda-slot-time">
                            ${escapeHtml(formatTime(slot.ora_inizio))} - ${escapeHtml(formatTime(slot.ora_fine))}
                        </div>
                        <div class="agenda-slot-title">
                            ${escapeHtml(title)}
                        </div>
                        <div class="agenda-slot-subtitle">
                            ${escapeHtml(subtitle)}
                        </div>
                        <div class="agenda-slot-footer">
                            <span class="badge badge-light">${escapeHtml(badge)}</span>
                            ${slot.tipo_slot === 'DOMICILIARE' ? '<span class="badge badge-info ml-1">DOM</span>' : ''}
                        </div>
                    </div>
                </div>
            `;
        });

        html += '</div>';
        grid.innerHTML = html;

        qsa('.agenda-slot-card').forEach(function (card) {
            card.addEventListener('click', function () {
                const raw = card.getAttribute('data-slot');
                const slot = JSON.parse(raw.replace(/&apos;/g, "'"));
                onClickSlot(slot);
            });
        });
    }

    function renderDomiciliari(rows) {
        const box = qs('#domiciliariList');
        if (!box) return;

        if (!rows || !rows.length) {
            box.innerHTML = '<div class="text-center text-muted py-4">Nessun domiciliare per questa data</div>';
            return;
        }

        let html = '';
        rows.forEach(function (row) {
            html += `
                <div class="border rounded p-3 mb-2">
                    <div><strong>${escapeHtml(formatTime(row.ora_inizio))}</strong></div>
                    <div><strong>${escapeHtml((row.cognome || '') + ' ' + (row.nome || ''))}</strong></div>
                    <div class="text-muted">${escapeHtml(row.indirizzo_visita || '')}</div>
                    <div class="text-muted">${escapeHtml(row.comune_visita || '')}</div>
                    <div>${escapeHtml(row.motivo_visita || '')}</div>
                </div>
            `;
        });

        box.innerHTML = html;
    }

    function renderNote(rows) {
        const box = qs('#noteList');
        if (!box) return;

        if (!rows || !rows.length) {
            box.innerHTML = '<div class="text-center text-muted py-4">Nessuna nota presente</div>';
            return;
        }

        let html = '';
        rows.forEach(function (row) {
            let badgeClass = 'badge-secondary';

            if (row.priorita === 'MEDIA') badgeClass = 'badge-warning';
            if (row.priorita === 'ALTA') badgeClass = 'badge-danger';
            if (row.priorita === 'BASSA') badgeClass = 'badge-secondary';

            html += `
                <div class="border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong>${escapeHtml(row.titolo || 'Nota')}</strong>
                        <span class="badge ${badgeClass}">${escapeHtml(row.priorita || 'MEDIA')}</span>
                    </div>
                    <div>${escapeHtml(row.testo || '')}</div>
                </div>
            `;
        });

        box.innerHTML = html;
    }

    async function loadAgenda() {
        const url = `${agendaState.baseUrl}/calendario?id_dot=${agendaState.selectedDot}&data=${encodeURIComponent(agendaState.selectedDate)}&view=${encodeURIComponent(agendaState.viewMode)}`;
        const json = await getJSON(url);
        renderAgenda(json.slots || []);
    }

    async function loadDomiciliari() {
        const url = `${agendaState.baseUrl}/domiciliari?id_dot=${agendaState.selectedDot}&data=${encodeURIComponent(agendaState.selectedDate)}`;
        const json = await getJSON(url);
        renderDomiciliari(json.rows || []);
    }

    async function loadNote() {
        const url = `${agendaState.baseUrl}/note?id_dot=${agendaState.selectedDot}&data=${encodeURIComponent(agendaState.selectedDate)}`;
        const json = await getJSON(url);
        renderNote(json.rows || []);
    }

    async function refreshAll() {
        await Promise.all([
            loadAgenda(),
            loadDomiciliari(),
            loadNote()
        ]);
    }

    function fillAppointmentModal(slot, tokenLock) {
        qs('#app_id_slot').value = slot.id_slot || '';
        qs('#app_id_dot').value = slot.id_dot || agendaState.selectedDot;
        qs('#app_id_paziente').value = slot.id_paziente || '';
        qs('#app_token_lock').value = tokenLock || '';

        qs('#app_cognome').value = slot.cognome || '';
        qs('#app_nome').value = slot.nome || '';
        qs('#app_telefono').value = slot.telefono || '';
        qs('#app_cellulare').value = slot.cellulare || '';
        qs('#app_email').value = slot.email || '';
        qs('#app_motivo_visita').value = slot.motivo_visita || '';
        qs('#app_indirizzo_visita').value = slot.indirizzo_visita || '';
        qs('#app_comune_visita').value = slot.comune_visita || '';
        qs('#app_note').value = slot.note || '';
        qs('#searchPatient').value = '';

        hideAutocomplete();
    }

    async function onClickSlot(slot) {
        if (slot.stato === 'PRENOTATO') {
            fillAppointmentModal(slot, '');
            openModal('appointmentModal');
            return;
        }

        const json = await postJSON(`${agendaState.baseUrl}/lock-slot`, {
            id_slot: slot.id_slot
        });

        if (!json.status) {
            showAlert(json.message || 'Slot non disponibile');
            return;
        }

        agendaState.lockToken = json.token_lock;
        fillAppointmentModal(slot, json.token_lock);
        openModal('appointmentModal');
        startLockRefresh();
    }

    function startLockRefresh() {
        stopLockRefresh();

        agendaState.lockTimer = setInterval(async function () {
            const token = qs('#app_token_lock').value || agendaState.lockToken;
            if (!token) return;

            const json = await postJSON(`${agendaState.baseUrl}/refresh-lock`, {
                token_lock: token
            });

            if (!json.status) {
                stopLockRefresh();
                agendaState.lockToken = null;
                closeModal('appointmentModal');
                await refreshAll();
                showAlert('Lock scaduto o non più disponibile');
            }
        }, 20000);
    }

    function stopLockRefresh() {
        if (agendaState.lockTimer) {
            clearInterval(agendaState.lockTimer);
            agendaState.lockTimer = null;
        }
    }

    async function unlockCurrentSlot() {
        const token = qs('#app_token_lock').value || agendaState.lockToken;

        if (!token) return;

        await postJSON(`${agendaState.baseUrl}/unlock-slot`, {
            token_lock: token
        });

        agendaState.lockToken = null;
        qs('#app_token_lock').value = '';
        stopLockRefresh();
    }

    async function saveAppointment() {
        const payload = {
            id_slot: qs('#app_id_slot').value,
            id_dot: qs('#app_id_dot').value,
            id_paziente: qs('#app_id_paziente').value,
            token_lock: qs('#app_token_lock').value,
            cognome: qs('#app_cognome').value,
            nome: qs('#app_nome').value,
            telefono: qs('#app_telefono').value,
            cellulare: qs('#app_cellulare').value,
            email: qs('#app_email').value,
            motivo_visita: qs('#app_motivo_visita').value,
            indirizzo_visita: qs('#app_indirizzo_visita').value,
            comune_visita: qs('#app_comune_visita').value,
            note: qs('#app_note').value
        };

        const json = await postJSON(`${agendaState.baseUrl}/salva-appuntamento`, payload);

        if (!json.status) {
            showAlert(json.message || 'Errore salvataggio appuntamento');
            return;
        }

        stopLockRefresh();
        agendaState.lockToken = null;
        closeModal('appointmentModal');
        await refreshAll();
        showAlert(json.message || 'Appuntamento salvato');
    }

    async function savePatientOnly() {
        const payload = {
            id_paziente: qs('#app_id_paziente').value,
            id_dot: qs('#app_id_dot').value,
            cognome: qs('#app_cognome').value,
            nome: qs('#app_nome').value,
            telefono: qs('#app_telefono').value,
            cellulare: qs('#app_cellulare').value,
            email: qs('#app_email').value
        };

        const json = await postJSON(`${agendaState.baseUrl}/salva-paziente`, payload);

        if (!json.status) {
            showAlert(json.message || 'Errore salvataggio paziente');
            return;
        }

        qs('#app_id_paziente').value = json.id_paziente || '';
        showAlert('Paziente salvato correttamente');
    }

    async function saveNote() {
        const payload = {
            id_dot: agendaState.selectedDot,
            data_nota: qs('#nota_data').value,
            titolo: qs('#nota_titolo').value,
            testo: qs('#nota_testo').value,
            priorita: qs('#nota_priorita').value
        };

        const json = await postJSON(`${agendaState.baseUrl}/salva-nota`, payload);

        if (!json.status) {
            showAlert(json.message || 'Errore salvataggio nota');
            return;
        }

        closeModal('noteModal');
        await loadNote();
        showAlert('Nota salvata correttamente');
    }

    async function generateSlots() {
        const payload = {
            id_dot: agendaState.selectedDot,
            data_inizio: qs('#gen_data_inizio').value,
            data_fine: qs('#gen_data_fine').value,
            ora_inizio: qs('#gen_ora_inizio').value,
            ora_fine: qs('#gen_ora_fine').value,
            durata_slot_minuti: qs('#gen_durata').value,
            tipo_slot: qs('#gen_tipo_slot').value,
            giorni_settimana: getSelectedWeekdays()
        };

        const json = await postJSON(`${agendaState.baseUrl}/genera-slot-periodo`, payload);

        if (!json.status) {
            showAlert(json.message || 'Errore generazione slot');
            return;
        }

        closeModal('generateSlotsModal');
        await refreshAll();
        showAlert('Slot generati correttamente: ' + (json.inserted || 0));
    }

    async function searchPatients(term) {
        if (!term || term.length < 2) {
            hideAutocomplete();
            return;
        }

        const url = `${agendaState.baseUrl}/cerca-pazienti?id_dot=${agendaState.selectedDot}&term=${encodeURIComponent(term)}`;
        const json = await getJSON(url);
        const rows = json.rows || [];
        const box = qs('#patientAutocomplete');

        if (!rows.length) {
            box.innerHTML = '<div class="p-2 text-muted">Nessun paziente trovato</div>';
            box.classList.remove('d-none');
            return;
        }

        let html = '';
        rows.forEach(function (row) {
            html += `
                <div class="agenda-autocomplete-item" data-id="${row.id_paziente}">
                    <strong>${escapeHtml(row.cognome)} ${escapeHtml(row.nome)}</strong><br>
                    <small>${escapeHtml(row.telefono || row.cellulare || '')}</small>
                </div>
            `;
        });

        box.innerHTML = html;
        box.classList.remove('d-none');

        qsa('.agenda-autocomplete-item').forEach(function (item) {
            item.addEventListener('click', async function () {
                const idPaziente = item.getAttribute('data-id');
                const jsonPaziente = await getJSON(`${agendaState.baseUrl}/paziente/${idPaziente}`);

                if (jsonPaziente && jsonPaziente.row) {
                    const p = jsonPaziente.row;
                    qs('#app_id_paziente').value = p.id_paziente || '';
                    qs('#app_cognome').value = p.cognome || '';
                    qs('#app_nome').value = p.nome || '';
                    qs('#app_telefono').value = p.telefono || '';
                    qs('#app_cellulare').value = p.cellulare || '';
                    qs('#app_email').value = p.email || '';
                    qs('#searchPatient').value = (p.cognome || '') + ' ' + (p.nome || '');
                    hideAutocomplete();
                }
            });
        });
    }

    function hideAutocomplete() {
        const box = qs('#patientAutocomplete');
        if (!box) return;
        box.innerHTML = '';
        box.classList.add('d-none');
    }

    function shiftDate(days) {
        const current = new Date(agendaState.selectedDate + 'T00:00:00');
        current.setDate(current.getDate() + days);
        agendaState.selectedDate = current.toISOString().slice(0, 10);
        qs('#agenda_date').value = agendaState.selectedDate;
        refreshAll();
    }

    function startAutoRefresh() {
        if (agendaState.refreshTimer) {
            clearInterval(agendaState.refreshTimer);
        }

        agendaState.refreshTimer = setInterval(function () {
            refreshAll();
        }, 30000);
    }

    function bindEvents() {
        const btnReloadAgenda = qs('#btnReloadAgenda');
        if (btnReloadAgenda) {
            btnReloadAgenda.addEventListener('click', function () {
                agendaState.selectedDot = Number(qs('#id_dot').value || 0);
                agendaState.selectedDate = qs('#agenda_date').value;
                agendaState.viewMode = qs('#view_mode').value;
                refreshAll();
            });
        }

        const idDot = qs('#id_dot');
        if (idDot) {
            idDot.addEventListener('change', function () {
                agendaState.selectedDot = Number(this.value || 0);
                refreshAll();
            });
        }

        const agendaDate = qs('#agenda_date');
        if (agendaDate) {
            agendaDate.addEventListener('change', function () {
                agendaState.selectedDate = this.value;
                refreshAll();
            });
        }

        const viewMode = qs('#view_mode');
        if (viewMode) {
            viewMode.addEventListener('change', function () {
                agendaState.viewMode = this.value;
                refreshAll();
            });
        }

        const btnToday = qs('#btnToday');
        if (btnToday) {
            btnToday.addEventListener('click', function () {
                const today = new Date().toISOString().slice(0, 10);
                agendaState.selectedDate = today;
                qs('#agenda_date').value = today;
                refreshAll();
            });
        }

        const btnPrevDay = qs('#btnPrevDay');
        if (btnPrevDay) {
            btnPrevDay.addEventListener('click', function () {
                shiftDate(-1);
            });
        }

        const btnNextDay = qs('#btnNextDay');
        if (btnNextDay) {
            btnNextDay.addEventListener('click', function () {
                shiftDate(1);
            });
        }

        const btnOpenNoteModal = qs('#btnOpenNoteModal');
        if (btnOpenNoteModal) {
            btnOpenNoteModal.addEventListener('click', function () {
                qs('#nota_data').value = agendaState.selectedDate;
                openModal('noteModal');
            });
        }

        const btnOpenNoteModalTop = qs('#btnOpenNoteModalTop');
        if (btnOpenNoteModalTop) {
            btnOpenNoteModalTop.addEventListener('click', function () {
                qs('#nota_data').value = agendaState.selectedDate;
                openModal('noteModal');
            });
        }

        const btnSaveNote = qs('#btnSaveNote');
        if (btnSaveNote) {
            btnSaveNote.addEventListener('click', function () {
                saveNote();
            });
        }

        const btnSaveAppointment = qs('#btnSaveAppointment');
        if (btnSaveAppointment) {
            btnSaveAppointment.addEventListener('click', function () {
                saveAppointment();
            });
        }

        const btnSavePatientOnly = qs('#btnSavePatientOnly');
        if (btnSavePatientOnly) {
            btnSavePatientOnly.addEventListener('click', function () {
                savePatientOnly();
            });
        }

        const btnOpenGenerateSlotsModal = qs('#btnOpenGenerateSlotsModal');
        if (btnOpenGenerateSlotsModal) {
            btnOpenGenerateSlotsModal.addEventListener('click', function () {
                qs('#gen_data_inizio').value = agendaState.selectedDate;
                qs('#gen_data_fine').value = agendaState.selectedDate;
                openModal('generateSlotsModal');
            });
        }

        const btnGenerateSlots = qs('#btnGenerateSlots');
        if (btnGenerateSlots) {
            btnGenerateSlots.addEventListener('click', function () {
                generateSlots();
            });
        }

        const searchPatient = qs('#searchPatient');
        if (searchPatient) {
            searchPatient.addEventListener('input', function () {
                searchPatients(this.value);
            });
        }

        qsa('.btn-close-appointment-modal').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                await unlockCurrentSlot();
                closeModal('appointmentModal');
            });
        });

        qsa('.btn-close-note-modal').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeModal('noteModal');
            });
        });

        qsa('.btn-close-generate-modal').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeModal('generateSlotsModal');
            });
        });

        window.addEventListener('beforeunload', function () {
            const token = qs('#app_token_lock') ? qs('#app_token_lock').value : null;
            if (!token) return;

            navigator.sendBeacon(
                `${agendaState.baseUrl}/unlock-slot`,
                buildFormData({ token_lock: token })
            );
        });
    }

    async function init() {
        bindEvents();
        await refreshAll();
        startAutoRefresh();
    }

    init();

})();