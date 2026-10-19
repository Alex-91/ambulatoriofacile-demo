const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const inputs = [{ name: 'csrf_test', value: 'old' }, { name: 'csrf_test', value: 'old' }];
let downloads = 0, refreshed = 0, alert = '', requested = '', downloadedName = '', requestedFormat = '';
let response = {
  ok: true, redirected: false,
  headers: { get: name => ({ 'X-CSRF-Name': 'csrf_test', 'X-CSRF-Hash': 'new', 'Content-Type': 'text/csv; charset=UTF-8' })[name] },
  blob: async () => new Blob(['CSV']),
};
const sandbox = {
  window: { alert: message => { alert = message; }, location: { assign: () => { throw new Error('Unexpected redirect'); } } },
  document: { querySelectorAll: () => inputs, body: { appendChild: () => {} }, createElement: () => ({ click() { downloads++; downloadedName = this.download; }, remove: () => {} }) },
  URL: { createObjectURL: () => 'blob:test', revokeObjectURL: () => {} },
  FormData: class { constructor(form) { this.form = form; } set(name, value) { this[name] = value; } },
  setTimeout: callback => callback(),
  fetch: async (url, options) => { requested = url; requestedFormat = options.body.export_format; assert.equal(options.method, 'POST'); return response; },
};
vm.runInNewContext(fs.readFileSync('public/assets/js/billing-csv-export.js', 'utf8'), sandbox);
(async () => {
  const button = { disabled: false };
  const refresh = () => { refreshed++; button.disabled = false; };
  await sandbox.window.downloadSelectedBillingCsv({}, button, '/export-selezionate', refresh);
  assert.equal(requested, '/export-selezionate');
  assert.equal(downloads, 1);
  assert.ok(inputs.every(input => input.value === 'new'));
  await sandbox.window.downloadSelectedBillingCsv({}, button, '/export-selezionate', refresh);
  assert.equal(downloads, 2);
  response = { ...response, ok: false, json: async () => ({ error: 'Selezione non valida' }) };
  await sandbox.window.downloadSelectedBillingCsv({}, button, '/export-selezionate', refresh);
  assert.equal(downloads, 2);
  assert.equal(alert, 'Selezione non valida');
  assert.equal(refreshed, 3);
  for (const [type, filename] of [['application/pdf', 'fattura.pdf'], ['application/zip', 'fatture-selezionate.zip']]) {
    response = { ...response, ok: true, headers: { get: name => name === 'Content-Type' ? type : null } };
    await sandbox.window.downloadSelectedBillingCsv({}, button, '/export-selezionate', refresh, 'pdf');
    assert.equal(requestedFormat, 'pdf');
    assert.equal(downloadedName, filename);
  }
  assert.equal(downloads, 4);
  response = { ...response, ok: true, headers: { get: name => name === 'Content-Type' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : null } };
  await sandbox.window.downloadSelectedBillingCsv({}, button, '/export-selezionate', refresh, 'xlsx');
  assert.equal(requestedFormat, 'xlsx');
  assert.equal(downloadedName, 'fatture-selezionate.xlsx');
  assert.equal(downloads, 5);
  console.log('PASS: CSV download uses export endpoint, refreshes all CSRF inputs, supports repeated downloads and rejects errors without downloading.');
})().catch(error => { console.error(error); process.exitCode = 1; });
