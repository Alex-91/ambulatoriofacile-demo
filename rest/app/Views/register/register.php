<!DOCTYPE html>
<html><head>
       

<link rel="shortcut icon"  href="<?= base_url('public/assets/images/logonew.jpg'); ?>" />
<title><?= esc('AmbulatorioFacile') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="theme-color" content="#2c8895">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= esc('AmbulatorioFacile') ?>">
<link rel="apple-touch-icon" href="<?= base_url('public/assets/images/logonew.jpg'); ?>">     
<link rel="stylesheet" href="<?= base_url('public/assets/css/register.css'); ?>">
 <script src="<?= base_url('public/assets/js/jquery.min.js'); ?>"></script>
 <link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/all.min.css'); ?>">
 <script src="<?= base_url('public/assets/js/register.js'); ?>"></script>

 <script>
    $(document).ready(function(){
       
        $.ajax({
            url: "<?= base_url('api/doctors'); ?>",
            type: "GET",
            dataType: "json",
            success: function(data) {
                console.log(data);  // Aggiungi questa riga per verificare la risposta
                
                let select = $("#dottori");
                select.empty();
                select.append('<option value="">Seleziona un dottore</option>');

                $.each(data, function(index, doctor) {
                    select.append('<option value="' + doctor.id_personale + '">' + doctor.qualifica + ' ' + doctor.cognome + ' ' + doctor.nome + '</option>');
                });
            },
            error: function(xhr, status, error) {
                console.error("Errore nel caricamento dei dottori: " + error);
            }
        });

    });
		
        </script>
    </head>

<body>

        <!-- Top content -->
        		<div class="container">
      <div class="wrapper">
        <div class="title" style="background-image: url('public/assets/images/logonew.jpg');background-size: contain;background-repeat: no-repeat;background-position-x: center;"></div>
        <form id="userForm" action="#">
      <span id="titolo">Registrazione Utente</span>
      <div class="row">
        <label for="nome">Nome*:</label>
        <input type="text" id="nome" placeholder="Nome" required>
      </div>
      <div class="row">
        <label for="cognome">Cognome*:</label>
        <input type="text" id="cognome" placeholder="Cognome" required>
      </div>
	  <div class="row">
        <label for="codice_fiscale">Codice Fiscale* (VerrÃ  utilizzato come username):</label>
        <input type="text" id="codice_fiscale" placeholder="Codice Fiscale" required>
      </div>

	   <div class="row" style="margin-top: 58px;">
        <label for="password">Password*:</label>
        <input type="password" id="password" placeholder="Password" required>
						<i class="fa fa-eye-slash toggle-password" id="togglePassword"></i>

      </div>
	  <ul id="password-rules" style="list-style-type: none; padding: 0; text-align: left; margin-bottom: 20px; color: #333; font-size: 14px;">
            <li id="rule-length" style="margin-bottom: 8px;">
                &#10060; Almeno 8 caratteri
            </li>
            <li id="rule-uppercase" style="margin-bottom: 8px;">
                &#10060; Almeno una lettera maiuscola
            </li>
            <li id="rule-lowercase" style="margin-bottom: 8px;">
                &#10060; Almeno una lettera minuscola
            </li>
            <li id="rule-special" style="margin-bottom: 8px;">
                &#10060; Almeno un carattere speciale
            </li>
        </ul>
	   <div class="row" style="margin-top: 58px;">
        <label for="password2">Ripeti Password*:</label>
        <input type="password" id="password2" placeholder="Password" required>
		<i class="fa fa-eye-slash toggle-password" id="togglePassword2"></i>
      </div>
	  <div class="row">
        <label for="cellulare">Cellulare*:</label>
        <input type="text" id="cellulare" placeholder="Cellulare" required>
      </div>
	   <div class="row">
        <label for="cellulare2">Ripeti Cellulare*:</label>
        <input type="text" id="cellulare2" placeholder="Cellulare" required>
      </div>
	  <div class="row">
        <label for="email">Email*:</label>
        <input type="text" id="email" placeholder="Email" required>
      </div>
	  <div class="row">
        <label for="dottori">Dottore*:</label>
        <select id="dottori"></select>
      </div>
	   <div class="privacy-checkbox">
                <label>
                    <input type="checkbox" id="privacy" required />
                    Accetto la <a href="./privacy-policy.pdf" download>privacy policy</a> e i <a href="./cookie-policy.pdf" download>cookie policy</a>
                </label>
                <div id="privacy-error" style="color: red; display: none;"></div>
            </div>
      <div id="errorRegister">
        <span>Compilare tutti i campi obbligatori</span>
      </div>
      <div class="row button">
        <input type="button" id="submit" value="Registrati">
      </div>
    </form>
      </div>
    </div>
</body>

   
        
</html>

