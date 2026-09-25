<details class="delivery-policy-card">
  <summary>Fornitore HTTP personalizzato <?= !empty($smsConfig['http_config_stored']) ? '(configurazione salvata)' : '' ?></summary>
  <p>Per servizi con API HTTPS POST e risposta JSON. Supporta corpo JSON o form, header di autenticazione e campi personalizzati. API con SOAP, firme dinamiche o login preliminare richiedono un adattatore dedicato.</p>
  <label>Configurazione API (JSON)</label>
  <textarea class="form-control" name="http_config" rows="8" maxlength="16000" autocomplete="off" spellcheck="false" placeholder="Lascia vuoto per mantenere la configurazione salvata"></textarea>
  <p class="help-block">La configurazione, comprese le credenziali, viene cifrata e non viene mostrata nuovamente. Sostituisci i valori dell’esempio secondo la documentazione del fornitore. Non usare l’esempio per invii reali.</p>
  <pre><?= esc(json_encode([
      'url' => 'https://sms.example.com/send',
      'format' => 'json',
      'headers' => ['Authorization' => 'Bearer INSERISCI_TOKEN'],
      'body' => ['to' => '{recipient}', 'text' => '{message}', 'from' => '{sender}'],
      'success_path' => 'success', 'success_value' => true, 'id_path' => 'id',
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  <p class="help-block">Segnaposto: {recipient} (numero con +39), {message}, {sender}. Per campi annidati nella risposta usa il punto, ad esempio data.id. Il valore di successo deve corrispondere anche nel tipo (booleano, numero o stringa).</p>
  <?php if (!empty($smsConfig['http_config_stored'])): ?>
    <label><input type="checkbox" name="clear_http_config" value="1"> Rimuovi configurazione HTTP salvata</label>
  <?php endif; ?>
</details>
