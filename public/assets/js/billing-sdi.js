(function () {
  'use strict';
  if (!document.getElementById('sdi-workspace')) return;
  var channels = {
    portal: {
      title: 'XML / Portale AdE',
      description: 'Il gestionale prepara il file. L’operatore accede al portale dell’Agenzia delle Entrate e carica l’XML; la trasmissione avviene sul portale. Qui mostriamo soltanto il percorso con dati sintetici.',
      requirements: ['Generatore XML per le fatture reali e validazione del tracciato.', 'Accesso dell’emittente o del delegato a Fatture e Corrispettivi.', 'Caricamento e invio manuali sul portale; consultazione delle notifiche sul portale.'],
      steps: [['Preparazione XML di esempio', 'File dimostrativo, non utilizzabile per una trasmissione fiscale.'], ['Caricamento sul portale simulato', 'In uso reale questo passaggio avviene fuori dal gestionale.'], ['Trasmissione dal portale simulata', 'Nessun accesso al portale e nessuna trasmissione eseguiti.']]
    },
    pec: {
      title: 'Invio tramite PEC',
      description: 'Il percorso prevede un messaggio PEC con il file XML allegato e la successiva gestione delle notifiche. La ricevuta del messaggio PEC e l’esito SDI sono passaggi distinti.',
      requirements: ['Casella PEC autorizzata dell’emittente e parametri del gestore.', 'Implementazione di invio e lettura delle notifiche, con credenziali protette.', 'XML valido e indirizzo SDI corretto per il canale PEC utilizzato.'],
      steps: [['Preparazione allegato simulata', 'Fattura sintetica DEMO-001.'], ['Invio PEC simulato', 'Nessun messaggio PEC è stato inviato.'], ['Ricevute PEC simulate', 'Accettazione e consegna PEC non equivalgono all’esito fiscale SDI.']]
    },
    api: {
      title: 'Provider API',
      description: 'Il gestionale trasmette tramite un intermediario. Il provider restituisce un riferimento e rende disponibili gli esiti SDI. In questa demo non è collegato alcun provider.',
      requirements: ['Scelta del provider, contratto e abilitazione alle sue API.', 'Implementazione del connettore specifico, ambiente di test e credenziali protette.', 'Gestione notifiche, duplicati, esiti incerti e collaudo prima dell’attivazione.'],
      steps: [['Preparazione richiesta simulata', 'Nessuna chiamata verso servizi esterni.'], ['Presa in carico provider simulata', 'La presa in carico non equivale alla consegna SDI.'], ['Notifica del provider simulata', 'In uso reale verrebbe acquisita dal servizio collegato.']]
    }
  };
  var scenarios = {
    delivered: {label: 'Consegnata · simulazione', tone: 'success', detail: 'Il percorso termina con una consegna simulata al destinatario. Nessuna ricevuta ufficiale è stata emessa.'},
    rejected: {label: 'Scartata · simulazione', tone: 'error', detail: 'Esempio: partita IVA del destinatario non valida. Il percorso mostra la necessità di correggere i dati prima di una nuova trasmissione.'},
    unavailable: {label: 'Mancata consegna · simulazione', tone: 'warning', detail: 'Il percorso mostra un documento elaborato dallo SDI ma non recapitato al destinatario. È un esito distinto dallo scarto.'},
    pending: {label: 'In attesa di esito · simulazione', tone: 'warning', detail: 'Il percorso non ha ancora un esito conclusivo. In uso reale occorre verificare lo stato prima di ripetere l’invio.'}
  };
  var xml = '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<!-- DEMO: dati sintetici NON TRASMETTERE. Non validato per uso fiscale. -->\n' +
    '<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPR12">\n' +
    '  <FatturaElettronicaHeader>\n' +
    '    <DatiTrasmissione><IdTrasmittente><IdPaese>IT</IdPaese><IdCodice>00000000000</IdCodice></IdTrasmittente><ProgressivoInvio>DEMO001</ProgressivoInvio><FormatoTrasmissione>FPR12</FormatoTrasmissione><CodiceDestinatario>0000000</CodiceDestinatario></DatiTrasmissione>\n' +
    '    <CedentePrestatore><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>00000000000</IdCodice></IdFiscaleIVA><Anagrafica><Denominazione>CENTRO MEDICO DIMOSTRATIVO</Denominazione></Anagrafica><RegimeFiscale>RF01</RegimeFiscale></DatiAnagrafici><Sede><Indirizzo>Via Esempio 1</Indirizzo><CAP>00100</CAP><Comune>Roma</Comune><Provincia>RM</Provincia><Nazione>IT</Nazione></Sede></CedentePrestatore>\n' +
    '    <CessionarioCommittente><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>00000000000</IdCodice></IdFiscaleIVA><Anagrafica><Denominazione>CLINICA DIMOSTRATIVA</Denominazione></Anagrafica></DatiAnagrafici><Sede><Indirizzo>Via Esempio 2</Indirizzo><CAP>00100</CAP><Comune>Roma</Comune><Provincia>RM</Provincia><Nazione>IT</Nazione></Sede></CessionarioCommittente>\n' +
    '  </FatturaElettronicaHeader>\n' +
    '  <FatturaElettronicaBody>\n' +
    '    <DatiGenerali><DatiGeneraliDocumento><TipoDocumento>TD01</TipoDocumento><Divisa>EUR</Divisa><Data>2026-10-21</Data><Numero>DEMO-001</Numero><ImportoTotaleDocumento>122.00</ImportoTotaleDocumento><Causale>DOCUMENTO DIMOSTRATIVO NON TRASMETTERE</Causale></DatiGeneraliDocumento></DatiGenerali>\n' +
    '    <DatiBeniServizi><DettaglioLinee><NumeroLinea>1</NumeroLinea><Descrizione>Servizi dimostrativi alla struttura</Descrizione><Quantita>1.00</Quantita><PrezzoUnitario>100.00</PrezzoUnitario><PrezzoTotale>100.00</PrezzoTotale><AliquotaIVA>22.00</AliquotaIVA></DettaglioLinee><DatiRiepilogo><AliquotaIVA>22.00</AliquotaIVA><ImponibileImporto>100.00</ImponibileImporto><Imposta>22.00</Imposta><EsigibilitaIVA>I</EsigibilitaIVA></DatiRiepilogo></DatiBeniServizi>\n' +
    '  </FatturaElettronicaBody>\n</p:FatturaElettronica>\n';
  var channel = 'portal';
  var lastRun = null;
  function byId(id) { return document.getElementById(id); }
  function element(tag, value, className) {
    var node = document.createElement(tag);
    node.textContent = value;
    if (className) node.className = className;
    return node;
  }
  function reset() {
    lastRun = null;
    byId('sdi-timeline').replaceChildren();
    byId('sdi-result').replaceChildren(element('span', 'Da preparare', 'sdi-state'), element('p', 'Scegli un esito e avvia la dimostrazione.'));
    byId('sdi-report').disabled = true;
  }
  function selectChannel(value) {
    if (!Object.prototype.hasOwnProperty.call(channels, value)) return;
    channel = value;
    document.querySelectorAll('[data-channel]').forEach(function (button) {
      var active = button.dataset.channel === channel;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', String(active));
    });
    byId('sdi-channel-title').textContent = channels[channel].title;
    byId('sdi-channel-description').textContent = channels[channel].description;
    byId('sdi-requirements').replaceChildren();
    channels[channel].requirements.forEach(function (text) { byId('sdi-requirements').appendChild(element('li', text)); });
    reset();
  }
  function download(text, name, type) {
    var url = URL.createObjectURL(new Blob([text], {type: type}));
    var anchor = document.createElement('a');
    anchor.href = url; anchor.download = name;
    document.body.appendChild(anchor); anchor.click(); anchor.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }
  document.querySelectorAll('[data-channel]').forEach(function (button) {
    button.addEventListener('click', function () { selectChannel(button.dataset.channel); });
  });
  byId('sdi-scenario').addEventListener('change', reset);
  byId('sdi-reset').addEventListener('click', reset);
  byId('sdi-run').addEventListener('click', function () {
    var outcome = scenarios[byId('sdi-scenario').value];
    if (!outcome) return;
    var steps = channels[channel].steps.concat([[outcome.label, outcome.detail]]);
    byId('sdi-timeline').replaceChildren();
    steps.forEach(function (step) {
      var item = document.createElement('li');
      item.appendChild(element('strong', step[0])); item.appendChild(element('span', step[1]));
      byId('sdi-timeline').appendChild(item);
    });
    byId('sdi-result').replaceChildren(element('span', outcome.label, 'sdi-state ' + outcome.tone), element('p', outcome.detail));
    lastRun = {mode: 'demo', official_receipt: false, network_used: false, invoice: 'DEMO-001', channel: channels[channel].title, outcome: outcome.label, steps: steps};
    byId('sdi-report').disabled = false;
  });
  byId('sdi-xml').addEventListener('click', function () { download(xml, 'DEMO-NON-TRASMETTERE.xml', 'application/xml;charset=utf-8'); });
  byId('sdi-report').addEventListener('click', function () {
    if (!lastRun) return;
    download('DIMOSTRAZIONE SDI — NON È UNA RICEVUTA UFFICIALE\nNessun invio effettuato.\n\n' + JSON.stringify(lastRun, null, 2), 'DEMO-riepilogo-sdi.txt', 'text/plain;charset=utf-8');
  });
  byId('sdi-xml-preview').textContent = xml;
  selectChannel('portal');
}());
