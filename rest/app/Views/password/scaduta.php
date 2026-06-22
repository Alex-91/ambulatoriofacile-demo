<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="shortcut icon" href="<?= base_url('public/assets/images/logonew.jpg'); ?>" />
  <title><?= esc('AmbulatorioFacile') ?> - Cambio Password</title>

  <meta name="theme-color" content="#2c8895">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="<?= esc('AmbulatorioFacile') ?>">
  <link rel="apple-touch-icon" href="<?= base_url('public/assets/images/logonew.jpg'); ?>">

  <link rel="stylesheet" href="<?= base_url('public/assets/css/register.css'); ?>">
  <link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/all.min.css'); ?>">
  <script src="<?= base_url('public/assets/js/jquery.min.js'); ?>"></script>

  <style>
    /* piccoli ritocchi per pagina scadenza */
    #titolo { display:block; margin-bottom: 12px; }
    .row { position: relative; }
    .toggle-password {
      position:absolute;
      right: 14px;
      top: 41px;
      cursor:pointer;
      color:#666;
    }
    #errorBox{
      display:none;
      background:#ffecec;
      border:1px solid #ffbdbd;
      padding:10px 12px;
      border-radius:10px;
      margin:10px 0;
      color:#b10000;
      font-size:14px;
    }
    #okBox{
      display:none;
      background:#eafff7;
      border:1px solid #b7f3dc;
      padding:10px 12px;
      border-radius:10px;
      margin:10px 0;
      color:#0b6b4f;
      font-size:14px;
    }
    .rule-ok{ color:#0b6b4f; }
    .rule-ko{ color:#b10000; }
  </style>
</head>

<body>
<div class="container">
  <div class="wrapper">
    <div class="title" style="background-image:url('<?= base_url('public/assets/images/logonew.jpg'); ?>');background-size:contain;background-repeat:no-repeat;background-position-x:center;"></div>

    <form id="pwdForm" action="#" autocomplete="off">
      <span id="titolo">Password scaduta: imposta una nuova password</span>

      <div class="row" style="margin-top: 24px;">
        <label for="password">Nuova Password*:</label>
        <input type="password" id="password" placeholder="Nuova password" required>
        <i class="fa fa-eye-slash toggle-password" id="togglePassword"></i>
      </div>

      <ul id="password-rules" style="list-style-type:none;padding:0;text-align:left;margin-bottom:20px;color:#333;font-size:14px;">
        <li id="rule-length" class="rule-ko" style="margin-bottom:8px;">&#10060; Almeno 8 caratteri</li>
        <li id="rule-uppercase" class="rule-ko" style="margin-bottom:8px;">&#10060; Almeno una lettera maiuscola</li>
        <li id="rule-lowercase" class="rule-ko" style="margin-bottom:8px;">&#10060; Almeno una lettera minuscola</li>
        <li id="rule-special" class="rule-ko" style="margin-bottom:8px;">&#10060; Almeno un carattere speciale</li>
      </ul>

      <div class="row" style="margin-top: 10px;">
        <label for="password2">Ripeti Password*:</label>
        <input type="password" id="password2" placeholder="Ripeti password" required>
        <i class="fa fa-eye-slash toggle-password" id="togglePassword2"></i>
      </div>

      <div id="errorBox"></div>
      <div id="okBox">Password aggiornata. Reindirizzamento...</div>

      <div class="row button">
        <input type="button" id="submitPwd" value="Aggiorna Password">
      </div>
    </form>
  </div>
</div>

<script>
function setRule(id, ok){
  const el = $(id);
  if(ok){
    el.removeClass("rule-ko").addClass("rule-ok").html("&#9989; " + el.text().replace(/^(\s*[\u2716\u2705]\s*)/,''));
  } else {
    el.removeClass("rule-ok").addClass("rule-ko").html("&#10060; " + el.text().replace(/^(\s*[\u2716\u2705]\s*)/,''));
  }
}
function evalRules(p){
  setRule("#rule-length",    p.length >= 8);
  setRule("#rule-uppercase", /[A-Z]/.test(p));
  setRule("#rule-lowercase", /[a-z]/.test(p));
  setRule("#rule-special",   /[^A-Za-z0-9]/.test(p));
}
function togglePass(inputId, iconId){
  const inp = $(inputId);
  const ic  = $(iconId);
  const t = inp.attr("type") === "password" ? "text" : "password";
  inp.attr("type", t);
  ic.toggleClass("fa-eye-slash fa-eye");
}

$("#password").on("input", function(){ evalRules($(this).val() || ""); });
$("#togglePassword").on("click", function(){ togglePass("#password", "#togglePassword"); });
$("#togglePassword2").on("click", function(){ togglePass("#password2", "#togglePassword2"); });

function showErr(msg){
  $("#errorBox").text(msg).show();
}
function clearErr(){
  $("#errorBox").hide().text("");
}

$("#submitPwd").on("click", function(){
  clearErr();

  const p1 = $("#password").val() || "";
  const p2 = $("#password2").val() || "";

  if(!p1 || !p2){
    return showErr("Inserisci e ripeti la nuova password.");
  }
  if(p1 !== p2){
    return showErr("Le due password non coincidono.");
  }

  // blocco lato client coerente alle regole
  if(p1.length < 8 || !/[A-Z]/.test(p1) || !/[a-z]/.test(p1) || !/[^A-Za-z0-9]/.test(p1)){
    return showErr("La password non rispetta tutte le regole richieste.");
  }

  $.ajax({
    url: "<?= site_url('password/scaduta') ?>",
    method: "POST",
    data: JSON.stringify({password: p1, password2: p2}),
    contentType: "application/json",
    dataType: "json",
    success: function(r){
      if(r && r.ok && r.redirectUrl){
        $("#okBox").show();
        window.location.href = "<?= site_url('') ?>/" + r.redirectUrl;
      } else {
        if(r && r.err === "same_as_old"){
          showErr("Non puoi usare la stessa password appena scaduta.");
        } else if(r && r.err === "rules"){
          showErr("La password non rispetta tutte le regole richieste.");
        } else {
          showErr("Errore: " + (r && r.err ? r.err : "unknown"));
        }
      }
    },
    error: function(xhr){
      let msg = "Errore server.";
      try {
        const j = JSON.parse(xhr.responseText);
        if(j && j.err) msg = "Errore: " + j.err;
      } catch(e){}
      showErr(msg);
    }
  });
});
</script>
</body>
</html>

