const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const script = fs.readFileSync(
  path.resolve(__dirname, '../../public/js/device-layout.js'),
  'utf8'
);

function runDeviceLayout(options) {
  const viewport = {
    content: 'width=device-width, initial-scale=1.0',
    setAttribute(name, value) {
      if (name === 'content') {
        this.content = value;
      }
    }
  };
  const classes = new Set();
  const window = {
    innerWidth: options.innerWidth || options.screenWidth,
    innerHeight: options.innerHeight || options.screenHeight,
    matchMedia: () => ({ matches: Boolean(options.coarsePointer) }),
    screen: {
      width: options.screenWidth,
      height: options.screenHeight
    }
  };
  const document = {
    documentElement: {
      classList: {
        add(className) {
          classes.add(className);
        }
      }
    },
    querySelector: () => viewport
  };
  const navigator = {
    maxTouchPoints: options.maxTouchPoints || 0,
    userAgent: options.userAgent || '',
    userAgentData: options.userAgentData || null
  };

  vm.runInNewContext(script, { document, navigator, window });

  return {
    applied: classes.has('tablet-desktop-layout'),
    content: viewport.content,
    isTablet: window.AmbulatorioFacileDeviceLayout.isTablet()
  };
}

test('usa la viewport desktop su iPad', () => {
  const result = runDeviceLayout({
    userAgent: 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) Mobile/15E148',
    maxTouchPoints: 5,
    screenWidth: 768,
    screenHeight: 1024
  });

  assert.equal(result.isTablet, true);
  assert.equal(result.applied, true);
  assert.equal(result.content, 'width=1024, initial-scale=0.7500');
});

test('usa la viewport desktop su tablet Android', () => {
  const result = runDeviceLayout({
    userAgent: 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36',
    maxTouchPoints: 5,
    screenWidth: 800,
    screenHeight: 1280
  });

  assert.equal(result.isTablet, true);
  assert.equal(result.applied, true);
});

test('riconosce un tablet Windows touch dalle dimensioni', () => {
  const result = runDeviceLayout({
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    maxTouchPoints: 10,
    coarsePointer: true,
    screenWidth: 800,
    screenHeight: 1280
  });

  assert.equal(result.isTablet, true);
  assert.equal(result.applied, true);
});

test('mantiene la viewport mobile su Android phone', () => {
  const result = runDeviceLayout({
    userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile Safari/537.36',
    userAgentData: { mobile: true },
    maxTouchPoints: 5,
    screenWidth: 412,
    screenHeight: 915
  });

  assert.equal(result.isTablet, false);
  assert.equal(result.applied, false);
  assert.equal(result.content, 'width=device-width, initial-scale=1.0');
});

test('riconosce un tablet Android piccolo anche se dichiara mobile', () => {
  const result = runDeviceLayout({
    userAgent: 'Mozilla/5.0 (Linux; Android 13; Tablet) Mobile Safari/537.36',
    userAgentData: { mobile: true },
    maxTouchPoints: 5,
    coarsePointer: true,
    screenWidth: 533,
    screenHeight: 853
  });

  assert.equal(result.isTablet, true);
  assert.equal(result.applied, true);
  assert.equal(result.content, 'width=1024, initial-scale=0.5205');
});

test('riconosce un tablet touch compatto dalla risoluzione', () => {
  const result = runDeviceLayout({
    userAgent: 'Mozilla/5.0 (Linux; x86_64)',
    maxTouchPoints: 5,
    coarsePointer: true,
    screenWidth: 520,
    screenHeight: 760
  });

  assert.equal(result.isTablet, true);
  assert.equal(result.applied, true);
  assert.equal(result.content, 'width=1024, initial-scale=0.5078');
});

test('mantiene la viewport mobile su iPhone', () => {
  const result = runDeviceLayout({
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile/15E148',
    maxTouchPoints: 5,
    screenWidth: 430,
    screenHeight: 932
  });

  assert.equal(result.isTablet, false);
  assert.equal(result.applied, false);
});

test('non trasforma in tablet una finestra desktop stretta', () => {
  const result = runDeviceLayout({
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    maxTouchPoints: 0,
    screenWidth: 768,
    screenHeight: 1024
  });

  assert.equal(result.isTablet, false);
  assert.equal(result.applied, false);
});
