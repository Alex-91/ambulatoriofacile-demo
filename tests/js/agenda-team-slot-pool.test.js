const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const agendaView = fs.readFileSync(
  path.resolve(__dirname, '../../rest/app/Views/agenda/index.php'),
  'utf8'
);

function extractFunction(name) {
  const start = agendaView.indexOf(`function ${name}(`);
  assert.notEqual(start, -1, `Funzione ${name} non trovata`);

  const bodyStart = agendaView.indexOf('{', start);
  let depth = 0;
  let quote = '';
  let escaped = false;

  for (let index = bodyStart; index < agendaView.length; index += 1) {
    const char = agendaView[index];

    if (quote !== '') {
      if (escaped) {
        escaped = false;
      } else if (char === '\\') {
        escaped = true;
      } else if (char === quote) {
        quote = '';
      }
      continue;
    }

    if (char === '"' || char === "'" || char === '`') {
      quote = char;
    } else if (char === '{') {
      depth += 1;
    } else if (char === '}') {
      depth -= 1;
      if (depth === 0) {
        return agendaView.slice(start, index + 1);
      }
    }
  }

  throw new Error(`Fine della funzione ${name} non trovata`);
}

function createJqueryStub() {
  const element = {
    addClass() { return this; },
    closest() { return this; },
    html() { return this; },
    removeClass() { return this; },
    toggleClass() { return this; }
  };

  function jquery() {
    return element;
  }

  jquery.each = (rows, callback) => {
    rows.forEach((row, index) => callback(index, row));
  };
  jquery.isArray = Array.isArray;

  return jquery;
}

test('la vista giorno team registra ogni slot una sola volta', () => {
  const context = {
    agendaCalendarBaseStep: 15,
    agendaTeamAllSlots: [],
    agendaTeamSlotIndex: {},
    buildAgendaTeamColumnInlineStyle: () => '',
    buildAgendaTeamDayBackgroundRows: () => '',
    buildAgendaTeamDayColumnEntries: () => '',
    buildAgendaTeamSlotIndexKey: (slot) => String(slot.id_slot),
    getAgendaTeamDayBounds: () => ({ totalMinutes: 60 }),
    getAgendaTeamDayPixelsPerMinute: () => 1,
    isAgendaTeamDayMobileListMode: () => false,
    renderAgendaTeamDayHeader: () => '',
    renderAgendaTeamDayTimeMarkers: () => '',
    supportsAgendaTeamDayCompactSlotDetailsFeature: () => false,
    supportsAgendaTeamDaySingleSlotHeightFeature: () => false,
    window: {
      AGENDA_CONFIG: { compressedLayoutEnabled: false }
    },
    $: createJqueryStub()
  };

  vm.createContext(context);
  vm.runInContext(extractFunction('registerAgendaTeamDaySlotPool'), context);
  vm.runInContext(extractFunction('renderAgendaTeamDay'), context);

  const slots = [
    { id_dot: 7, id_slot: 101, ora_inizio: '2026-10-12 10:30:00' },
    { id_dot: 7, id_slot: 102, ora_inizio: '2026-10-12 10:45:00' }
  ];

  context.renderAgendaTeamDay({
    columns: [{ id_dot: 7, slots }],
    grid_duration: 15,
    min_time: '10:30:00',
    max_time: '11:30:00'
  });

  assert.deepEqual(
    Array.from(context.agendaTeamAllSlots, (slot) => slot.id_slot),
    [101, 102]
  );
});
