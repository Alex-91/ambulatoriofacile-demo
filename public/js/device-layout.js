(function (window, document, navigator) {
  'use strict';

  var TABLET_DESKTOP_WIDTH = 1024;
  var TABLET_MIN_SHORT_SIDE = 600;

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
    var isPhone = reportsMobile === true
      || userAgent.indexOf('iphone') !== -1
      || userAgent.indexOf('ipod') !== -1
      || userAgent.indexOf('windows phone') !== -1
      || (userAgent.indexOf('android') !== -1 && userAgent.indexOf('mobile') !== -1);

    if (isAppleTablet || isAndroidTablet) {
      return true;
    }

    if (isPhone) {
      return false;
    }

    return (maxTouchPoints > 0 || hasCoarsePointer())
      && shortestScreenSide() >= TABLET_MIN_SHORT_SIDE;
  }

  function applyTabletDesktopViewport() {
    var viewport = document.querySelector('meta[name="viewport"]');

    if (!viewport || !isTabletDevice()) {
      return false;
    }

    viewport.setAttribute(
      'content',
      'width=' + TABLET_DESKTOP_WIDTH + ', initial-scale=1.0'
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
