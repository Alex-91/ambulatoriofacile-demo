$(document).ready(function(){
	
	$("#auth1").click();
	$("#auth1").focus();
	
	
	    	$(document).on('keyup', '#auth1', function(){
			
			if(!$.isNumeric( $("#auth1").val() ))
			{
				$("#auth1").val("");
			}
			else
			{
				$("#auth2").focus();
			}
			
    	});
		$(document).on('keyup', '#auth2', function(){
			
			if(!$.isNumeric( $("#auth2").val() ) && event.keyCode != 8)
			{
				$("#auth2").val("");
			}
			else if (event.keyCode == 8 ) {
				$("#auth1").focus();
				}
			else
			{
				$("#auth3").focus();
			}
			
    	});
		$(document).on('keyup', '#auth3', function(){
			
			if(!$.isNumeric( $("#auth3").val() ) && event.keyCode != 8)
			{
				$("#auth3").val("");
			}
			else if (event.keyCode == 8 ) {
				$("#auth2").focus();
				}
			else
			{
				$("#auth4").focus();
			}
			
    	});
		$(document).on('keyup', '#auth4', function(){
			
			if(!$.isNumeric( $("#auth4").val() ) && event.keyCode != 8)
			{
				$("#auth4").val("");
			}
			else if (event.keyCode == 8 ) {
				$("#auth3").focus();
				}
			
			
			
    	});
	
		
		function login(authCode)
		{
            var  dati = {
                "authCode" : authCode
            };
			  $.ajax({
                      url : "./checkOtp",
                      method : 'POST',
                      data: JSON.stringify(dati),
                      dataType: 'json',
                      success: function (results) {
                        if (results.success) {
                            // Registrazione riuscita -> Reindirizza alla pagina di login
                            $(window.location).attr('href', './'+results.redirectUrl);
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
                       //     $("#errorAuth").css("display", "block");
                        }
                    }
                     
                  });
		}
    	$('#userForm').on('keyup', function(e) {
    		//alert("ciao");
    		  var keyCode = e.keyCode || e.which;
    		  var dev="";
    		  if (keyCode === 13) { 
    			  var authCode = $("#auth1").val();
				  authCode = authCode+$("#auth2").val();
				  authCode = authCode+$("#auth3").val();
				  authCode = authCode+$("#auth4").val();
				  login(authCode);
    		  
    		  }
    		});
    	$(document).on('click', '#submit', function(){
    		var dev="";
                var authCode = $("#auth1").val();
				  authCode = authCode+$("#auth2").val();
				  authCode = authCode+$("#auth3").val();
				  authCode = authCode+$("#auth4").val();
                login(authCode);
            });
           	$(document).on('click', '#newCodice', function(){
					$.ajax({
                      url : "./auth",
                      method : 'GET',
                      data : {
                      },
                      dataType: 'json',
                      success : function(results){
                     
                          
                              
                              return false;
                          
                      }
                  });
            });  
			/*$(document).on('click', '#newCodiceSMS', function(){
					$.ajax({
                      url : "./authSMS",
                      method : 'POST',
                      data : {
                      },
                      dataType: 'json',
                      success : function(results){
                  
                          
                      }
                  });
            });*/
    }); 