$(document).ready(function(){

  function getUrlParameter(name) {
    name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
    var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
    var results = regex.exec(location.search);
    return results === null ? undefined : decodeURIComponent(results[1].replace(/\+/g, ' '));
  }

  var esito = getUrlParameter('esito');
  if (esito !== undefined) {
    $('#okLogin').css("display", "block");
  }

  function go(url){
    window.location.href = url;
  }

  function submitUsername(){
    $("#errorLogin").hide().html('');
    const username = ($("#username").val() || "").trim().toUpperCase();

    if(!username){
      $("#errorLogin").show().html('<span style="color:red">Inserisci lo username</span>');
      return;
    }

    $.ajax({
      url:  "./checkUsername",
      method: "POST",
      data: JSON.stringify({ username: username }),
      contentType: "application/json",
      dataType: "json",
      success: function(results){
        if(results && results.success){
          // ✅ vai SEMPRE a OTP (non /reset/auth)
          go( "./auth");
        } else {
          $("#errorLogin").show().html('<span style="color:red">'+(results.error || "Errore")+'</span>');
        }
      },
      error: function(xhr){
        let response = xhr.responseJSON || {};
       // $("#errorLogin").show().html('<span style="color:red">'+(response.error || "Errore imprevisto")+'</span>');
      }
    });
  }

  $('#userForm').on('keyup', function(e){
    var keyCode = e.keyCode || e.which;
    if(keyCode === 13){
      submitUsername();
    }
  });

  $(document).on('click', '#prosegui', function(){
    submitUsername();
  });

});
