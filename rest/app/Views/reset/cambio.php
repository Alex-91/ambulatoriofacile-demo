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
 <script src="<?= base_url('public/assets/js/cambio.js'); ?>"></script>

    </head>

<body>

        <!-- Top content -->
        		<div class="container">
      <div class="wrapper">
      <div class="title" style="background-image:url('<?= base_url('public/assets/images/logo-header.svg'); ?>');"></div>
      <form id="userForm" action="#">
		<span id="titolo" style="text-align: center;width: 100%;justify-content: center;display: block;font-weight: bold;">Modifica Password</span>
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
        <label for="password_rip">Ripeti Password*:</label>
        <input type="password" id="password_rip" placeholder="Password" required>
		<i class="fa fa-eye-slash toggle-password" id="togglePassword2"></i>
      </div>
		  <div id="errorLogin" style="text-align: center;display:none">
           
				<span style="color:red"><b>Password non coincidono o non soddisfano i requisiti</b></span>
            
          </div>
          <div class="row button">
            <input type="button" id="submit" value="Cambia password">
          </div>
        </form>
      </div>
    </div>
</body>

   
        
</html>

