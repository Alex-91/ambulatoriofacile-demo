(function (window, document, navigator) {
  'use strict';

  var TABLET_DESKTOP_WIDTH = 1024;
  var TABLET_MIN_SHORT_SIDE = 520;
  var TABLET_MIN_LONG_SIDE = 760;

  function shortestScreenSide() {
    var screenWidth = Number(window.screen && window.screen.width) || 0;
    var screenHeight = Number(window.screen && window.screen.height) || 0;

    if (screenWidth > 0 && screenHeight > 0) {
      return Math.min(screenWidth, screenHeight);
    }

    return Math.min(
      Number(window.innerWidth) || 0,
      Number(window.innerHeight) || 0
    );
  }

  function longestScreenSide() {
    var screenWidth = Number(window.screen && window.screen.width) || 0;
    var screenHeight = Number(window.screen && window.screen.height) || 0;

    if (screenWidth > 0 && screenHeight > 0) {
      return Math.max(screenWidth, screenHeight);
    }

    return Math.max(
      Number(window.innerWidth) || 0,
      Number(window.innerHeight) || 0
    );
  }

  function hasCoarsePointer() {
    return Boolean(
      window.matchMedia
      && window.matchMedia('(pointer: coarse)').matches
    );
  }

  function isTabletDevice() {
    var userAgent = String(navigator.userAgent || '').toLowerCase();
    var maxTouchPoints = Number(navigator.maxTouchPoints) || 0;
    var userAgentData = navigator.userAgentData || null;
    var reportsMobile = userAgentData && typeof userAgentData.mobile === 'boolean'
      ? userAgentData.mobile
      : null;
    var isAppleTablet = userAgent.indexOf('ipad') !== -1
      || (userAgent.indexOf('macintosh') !== -1 && maxTouchPoints > 1);
    var isAndroidTablet = userAgent.indexOf('android') !== -1
      && userAgent.indexOf('mobile') === -1;
    var isKnownPhone = userAgent.indexOf('iphone') !== -1
      || userAgent.indexOf('ipod') !== -1
      || userAgent.indexOf('windows phone') !== -1;
    var hasTouchInput = maxTouchPoints > 0 || hasCoarsePointer();
    var isTabletSizedTouchDevice = hasTouchInput
      && shortestScreenSide() >= TABLET_MIN_SHORT_SIDE
      && longestScreenSide() >= TABLET_MIN_LONG_SIDE;

    if (isAppleTablet || isAndroidTablet) {
      return true;
    }

    if (isKnownPhone) {
      return false;
    }

    // Alcuni tablet Android e browser embedded dichiarano comunque
    // userAgentData.mobile=true o includono "Mobile" nello user agent.
    // Le dimensioni touch hanno quindi precedenza sul generico flag mobile.
    if (isTabletSizedTouchDevice) {
      return true;
    }

    if (
      reportsMobile === true
      || (userAgent.indexOf('android') !== -1 && userAgent.indexOf('mobile') !== -1)
    ) {
      return false;
    }

    return false;
  }

  function tabletDesktopScale() {
    var availableWidth = Number(window.innerWidth) || 0;

    if (availableWidth <= 0) {
      availableWidth = shortestScreenSide();
    }

    return Math.min(1, Math.max(0.5, availableWidth / TABLET_DESKTOP_WIDTH));
  }

  function applyTabletDesktopViewport() {
    var viewport = document.querySelector('meta[name="viewport"]');

    if (!viewport || !isTabletDevice()) {
      return false;
    }

    viewport.setAttribute(
      'content',
      'width=' + TABLET_DESKTOP_WIDTH
        + ', initial-scale=' + tabletDesktopScale().toFixed(4)
    );
    document.documentElement.classList.add('tablet-desktop-layout');

    return true;
  }

  window.AmbulatorioFacileDeviceLayout = {
    apply: applyTabletDesktopViewport,
    isTablet: isTabletDevice
  };

  applyTabletDesktopViewport();
}(window, document, navigator));
