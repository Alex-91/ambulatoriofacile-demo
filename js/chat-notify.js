(function () {
  // anti doppio suono: nelle pagine chat lascia lavorare solo il polling locale
  if (window.__CHAT_LOCAL_POLLING__ === true) return;

  var lastUnread = 0;
  var lastToastMsgId = 0;
  var pollingMs = 10000;

  var baseTitle = document.title;
  var blinkTimer = null;
var cfg = window.CHAT_NOTIFY_CFG || {};

  var notifyAudio = new Audio(window.CHAT_SOUND_URL || 'public/sounds/chat.mp3');

  notifyAudio.volume = 0.7;
  notifyAudio.preload = 'auto';
  var audioUnlocked = false;

  var windowFocused = document.hasFocus();
  window.addEventListener('focus', function(){ windowFocused = true; });
  window.addEventListener('blur',  function(){ windowFocused = false; });

  function isWindowActive(){
    return windowFocused && document.visibilityState === 'visible';
  }

  function unlockAudio(){
    if (audioUnlocked) return;
    notifyAudio.play().then(function(){
      notifyAudio.pause();
      notifyAudio.currentTime = 0;
      audioUnlocked = true;
    }).catch(function(){});
  }
  window.addEventListener('click', unlockAudio, { once:true });
  window.addEventListener('keydown', unlockAudio, { once:true });

  function playSound(){
    if (!audioUnlocked) return;
    notifyAudio.currentTime = 0;
    notifyAudio.play().catch(function(){});
  }

  function setTitleAlert(unread){
    if (unread <= 0){
      if (blinkTimer) clearInterval(blinkTimer);
      blinkTimer = null;
      document.title = baseTitle;
      return;
    }
    if (blinkTimer) return;

    var on = false;
    blinkTimer = setInterval(function(){
      on = !on;
      document.title = on ? '(' + unread + ') Nuovi messaggi chat' : baseTitle;
    }, 1000);
  }

  function desktopNotify(title, text, href){
    if (!('Notification' in window)) return;

    if (Notification.permission === 'default') {
      Notification.requestPermission().then(function(p){
        if (p === 'granted') desktopNotify(title, text, href);
      });
      return;
    }

    if (Notification.permission !== 'granted') return;
    if (isWindowActive()) return;

    var n = new Notification(title, {
      body: text,
      tag: 'chat-new'
    });

    n.onclick = function(){
      window.focus();
      window.location.href = href;
    };
  }

  function ensureToastContainer(){
    if (document.getElementById('chatToastWrap')) return;
    var wrap = document.createElement('div');
    wrap.id = 'chatToastWrap';
    wrap.style.position = 'fixed';
    wrap.style.right = '12px';
    wrap.style.bottom = '12px';
    wrap.style.zIndex = '99999';
    wrap.style.maxWidth = '360px';
    document.body.appendChild(wrap);
  }

  function toast(title, text, href){
    ensureToastContainer();

    var card = document.createElement('div');
    card.style.background = '#fff';
    card.style.border = '1px solid #ddd';
    card.style.borderLeft = '4px solid #dd4b39';
    card.style.borderRadius = '6px';
    card.style.boxShadow = '0 2px 8px rgba(0,0,0,.15)';
    card.style.padding = '10px 12px';
    card.style.marginTop = '8px';
    card.style.cursor = 'pointer';

    card.innerHTML =
      '<b style="display:block;margin-bottom:4px;">' + escapeHtml(title) + '</b>' +
      '<div style="font-size:13px;color:#333;">' + escapeHtml(text) + '</div>' +
      '<div style="font-size:12px;color:#999;margin-top:6px;">Apri chat</div>';

    card.onclick = function(){ window.location.href = href; };

    document.getElementById('chatToastWrap').appendChild(card);

    setTimeout(function(){
      try { card.remove(); } catch(e){}
    }, 8000);
  }

  function escapeHtml(s){
    return String(s)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  function setBadge(n){
    var badge = document.getElementById('chatBadge');
    if (!badge) return;
    if (n > 0){
      badge.style.display = 'inline-block';
      badge.textContent = String(n);
    } else {
      badge.style.display = 'none';
      badge.textContent = '0';
    }
  }

  function isMobile(){
    return window.matchMedia && window.matchMedia("(max-width: 767px)").matches;
  }

  function currentThreadIdFromUrl(){
    var m = window.location.pathname.match(/chat\/thread\/(\d+)/);
    if (m && m[1]) return parseInt(m[1], 10);

    var params = new URLSearchParams(window.location.search || '');
    var t = params.get('thread');
    if (t) return parseInt(t, 10);

    return 0;
  }

  function isInChatArea(){
    return window.location.pathname.indexOf('chat') !== -1;
  }
  
  function setBadgeAll(n){
  ['chatBadge','chatBadgeMobile'].forEach(function(id){
    var el = document.getElementById(id);
    if (!el) return;
    if (n > 0) {
      el.style.display = 'inline-block';
      el.textContent = String(n);
    } else {
      el.style.display = 'none';
      el.textContent = '0';
    }
  });
}


 function buildHref(threadId){
  if (!threadId) return cfg.chatUrl || '/chat';
  if (!isMobile()) return (cfg.chatUrl || '/chat') + '?thread=' + threadId;
  return (cfg.chatThreadBase || '/chat/thread') + '/' + threadId;
}


  function formatTitle(latest){
    var base = latest.thread_title || 'Chat';
    return 'Nuovo messaggio • ' + base;
  }

  function formatText(latest){
    var sender = latest.sender_name || 'Utente';
    var body = (latest.body || '').replace(/\s+/g, ' ').trim();
    if (body.length > 120) body = body.slice(0, 120) + '…';
    return sender + ': ' + body;
  }
  
  window.addEventListener('chat-unread-changed', function(e){
  var n = (e.detail && typeof e.detail.unread !== 'undefined') ? e.detail.unread : 0;
  setBadgeAll(parseInt(n,10)||0);
});


 function tick(){
  var inChat = isInChatArea();
  var openThreadId = currentThreadIdFromUrl();

fetch((cfg.pollUrl || '/chat/poll') + '?thread=0&after=0', { credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(resp){
      if (!resp || !resp.ok) return;

      var unread = parseInt(resp.total_unread || 0, 10);
	  setBadgeAll(unread);

      setBadge(unread);
      setTitleAlert(unread);

      if (unread > lastUnread) {
        var latest = (resp.threads && resp.threads.length) ? resp.threads[0] : null;

        if (latest) {
          var msgId = parseInt(latest.last_id || 0, 10);
          var tid   = parseInt(latest.id_thread || 0, 10);

          if (tid && tid === openThreadId && isWindowActive()) {
            lastUnread = unread;
            return;
          }

          if (msgId && msgId !== lastToastMsgId) {
            lastToastMsgId = msgId;
            var title = formatTitle(latest);
            var text  = formatText(latest);
            var href  = buildHref(tid);

            playSound();
            toast(title, text, href);
            desktopNotify(title, text, href);
          }
        } else if (!inChat) {
          playSound();
          toast('Nuovo messaggio', 'Hai nuovi messaggi in chat', '/chat');
          desktopNotify('Nuovo messaggio', 'Hai nuovi messaggi in chat', '/chat');
        }
      }

      lastUnread = unread;
    })
    .catch(function(e){ console.error('chat-notify', e); })
    .finally(function(){ setTimeout(tick, pollingMs); });
}


  $(function(){
    tick();
  });
})();