(function () {
  var lastUnread = 0;
  var lastToastMsgId = 0;
  var pollingMs = 10000;

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

    card.innerHTML = '<b style="display:block;margin-bottom:4px;">'+escapeHtml(title)+'</b>'
      + '<div style="font-size:13px;color:#333;">'+escapeHtml(text)+'</div>'
      + '<div style="font-size:12px;color:#999;margin-top:6px;">Apri chat</div>';

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

  function isInChatThreadPage(){
    // se sei già dentro /chat/thread/... non faccio toast
    return window.location.pathname.indexOf('/chat/thread') !== -1;
  }

  function tick(){
    // Se sei già nella chat thread, niente toast (ci pensa il polling della chat)
    var inThread = isInChatThreadPage();

    $.getJSON('/chat/unread')
      .done(function(resp){
        if(!resp || !resp.ok) return;

        var unread = parseInt(resp.unread_threads || 0, 10);
        setBadge(unread);

        // toast solo se NON sei in chat thread
        if (!inThread && unread > lastUnread) {
          // prendo ultimo msg non letto (resp.latest[0])
          var latest = (resp.latest && resp.latest.length) ? resp.latest[0] : null;
          if (latest) {
            var msgId = parseInt(latest.id_message || 0, 10);
            if (msgId && msgId !== lastToastMsgId) {
              lastToastMsgId = msgId;
              var title = 'Nuovo messaggio da ' + (latest.other_name || 'utente');
              var text  = (latest.body || '').slice(0, 120);
              toast(title, text, '/chat/thread/' + latest.id_thread);
            }
          } else {
            toast('Nuovo messaggio', 'Hai nuovi messaggi in chat', '/chat');
          }
        }

        lastUnread = unread;
      })
      .always(function(){
        setTimeout(tick, pollingMs);
      });
  }

  // avvio
  $(function(){
    tick();
  });

})();
