(function () {
  'use strict';
  const get = name => document.querySelector('[name="' + name + '"]');
  const mode = get('stamp_mode'), amount = get('stamp_duty_amount');
  if (!mode || !amount) return;
  const cents = value => Math.round((Number(String(value).replace(',', '.')) || 0) * 100);
  const money = value => (value / 100).toLocaleString('it-IT', {minimumFractionDigits:2, maximumFractionDigits:2});
  function refresh() {
    let subtotal = 0, base = 0;
    const groups = {};
    const eligible = nature => ['N4', 'N2.1', 'N2.2', 'N3.5', 'N3.6'].includes(nature);
    const rate = Number(get('vat_rate').value) || 0;
    document.querySelectorAll('[name="item_unit_amount[]"]').forEach(input => {
      const row = input.closest('tr');
      const qty = Number(row.querySelector('[name="item_qty[]"]').value) || 1;
      const total = Math.round(qty * cents(input.value));
      subtotal += total;
      const treatment = row.querySelector('[name="item_tax_treatment[]"]')?.value || '';
      const lineRate = treatment === '' ? rate : (treatment.startsWith('IVA') ? Number(treatment.slice(3)) : 0);
      groups[lineRate] = (groups[lineRate] || 0) + total;
      if (lineRate === 0 && eligible(treatment || get('stamp_nature').value)) base += total;
    });
    if (mode.value === 'automatic') amount.value = base > 7747 ? '2.00' : '0.00';
    const charged = get('stamp_charge_customer').value === '1' ? cents(amount.value) : 0;
    const tax = Object.entries(groups).reduce((sum, [rate, base]) => sum + Math.round(base * Number(rate) / 100), 0);
    document.getElementById('stamp-summary').textContent = 'Base per il bollo: € ' + money(base)
      + ' · Bollo: € ' + money(cents(amount.value)) + ' · Totale da incassare: € ' + money(subtotal + tax + charged)
      + (get('stamp_nature').value === 'MANUAL' ? ' · Verifica manualmente se il bollo è dovuto.' : '');
    document.querySelectorAll('[data-stamp-method]').forEach(el => {
      const visible = el.dataset.stampMethod === get('stamp_method').value;
      el.hidden = !visible;
      el.querySelectorAll('input').forEach(input => { input.disabled = !visible; });
    });
  }
  amount.addEventListener('input', () => { mode.value = 'manual'; refresh(); });
  const form = mode.closest('form');
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  // Autocomplete uses jQuery's synthetic change events.
  if (window.jQuery) window.jQuery(form).on('change', 'input', refresh);
  refresh();
})();
