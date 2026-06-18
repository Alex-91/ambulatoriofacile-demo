(function () {
  document.addEventListener('DOMContentLoaded', () => {
    console.log('messages.js avviato ✅');

    // rileggo gli elementi DOPO che il DOM è pronto
    const roleEl = document.getElementById('role');
    const idDraftEl = document.getElementById('id_draft');
    const bodyEl = document.getElementById('body');
    const statusEl = document.getElementById('draftStatus');
    const sendDraftHidden = document.getElementById('send_id_draft');
    const sendForm = document.getElementById('sendForm');

    const patientTargetEl = document.getElementById('patient_target');
    const recipientUserIdEl = document.getElementById('recipient_user_id');
    const patientSearchEl = document.getElementById('patient_search');
    const patientResultsEl = document.getElementById('patient_results');

    const filesEl = document.getElementById('files');
    const attachmentsBox = document.getElementById('attachmentsBox');

    // DEBUG: ti dico subito se manca qualcosa
    console.log('DOM check', {
      role: !!roleEl,
      id_draft: !!idDraftEl,
      body: !!bodyEl,
      sendForm: !!sendForm,
      send_id_draft: !!sendDraftHidden,
      patient_target: !!patientTargetEl
    });

    if (!idDraftEl || !sendDraftHidden || !sendForm) {
      console.error('Mancano elementi base (id_draft / send_id_draft / sendForm). Autosave disabilitato.');
      return;
    }
    if (!bodyEl) {
      console.error('Textarea #body non trovata. Controlla che nella view abbia id="body".');
      return;
    }

    const role = roleEl?.value || '';
    let dirty = false;
    let autosaveTimer = null;
    let autosaveInterval = null;

    function setStatus(text, cls) {
      if (!statusEl) return;
      statusEl.className = 'badge ' + (cls || 'badge-secondary');
      statusEl.textContent = text;
    }

    function getDraftId() {
      return parseInt(idDraftEl.value || '0', 10) || 0;
    }

    function setDraftId(id) {
      idDraftEl.value = String(id);
      sendDraftHidden.value = String(id);
    }

    function buildDraftPayload() {
      const body = bodyEl.value || '';
      const idDraft = getDraftId();

      let payload = {
        id_draft: idDraft,
        body: body,
        draft_kind: 'NEW'
      };

      if (role === 'PAZIENTE') {
        payload.recipient_type = 'PATIENT_TARGET';
        payload.patient_target_code = patientTargetEl ? patientTargetEl.value : 'MEDICO';
      } else if (role === 'DOTTORE') {
        payload.recipient_type = 'USER';
        payload.recipient_user_id = parseInt(recipientUserIdEl?.value || '0', 10) || null;
      } else if (role === 'SEGRETERIA' || role === 'INFERMIERE') {
        payload.recipient_type = 'ROLE';
        payload.recipient_role = role;
      } else {
        payload.recipient_type = 'ROLE';
        payload.recipient_role = 'SEGRETERIA';
      }

      // CSRF (se lo hai esposto in window.__CSRF nella view)
      if (window.__CSRF && window.__CSRF.name && window.__CSRF.hash) {
        payload[window.__CSRF.name] = window.__CSRF.hash;
      }

      return payload;
    }

    async function autosave() {
      if (!dirty) return;

      try {
        setStatus('Salvataggio in corso...', 'badge-info');

        const payload = buildDraftPayload();

        // NB: per medico possiamo salvare bozza anche senza paziente selezionato
        // la validazione forte la fai al momento dell'invio.
        const res = await fetch('/messaggi/api/bozza/salva', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
          credentials: 'same-origin'
        });

        // se auth/CSRF ti redirige, qui spesso torna HTML e json() fallisce
        const txt = await res.text();
        let json;
        try { json = JSON.parse(txt); } catch (e) {
          console.error('Risposta non JSON (forse redirect/login/CSRF):', res.status, txt.slice(0, 200));
          setStatus('Errore bozza (auth/csrf?)', 'badge-danger');
          return;
        }

        if (!json.ok) {
          console.error('Autosave error:', json);
          setStatus('Errore salvataggio bozza', 'badge-danger');
          return;
        }

        setDraftId(json.id_draft);
        dirty = false;

        const now = new Date();
        const hh = String(now.getHours()).padStart(2,'0');
        const mm = String(now.getMinutes()).padStart(2,'0');
        setStatus(`Salvato in bozza alle ${hh}:${mm}`, 'badge-success');
      } catch (e) {
        console.error(e);
        setStatus('Errore salvataggio bozza', 'badge-danger');
      }
    }

    function scheduleAutosave() {
      dirty = true;
      if (autosaveTimer) clearTimeout(autosaveTimer);
      autosaveTimer = setTimeout(() => autosave(), 800);
    }

    function startIntervalAutosave() {
      autosaveInterval = setInterval(() => {
        if (dirty) autosave();
      }, 10000);
    }

    // bind input
    bodyEl.addEventListener('input', scheduleAutosave);
    if (patientTargetEl) patientTargetEl.addEventListener('change', scheduleAutosave);

    // Invia: se draftId=0, forza autosave e poi submit
    sendForm.addEventListener('submit', async (e) => {
      if (getDraftId() <= 0) {
        e.preventDefault();
        dirty = true;
        await autosave();
        if (getDraftId() > 0) {
          sendForm.submit();
        } else {
          alert('Impossibile creare la bozza (vedi console F12).');
        }
      }
    });

    // confirm leave
    window.addEventListener('beforeunload', (e) => {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = 'Vuoi salvare in bozza?';
      return 'Vuoi salvare in bozza?';
    });

    startIntervalAutosave();
    setStatus('Bozza pronta', 'badge-secondary');
  });
})();