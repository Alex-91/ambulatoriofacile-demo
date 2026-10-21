(function () {
  'use strict';

  var panel = document.getElementById('loginPushPanel');
  var button = document.getElementById('loginEnablePush');
  var status = document.getElementById('loginPushStatus');
  var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
    || navigator.standalone === true;
  if (!panel || !button || !status || !standalone) return;

  panel.hidden = false;
  if (!('Notification' in window) || !('PushManager' in window) || !('serviceWorker' in navigator)) {
    button.disabled = true;
    status.textContent = 'Le notifiche non sono supportate da questa app su questo dispositivo. Verifica gli aggiornamenti del sistema.';
    return;
  }

  function renderPermission() {
    if (Notification.permission === 'denied') {
      status.textContent = 'Le notifiche sono bloccate. Abilitale nelle impostazioni del dispositivo per AmbulatorioFacile, poi torna qui e riprova.';
    } else if (Notification.permission === 'granted') {
      status.textContent = 'Il permesso è già concesso. Premi Attiva notifiche e poi accedi per aggiornare il collegamento a questo dispositivo.';
    }
  }
  renderPermission();

  button.addEventListener('click', async function () {
    button.disabled = true;
    try {
      // Must happen directly in the tap handler, before any asynchronous work.
      var permission = Notification.permission === 'granted'
        ? 'granted'
        : await Notification.requestPermission();
      if (permission !== 'granted') {
        status.textContent = permission === 'denied'
          ? 'Le notifiche sono bloccate. Abilitale nelle impostazioni del dispositivo per AmbulatorioFacile, poi riprova.'
          : 'Permesso non concesso. Puoi riprovare oppure accedere senza attivare le notifiche.';
        return;
      }
      // No anonymous subscription: the authenticated page registers the device.
      localStorage.setItem('push_login_activation_pending', '1');
      localStorage.removeItem('push_mobile_last_prompt_at');
      status.textContent = 'Permesso concesso. Ora accedi per completare l’associazione delle notifiche al tuo account.';
    } catch (_) {
      status.textContent = 'Non è stato possibile completare l’attivazione. Riprova oppure accedi e usa “Associa questo dispositivo” nel profilo.';
    } finally {
      button.disabled = false;
    }
  });
})();
