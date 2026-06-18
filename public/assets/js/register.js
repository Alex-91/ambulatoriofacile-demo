
       
         $(document).ready(function(){		
		  $('#cellulare2').on('paste', function(e) {
        e.preventDefault(); // Previene l'azione di incollare
    });

    // Disabilita suggerimenti automatici su #cellulare2
    $('#cellulare2').attr('autocomplete', 'off');
    $('#cellulare2').attr('autocorrect', 'off');  // iOS e Safari
    $('#cellulare2').attr('autocapitalize', 'off'); // iOS e Safari
    $('#cellulare2').attr('spellcheck', 'false');  
	
	
		function isMaggiorenne(codiceFiscale) {
    // Controlla la lunghezza del codice fiscale
    if (codiceFiscale.length !== 16) {
        return false;
    }

    // Estrai i caratteri relativi alla data di nascita
    var anno = parseInt(codiceFiscale.substr(6, 2));
    var mese = codiceFiscale.substr(8, 1);
    var giorno = parseInt(codiceFiscale.substr(9, 2));

    // Converti il mese da lettera a numero
    var mesi = {
        'A': 1, 'B': 2, 'C': 3, 'D': 4, 'E': 5, 'H': 6,
        'L': 7, 'M': 8, 'P': 9, 'R': 10, 'S': 11, 'T': 12
    };
    mese = mesi[mese.toUpperCase()];

    // Correggi il giorno se superiore a 31 (sesso femminile)
    if (giorno > 31) {
        giorno -= 40;
    }

    // Determina il secolo dell'anno di nascita
    var currentYear = new Date().getFullYear() % 100;
    var secolo;
    if (anno <= currentYear) {
        secolo = 2000;
    } else {
        secolo = 1900;
    }
    anno += secolo;

    // Calcola la data di nascita
    var dataNascita = new Date(anno, mese - 1, giorno);

    // Calcola l'età
    var today = new Date();
    var age = today.getFullYear() - dataNascita.getFullYear();
    var monthDifference = today.getMonth() - dataNascita.getMonth();

    // Se il compleanno non è ancora arrivato quest'anno, sottrai 1 anno
    if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < dataNascita.getDate())) {
        age--;
    }

    // Controlla se la persona è maggiorenne
    return age >= 18;
}

		
		 $('#codice_fiscale').on('keyup', function() {
        // Converti il testo inserito in maiuscolo
        $(this).val($(this).val().toUpperCase());
    });
	
      
    
    	
		function login()
		{
						$('input').removeClass('error');
         $('#errorRegister').css("display","none");
        // Controllo se tutti i campi sono compilati
							var isValid = true;
							$('input[required]').each(function(){
								if ($.trim($(this).val()) == '') {
									
									isValid = false;
									$(this).addClass('error'); // Aggiunge la classe 'error' se un campo è vuoto
								}
							});
							 var valoreSelezionato = $('#dottori').val(); // Ottiene il valore selezionato dalla select
								if(valoreSelezionato === '') {
									// La select non è stata selezionata
									$('#dottori').addClass('error');
									isValid = false;
								} 
							
							// Controllo del codice fiscale
							
							if (!$('#privacy').prop('checked')) {
                    isValid = false;
                    $('#privacy-error').css("display", "block").html("<span>Devi accettare la privacy policy</span>");
                } else {
                    $('#privacy-error').css("display", "none");
                }
        // Mostra un messaggio di errore se non tutti i controlli sono passati
						if (!isValid) {
						  $('#errorRegister').css("display","block").html("<span>Compilare tutti i campi obbligatori</span>");
						return false;
							}
					else
					{
						isValidCod=true;
						isValidCodMag=true;
						isValidCel=true;
						isValidEma=true;
						isValidPassword=true;
						isValidPasswordEqual=true;
						isValidCellularEqual=true;
						var codiceFiscale = $('#codice_fiscale').val();
							var cfRegex = /^[A-Za-z]{6}\d{2}[A-Za-z]\d{2}[A-Za-z]\d{3}[A-Za-z]$/;
							if (!cfRegex.test(codiceFiscale)) {
								isValidCod = false;
								$('#codice_fiscale').addClass('error'); // Aggiunge la classe 'error' se il codice fiscale non è valido
							}
							if (!isMaggiorenne(codiceFiscale)) {
								
								isValidCodMag = false;
								$('#codice_fiscale').addClass('error');
							}
							// Controllo del cellulare
							var cellulare = $('#cellulare').val();
							var cellulareRegex = /^\d+$/;
							if (!cellulareRegex.test(cellulare)) {
								isValidCel = false;
								$('#cellulare').addClass('error'); // Aggiunge la classe 'error' se il cellulare non è valido
							}
							
							
							// Controllo dell'email
							var email = $('#email').val();
							var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
							if (!emailRegex.test(email)) {
								isValidEma = false;
								$('#email').addClass('error'); // Aggiunge la classe 'error' se l'email non è valida
							}
							
							var password=$("#password").val();
							var minLength = 8;
							var hasUpperCase = /[A-Z]/.test(password);
							var hasLowerCase = /[a-z]/.test(password);
							var hasSpecialChar = /[!@#$%^&*(),.?":{}|<>]/.test(password);

							// Controllo lunghezza minima
							if (password.length < minLength) {
								isValidPassword = false;
							}

							// Controllo maiuscole
							if (!hasUpperCase) {
								isValidPassword = false;
							}

							// Controllo minuscole
							if (!hasLowerCase) {
								isValidPassword = false;
							}

							// Controllo caratteri speciali
							if (!hasSpecialChar) {
								isValidPassword = false;
							}

							
							var password2=$("#password2").val();
							if(password!=password2)
							{
								isValidPasswordEqual=false;
								
							}
							
							var cellulare2=$("#cellulare2").val();
							if(cellulare!=cellulare2)
							{
								isValidCellularEqual=false;
								
							}
							
						
						if (isValidEma && isValidCod && isValidCel && isValidCodMag && isValidPassword && isValidPasswordEqual && isValidCellularEqual) {
							
							 var nome = $('#nome').val();
        var cognome = $('#cognome').val();
        var codiceFiscale = $('#codice_fiscale').val();
        var cellulare = $('#cellulare').val();
        var email = $('#email').val();
        var dottore = $('#dottori').val();
                var password = $('#password').val();

      
        
        // Creazione dell'oggetto dati da inviare
		var dati = {
			nome: nome,
			cognome: cognome,
			codice_fiscale: codiceFiscale,
			cellulare: cellulare,
			email: email,
			dottore: dottore,
			password: password
		};
		var baseUrl = "<?= base_url('register/salva'); ?>";
			  $.ajax({
                      url: './register/salva',
                      method : 'POST',
					  data: JSON.stringify(dati),
                      dataType: 'json',
					  success: function (results) {
						if (results.success) {
							// Registrazione riuscita -> Reindirizza alla pagina di login
							$(window.location).attr('href', './login?esito=ok');
						} else if (results.error) {
							// Se c'è un errore mostra il messaggio
							$("#errorRegister").css("display", "block").html("<span>" + results.error + "</span>");
						}
					},
					error: function (xhr) {
						let response = xhr.responseJSON;
		
						if (response && response.error) {
							$("#errorRegister").css("display", "block").html("<span>" + response.error + "</span>");
						} else {
							$("#errorRegister").css("display", "block").html("<span>Si è verificato un errore imprevisto.</span>");
						}
					}
                  });
					}
					else
					{
						if (!isValidCod) {
							 $('#errorRegister').css("display","block").html("<span>Codice fiscale errato</span>");
						}
						else if (!isValidCel) {
							 $('#errorRegister').css("display","block").html("<span>Cellulare errato</span>");
						}
						else if (!isValidEma) {
							 $('#errorRegister').css("display","block").html("<span>Indirizzo email errato</span>");
						}
						else if(!isValidCodMag)
						{
														 $('#errorRegister').css("display","block").html("<span>Registrazione abilitata solo per maggiorenni</span>");
						}
						else if(!isValidPassword)
						{

								$('#password').addClass('error');
								$('#errorRegister').css("display","block").html("<span>Password non conforme</span>");
								}
						else if(!isValidPasswordEqual)
						{
								$('#password').addClass('error');
								$('#password2').addClass('error');
								$('#errorRegister').css("display","block").html("<span>Password non coincidono</span>");
						}
						else if(!isValidCellularEqual)
						{
								$('#cellulare').addClass('error');
								$('#cellulare').addClass('error');
								$('#errorRegister').css("display","block").html("<span>Cellulare non coincide</span>");
						}
					}
					}
		}
    	$('#userForm').on('keyup', function(e) {
    		//alert("ciao");
    		  var keyCode = e.keyCode || e.which;
    		  var dev="";
    		  if (keyCode === 13) { 
    			 
				  login();
				  
    		  
    		  }
    		});
    	$(document).on('click', '#submit', function(){
    		
				  login();
				  
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
	
	$(document).ready(function(){
        $("#togglePassword2").on("mousedown touchstart", function(){  // Quando tieni premuto o tocchi
            $("#password2").attr("type", "text");
            $(this).removeClass("fa-eye-slash").addClass("fa-eye");
        });

        $("#togglePassword2").on("mouseup mouseleave touchend", function(){ // Quando rilasci
            $("#password2").attr("type", "password");
            $(this).removeClass("fa-eye").addClass("fa-eye-slash");
        });
    });