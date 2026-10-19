(function () {
  'use strict';
  window.downloadSelectedBillingCsv = function (form, button, url, refresh, format) {
    if (button.disabled) return;
    button.disabled = true;
    var data = new FormData(form);
    data.set('export_format', format || 'csv');
    var filename = format === 'xlsx' ? 'fatture-selezionate.xlsx' : 'fatture-selezionate.csv';
    return fetch(url, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (response) {
        var name = response.headers.get('X-CSRF-Name');
        var hash = response.headers.get('X-CSRF-Hash');
        if (name && hash) document.querySelectorAll('input[type="hidden"]').forEach(function (input) {
          if (input.name === name) input.value = hash;
        });
        if (response.redirected) { window.location.assign(response.url); return null; }
        if (!response.ok) return response.json().then(function (body) { throw new Error(body.error || 'Esportazione non riuscita.'); });
        var type = (response.headers.get('Content-Type') || '').split(';')[0];
        if (['text/csv', 'application/pdf', 'application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'].indexOf(type) === -1) throw new Error('Risposta non valida. Ricarica la pagina.');
        filename = type === 'application/pdf' ? 'fattura.pdf' : type === 'application/zip' ? 'fatture-selezionate.zip' : filename;
        var disposition = (response.headers.get('Content-Disposition') || '').match(/filename="([a-zA-Z0-9_.-]+)"/);
        if (disposition) filename = disposition[1];
        return response.blob();
      })
      .then(function (blob) {
        if (!blob) return;
        var href = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = href;
        link.download = filename;
        document.body.appendChild(link); link.click(); link.remove();
        setTimeout(function () { URL.revokeObjectURL(href); }, 1000);
      })
      .catch(function (error) { window.alert(error.message || 'Esportazione non riuscita. Riprova.'); })
      .finally(refresh);
  };
})();
