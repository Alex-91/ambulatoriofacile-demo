$(document).ready(function(){

    $("#togglePassword").on("mousedown touchstart", function(){  // Quando tieni premuto o tocchi
        $("#password").attr("type", "text");
        $(this).removeClass("fa-eye-slash").addClass("fa-eye");
    });

    $("#togglePassword").on("mouseup mouseleave touchend", function(){ // Quando rilasci
        $("#password").attr("type", "password");
        $(this).removeClass("fa-eye").addClass("fa-eye-slash");
    });

    $("#togglePassword2").on("mousedown touchstart", function(){  // Quando tieni premuto o tocchi
        $("#password_rip").attr("type", "text");
        $(this).removeClass("fa-eye-slash").addClass("fa-eye");
    });

    $("#togglePassword2").on("mouseup mouseleave touchend", function(){ // Quando rilasci
        $("#password_rip").attr("type", "password");
        $(this).removeClass("fa-eye").addClass("fa-eye-slash");
    });
    	
    function login(password)
    {
        var dati = {
            "password" : password
        };
          $.ajax({
                  url : "./cambioPassword",
                  method : 'POST',
                  data: JSON.stringify(dati),
                  dataType: 'json',
                  success: function (results) {
                    if (results.success) {
                        // Registrazione riuscita -> Reindirizza alla pagina di login
                        $(window.location).attr('href', '../login');
                    } else if (results.error) {
                        // Se c'è un errore mostra il messaggio
                        $("#errorAuth").css("display", "block");
                    }
                },
                error: function (xhr) {
                    let response = xhr.responseJSON;
                    if (response && response.error) {
                        $("#errorAuth").css("display", "block");
                    } else {
                        $("#errorAuth").css("display", "block");
                    }
                }
              });
    }
    $('#userForm').on('keyup', function(e) {
        //alert("ciao");
          var keyCode = e.keyCode || e.which;
          var dev="";
          if (keyCode === 13) { 
              var password = $("#password").val();
              var password_rip = $("#password_rip").val();
              if(password!=password_rip)
              { $('#errorLogin').css("display","block");
              return false;}
              else
              {
              login(password);
              }
          
          }
        });
    $(document).on('click', '#submit', function(){
        var dev="";
            var password = $("#password").val();
              var password_rip = $("#password_rip").val();
              if(password!=password_rip)
              { $('#errorLogin').css("display","block");
              return false;}
              else
              {
              login(password);
              }
        });

        $("#password").on("keyup blur", function() {
            var password = $(this).val();
            var feedback = "";

            // Regex per controllare i criteri della password
            var minLength = 8;
            var hasUpperCase = /[A-Z]/.test(password);
            var hasLowerCase = /[a-z]/.test(password);
            var hasSpecialChar = /[!@#$%^&*(),.?":{}|<>]/.test(password);
            var isValid = true;

            // Controllo lunghezza minima
            if (password.length >= minLength) {
                $("#rule-length").html('&#9989; Almeno 8 caratteri').css('color', 'green');
            } else {
                $("#rule-length").html('&#10060; Almeno 8 caratteri').css('color', '#333');
                isValid = false;
            }

            // Controllo maiuscole
            if (hasUpperCase) {
                $("#rule-uppercase").html('&#9989; Almeno una lettera maiuscola').css('color', 'green');
            } else {
                $("#rule-uppercase").html('&#10060; Almeno una lettera maiuscola').css('color', '#333');
                isValid = false;
            }

            // Controllo minuscole
            if (hasLowerCase) {
                $("#rule-lowercase").html('&#9989; Almeno una lettera minuscola').css('color', 'green');
            } else {
                $("#rule-lowercase").html('&#10060; Almeno una lettera minuscola').css('color', '#333');
                isValid = false;
            }

            // Controllo caratteri speciali
            if (hasSpecialChar) {
                $("#rule-special").html('&#9989; Almeno un carattere speciale').css('color', 'green');
            } else {
                $("#rule-special").html('&#10060; Almeno un carattere speciale').css('color', '#333');
                isValid = false;
            }

            // Mostra i messaggi di feedback
            
        });
         
}); //function to display message to the user