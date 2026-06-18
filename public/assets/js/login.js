$(document).ready(function(){
	function getUrlParameter(name) {
    name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
    var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
    var results = regex.exec(location.search);
    return results === null ? undefined : decodeURIComponent(results[1].replace(/\+/g, ' '));
}
    	var valoreVariabile = getUrlParameter('esito');
		
			// Controlla se la variabile è stata definita nella stringa di query
			if (valoreVariabile !== undefined) {
				// Esegui un'azione qui se la variabile esiste
				 $('#okLogin').css("display","block");
			}
		function login(username,password)
		{
            var dati = {
                "username" : username,
                "password" : password
            };
			  $.ajax({
                      url : "login",
                      method : 'POST',
                      data: JSON.stringify(dati),
                      dataType: 'json',
                      success : function(results){
                        console.log(results);
                          if(results.resp != null && results.resp == "OK"){
                        	  $(window.location).attr('href', './auth');
                          	}
							else if(results.resp != null && results.resp == "SCADENZA")
							{
							$(window.location).attr('href', './cambio');
							}
							else if(results.resp != null && results.resp == "SOST")
							{
							$(window.location).attr('href', './sceltaSost');
							}
							else if(results.resp != null && results.error_message == "SI")
							{
								alert("Errore di sistema per invio SMS");
							}
							else{
                              $('#errorLogin').css("display","block");
                              
                              return false;
                          }
                      }
                  });
		}
    	$('#userForm').on('keyup', function(e) {
    		//alert("ciao");
    		  var keyCode = e.keyCode || e.which;
    		  var dev="";
    		  if (keyCode === 13) { 
    			  var username = $("#username").val();
                  var password = $("#password").val();
				  login(username,password);
    		  
    		  }
    		});
    	$(document).on('click', '#submit', function(){
    		var dev="";
                var username = $("#username").val();
                var password = $("#password").val();
                login(username,password);
            });
		$(document).on('click', '#register', function(){
    		 $(window.location).attr('href', './register');
            });
			
		$(document).on('click', '#reset', function(){
    		 $(window.location).attr('href', './reset');
            });
             
    }); //function to display message to the user
          $(document).ready(function(){
        $("#togglePassword").on("mousedown touchstart", function(){  // Quando tieni premuto o tocchi
            $("#password").attr("type", "text");
            $(this).removeClass("fa-eye-slash").addClass("fa-eye");
        });

        $("#togglePassword").on("mouseup mouseleave touchend", function(){ // Quando rilasci
            $("#password").attr("type", "password");
            $(this).removeClass("fa-eye").addClass("fa-eye-slash");
        });
    });