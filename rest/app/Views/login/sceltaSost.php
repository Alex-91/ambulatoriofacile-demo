<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Scelta accesso</title>

  <script src="<?= base_url('public/assets/js/jquery.min.js'); ?>"></script>

  <style>
    body{font-family:system-ui;background:#f6f7fb;margin:0;padding:24px}
    .box{max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:18px;border:1px solid #eee}
    button{width:100%;padding:14px;border-radius:12px;border:0;cursor:pointer;margin-top:10px;font-size:16px}
    .me{background:#2c8895;color:#fff}
    .sost{background:#c0392b;color:#fff}
    .note{font-size:13px;color:#666;margin-top:10px;line-height:1.4}
    select{width:100%;padding:12px;border-radius:10px;border:1px solid #ddd;font-size:15px;margin-top:10px}
    label{display:block;margin-top:14px;font-size:13px;color:#333}
    .warn{margin-top:10px;font-size:13px;color:#c0392b;display:none}
    .busy{opacity:.7;cursor:wait}
    .loading-note{margin-top:10px;font-size:13px;color:#2c8895;display:none}
  </style>
</head>

<body>
  <div class="box">
    <h3>Come vuoi entrare?</h3>

    <div class="note">
      Sei in sostituzione attiva. Puoi entrare:
      <br>&bull; <b>come te stesso</b> (poi scegli le schede abilitate)
      <br>&bull; <b>come sostituto</b> (poi scegli le schede abilitate del medico sostituito, inclusa l'agenda)
    </div>

    <label for="id_personale"><b>Seleziona il medico da sostituire</b></label>
    <select id="id_personale" name="id_personale">
      <?php if (!empty($opts) && is_array($opts)): ?>
        <?php foreach ($opts as $o): ?>
          <?php
            $id  = (int)($o['id_personale'] ?? 0);
            $txt = ($o['nome_completo'] ?? '') !== ''
              ? (string)$o['nome_completo']
              : trim((string)($o['cognome'] ?? '') . ' ' . (string)($o['nome'] ?? ''));
          ?>
          <option value="<?= $id ?>"><?= esc($txt) ?></option>
        <?php endforeach; ?>
      <?php else: ?>
        <option value="0">Nessun medico disponibile</option>
      <?php endif; ?>
    </select>

    <div id="warn" class="warn">Seleziona un medico valido.</div>
    <div id="loadingNote" class="loading-note">Accesso in corso...</div>

    <button class="me" id="btnMe" type="button">Entra come me stesso</button>
    <button class="sost" id="btnSost" type="button">Entra come sostituto</button>
  </div>

<script>
(function(){
  const chooseUrl = "<?= site_url('sostituzioni/choose') ?>";
  const baseUrl   = "<?= rtrim(site_url(''), '/') ?>";

  function redirectTo(path){
    // path puo essere '' (home)
    if (typeof path !== "string") path = "";
    window.location.href = baseUrl + "/" + path;
  }

  function sendChoice(mode){
    $("#warn").hide();
    $("#loadingNote").show();
    $("#btnMe, #btnSost").prop("disabled", true).addClass("busy");

    const payload = { mode: mode };

    if (mode === "sost") {
      const idp = parseInt($("#id_personale").val() || "0", 10);
      if (!idp || idp <= 0) {
        $("#warn").show();
        $("#loadingNote").hide();
        $("#btnMe, #btnSost").prop("disabled", false).removeClass("busy");
        return;
      }
      payload.id_personale = idp;
    }

    $.ajax({
      url: chooseUrl,
      method: "POST",
      data: JSON.stringify(payload),
      contentType: "application/json",
      dataType: "json",
      success: function(r){
        if (r && r.ok) {
          redirectTo(r.redirectUrl || "");
        } else {
          $("#loadingNote").hide();
          $("#btnMe, #btnSost").prop("disabled", false).removeClass("busy");
          alert("Errore scelta: " + ((r && r.err) ? r.err : "unknown"));
        }
      },
      error: function(xhr){
        $("#loadingNote").hide();
        $("#btnMe, #btnSost").prop("disabled", false).removeClass("busy");
        console.log(xhr.responseText);
        alert("Errore server: " + xhr.status);
      }
    });
  }

  $("#btnMe").on("click", function(){ sendChoice("self"); });
  $("#btnSost").on("click", function(){ sendChoice("sost"); });
})();
</script>

</body>
</html>
