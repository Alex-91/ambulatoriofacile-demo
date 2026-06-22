<!DOCTYPE html>
<html>
  <head>
    <meta charset="UTF-8">
    <title>AmbulatorioFacile | Chat</title>
        <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
<script src="<?= base_url('public/js/chat-notify.js') ?>"></script>
    <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>
    <!-- Bootstrap 3.3.4 -->
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <!-- Font Awesome Icons -->
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <!-- Ionicons -->
    <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" type="text/css" />
    <!-- fullCalendar 2.2.5 (lasciato come nel template) -->
    <link href="<?= base_url('public/plugins/fullcalendar/fullcalendar.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/plugins/fullcalendar/fullcalendar.print.css') ?>" rel="stylesheet" type="text/css" media='print' />
    <!-- AmbulatorioFacile theme -->
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
    <!-- iCheck -->
    <link href="<?= base_url('public/plugins/iCheck/flat/blue.css') ?>" rel="stylesheet" type="text/css" />
  </head>
  <body class="skin-blue sidebar-mini">
    <div class="wrapper">

      <!-- HEADER identico -->
       <?= view('partials/header') ?>


      <!-- Sidebar nascosta identica -->
      <aside class="main-sidebar" style="display:none">
        <section class="sidebar"></section>
      </aside>

      <!-- CONTENT WRAPPER -->
      <div class="content-wrapper">
        <!-- Content Header -->
        <section class="content-header">
          <h1>Chat</h1>
          <ol class="breadcrumb">
            <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
            <li class="active">Chat</li>
          </ol>
        </section>

        <!-- Main content -->
        <section class="content">
          <div class="row">
            <!-- Colonna sinistra (menu chat demo) -->
            <div class="col-md-3">
              <a href="#" class="btn btn-primary btn-block margin-bottom" disabled>Nuova conversazione</a>

              <div class="box box-solid" style="margin-bottom:0!important">
                <div class="box-header with-border">
                  <h3 class="box-title">Canali</h3>
                  <div class="box-tools"><button class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button></div>
                </div>
                <div class="box-body no-padding">
                  <ul class="nav nav-pills nav-stacked">
                    <li class="active"><a href="#"><i class="fa fa-hashtag"></i> generale</a></li>
                    <li><a href="#"><i class="fa fa-user-md"></i> ambulatorio</a></li>
                    <li><a href="#"><i class="fa fa-home"></i> domiciliari</a></li>
                  </ul>
                </div>
              </div>

              <div class="box box-solid">
                <div class="box-header with-border">
                  <h3 class="box-title">Utenti (demo)</h3>
                  <div class="box-tools"><button class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button></div>
                </div>
                <div class="box-body no-padding">
                  <ul class="nav nav-pills nav-stacked">
                    <li><a href="#"><i class="fa fa-circle text-green"></i> Alice</a></li>
                    <li><a href="#"><i class="fa fa-circle text-green"></i> Marco</a></li>
                    <li><a href="#"><i class="fa fa-circle text-muted"></i> Sara</a></li>
                  </ul>
                </div>
              </div>
            </div>

            <!-- Colonna destra (Direct Chat) -->
            <div class="col-md-9">
              <div class="box box-primary direct-chat direct-chat-primary">
                <div class="box-header with-border">
                  <h3 class="box-title"><i class="fa fa-comments"></i> Chat in tempo reale (demo UI)</h3>
                  <div class="box-tools pull-right">
                    <span class="badge bg-light-blue" title="Utenti online">3</span>
                    <button type="button" class="btn btn-box-tool" data-widget="chat-pane-toggle" title="Contatti"><i class="fa fa-user"></i></button>
                    <button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
                  </div>
                </div>

                <div class="box-body">
                  <!-- Messaggi di esempio -->
                  <div class="direct-chat-messages" id="chatMessages" style="height:380px;">
                    <div class="direct-chat-msg">
                      <div class="direct-chat-info clearfix">
                        <span class="direct-chat-name pull-left">Alice</span>
                        <span class="direct-chat-timestamp pull-right">11/08/2025 15:21</span>
                      </div>
                      <img class="direct-chat-img" src="https://i.pravatar.cc/64?img=5" alt="Alice">
                      <div class="direct-chat-text">Ciao! Hai visto il referto di Rossi?</div>
                    </div>

                    <div class="direct-chat-msg right">
                      <div class="direct-chat-info clearfix">
                        <span class="direct-chat-name pull-right">Tu</span>
                        <span class="direct-chat-timestamp pull-left">11/08/2025 15:22</span>
                      </div>
                      <img class="direct-chat-img" src="https://i.pravatar.cc/64?img=1" alt="Tu">
                      <div class="direct-chat-text">SÃ¬, tutto ok. Lo invio in posta tra poco.</div>
                    </div>

                    <div class="direct-chat-msg">
                      <div class="direct-chat-info clearfix">
                        <span class="direct-chat-name pull-left">Marco</span>
                        <span class="direct-chat-timestamp pull-right">11/08/2025 15:23</span>
                      </div>
                      <img class="direct-chat-img" src="https://i.pravatar.cc/64?img=11" alt="Marco">
                      <div class="direct-chat-text">Perfetto, grazie! ðŸ‘Œ</div>
                    </div>
                  </div>

                  <!-- Pannello contatti (toggle col bottone utente) -->
                  <div class="direct-chat-contacts">
                    <ul class="contacts-list">
                      <li>
                        <a href="#">
                          <img class="contacts-list-img" src="https://i.pravatar.cc/64?img=5" alt="Alice">
                          <div class="contacts-list-info">
                            <span class="contacts-list-name">Alice <small class="contacts-list-date pull-right">online</small></span>
                            <span class="contacts-list-msg">Ambulatorio 2</span>
                          </div>
                        </a>
                      </li>
                      <li>
                        <a href="#">
                          <img class="contacts-list-img" src="https://i.pravatar.cc/64?img=11" alt="Marco">
                          <div class="contacts-list-info">
                            <span class="contacts-list-name">Marco <small class="contacts-list-date pull-right">online</small></span>
                            <span class="contacts-list-msg">Domiciliari</span>
                          </div>
                        </a>
                      </li>
                      <li>
                        <a href="#">
                          <img class="contacts-list-img" src="https://i.pravatar.cc/64?img=2" alt="Sara">
                          <div class="contacts-list-info">
                            <span class="contacts-list-name">Sara <small class="contacts-list-date pull-right">5 min fa</small></span>
                            <span class="contacts-list-msg">In pausa</span>
                          </div>
                        </a>
                      </li>
                    </ul>
                  </div>
                </div>

                <!-- Footer input (disabilitato demo) -->
                <div class="box-footer">
                  <form onsubmit="return false;">
                    <div class="input-group">
                      <input type="text" class="form-control" placeholder="Scrivi un messaggio... (demo)">
                      <span class="input-group-btn">
                        <button type="button" class="btn btn-primary btn-flat" disabled><i class="fa fa-paper-plane"></i></button>
                      </span>
                    </div>
                  </form>
                </div>

              </div>
            </div><!-- /.col-md-9 -->
          </div><!-- /.row -->
        </section>
      </div><!-- /.content-wrapper -->

      <!-- FOOTER identico -->
      <footer class="main-footer">
        <div class="pull-right hidden-xs"><b>Version</b> 2.0</div>
        <strong>Copyright &copy; AmbulatorioFacile.</strong> Tutti i diritti riservati.
      </footer>

      <!-- Control Sidebar identica -->
      <aside class="control-sidebar control-sidebar-dark">
        <ul class="nav nav-tabs nav-justified control-sidebar-tabs">
          <li><a href="#control-sidebar-home-tab" data-toggle="tab"><i class="fa fa-home"></i></a></li>
       
        </ul>
        <div class="tab-content">
          <div class="tab-pane" id="control-sidebar-home-tab"></div>
          <div class="tab-pane" id="control-sidebar-stats-tab"></div>
          <div class="tab-pane" id="control-sidebar-settings-tab"></div>
        </div>
      </aside>
      <div class='control-sidebar-bg'></div>
    </div><!-- ./wrapper -->

    <!-- JS identici -->
    <script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
    <script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>" type="text/javascript"></script>
    <script src="<?= base_url('public/plugins/slimScroll/jquery.slimscroll.min.js') ?>" type="text/javascript"></script>
    <script src="<?= base_url('public/plugins/fastclick/fastclick.min.js') ?>"></script>
    <script src="<?= base_url('public/dist/js/app.min.js') ?>" type="text/javascript"></script>
    <script src="<?= base_url('public/plugins/iCheck/icheck.min.js') ?>" type="text/javascript"></script>
    <script>
      $(function () {
        // Solo per la demo: scroll in fondo ai messaggi
        var box = $('#chatMessages'); box.scrollTop(box[0].scrollHeight);
      });
    </script>
    <script src="<?= base_url('public/dist/js/demo.js') ?>" type="text/javascript"></script>
  </body>
</html>
