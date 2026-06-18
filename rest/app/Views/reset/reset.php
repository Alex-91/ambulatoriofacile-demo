<!DOCTYPE html>
<html><head>
       

<link rel="shortcut icon"  href="<?= base_url('public/assets/images/logonew.jpg'); ?>" />
<title><?= esc('AmbulatoriCLOUD') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="theme-color" content="#2c8895">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= esc('AmbulatoriCLOUD') ?>">
<link rel="apple-touch-icon" href="<?= base_url('public/assets/images/logonew.jpg'); ?>">     
<link rel="stylesheet" href="<?= base_url('public/assets/css/reset.css'); ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/css/login.css'); ?>">
 <script src="<?= base_url('public/assets/js/jquery.min.js'); ?>"></script>
 <link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/all.min.css'); ?>">
 <script src="<?= base_url('public/assets/js/reset.js'); ?>"></script>
 <script>
$(document).ready(function () {

  // quando premi INVIO dentro il form, non fare submit classico (refresh)
  $("#userForm").on("submit", function (e) {
    e.preventDefault();
    $("#prosegui").trigger("click"); // usa la stessa logica del bottone
  });

  // opzionale: se premi invio dentro l'input username, forza submit del form
  $("#username").on("keydown", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      $("#userForm").trigger("submit");
    }
  });

});

  </script>
    </head>

<body>

        <!-- Top content -->
        		<div class="container">
      <div class="wrapper">
      <div class="title" style="background-image: url('<?= base_url('public/assets/images/logonew.jpg'); ?>'); background-size: contain; background-repeat: no-repeat; background-position-x: center;"></div>
      <form id="userForm" action="#">
		
          <div class="row">
            <i class="fa fa-user"></i>
            <input type="text" id="username" placeholder="Username o Codice Fiscale" required>
          </div>
          
		  <div id="errorLogin" style="text-align: center;display:none">
           
				
            
          </div>
          <div class="row button">
            <input type="button" id="prosegui" value="Prosegui">
          </div>
		   
        </form>
      </div>
    </div>
</body>

   
        
</html>

