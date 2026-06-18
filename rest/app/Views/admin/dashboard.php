<?php
/** @var array $messages */
/** @var int   $rangeStart */
/** @var int   $rangeEnd */
/** @var int   $total */
/** @var string|null $q */
/** @var int   $page */
/** @var int   $perPage */
/** @var bool  $hasPrev */
/** @var bool  $hasNext */
/** @var bool|null $requireDoctorSelection */
/** @var int|null  $selectedDoctorId */

$result = session()->get('menuDataAdmin');
$menu_items = $result['result'] ?? [];

// per admin non servono dottori, ma li lascio per compatibilitÃ  con la struttura
$dottori  = [];
$contDott = 0;

$folder         = $folder ?? 'inbox';          // default
$isSentFolder   = ($folder === 'sent');
$isDraftFolder  = ($folder === 'drafts');

$boxTitle       = $isDraftFolder ? 'Bozze' : ($isSentFolder ? 'Posta inviata' : 'Inbox');

// gestitaFilter potrebbe non essere settato per la sent o drafts
$gestitaFilter = $gestitaFilter ?? 'all';

$needDoctor = !empty($requireDoctorSelection);
?>
<html>
  <head>
    <meta charset="UTF-8">
    <title>AmbulatoriCLOUD | Posta</title>
    <link rel="icon" href="<?= base_url('public/assets/images/logonew.jpg') ?>" type="image/x-icon" sizes="any">
    <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>
    <!-- Bootstrap 3.3.4 -->
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <!-- Font Awesome Icons -->
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <!-- Ionicons -->
    <link href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css" rel="stylesheet" type="text/css" />
    <!-- fullCalendar 2.2.5-->
    <link href="<?= base_url('public/plugins/fullcalendar/fullcalendar.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/plugins/fullcalendar/fullcalendar.print.css') ?>" rel="stylesheet" type="text/css" media='print' />
    <!-- Theme style -->
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <!-- AmbulatoriCLOUD skins -->
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
    <!-- iCheck -->
    <link href="<?= base_url('public/plugins/iCheck/flat/blue.css') ?>" rel="stylesheet" type="text/css" />

    <style>
      #page-loader {
        position: fixed; inset: 0;
        background: rgba(255,255,255,.85);
        z-index: 9999; display: none;
      }
      #page-loader .spinner {
        position: absolute; top: 50%; left: 50%;
        transform: translate(-50%,-50%); text-align: center;
      }
      #page-loader .spinner .msg {
        margin-top: 10px; font-size: 13px; color: #444;
        letter-spacing:.2px;
      }
      .nav-pills.nav-stacked > li.active > a {
        background-color: #2c8895;
        color: #fff;
      }
    </style>
  </head>
  <body class="skin-blue sidebar-mini">
    <form id="open-message" method="post" style="display:none" action="<?= site_url('posta/read') ?>">
      <?= csrf_field() ?>
      <input type="hidden" id="csrf_name"  value="<?= csrf_token() ?>">
      <input type="hidden" id="csrf_value" value="<?= csrf_hash() ?>">
      <input type="hidden" name="uid" id="msg-uid">
      <input type="hidden" name="box" id="msg-box" value="<?= esc($folder) ?>">
    </form>

    <div id="page-loader" aria-hidden="true">
      <div class="spinner">
        <i class="fa fa-spinner fa-pulse fa-3x fa-fw"></i>
        <div class="msg">Caricamentoâ€¦</div>
      </div>
    </div>

    <div class="wrapper">

     
     <?= view('partials/header', [
    'menu_items' => $menu_items ?? [],
]) ?>



      <aside class="main-sidebar" style="display:none">
        <section class="sidebar">
          <!-- lasciata di default, ma nascosta -->
        </section>
      </aside>

      <!-- Content Wrapper. Contains page content -->
      <div class="content-wrapper">
        <section class="content-header">
          <h1>
            Posta
            <small></small>
          </h1>
          <ol class="breadcrumb">
            <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
            <li class="active">AdminPanel</li>
          </ol>
        </section>

        <!-- Main content -->
        <section class="content">
          <div class="row">
            <div class="col-md-3">

            <!-- COLONNA SINISTRA -->
           <?= view('partials/sidebar_admin', [
                'menu_items'       => $menu_items ?? [],
                'dottori'          => $dottori ?? [],
                'contDott'         => $contDott ?? 0,
                'selectedDoctorId' => $selectedDoctorId ?? null,
                'result'           => $result ?? [],
              ]) ?>


              
            </div><!-- /.col -->

            <!-- COLONNA DESTRA -->
            <div class="col-md-9">
              
            <div class="box box-primary">
              <div class="box-header with-border">
                <h3 class="box-title">Benvenuto in Admin</h3>
              </div>
              <div class="box-body">
                Qui andranno le varie voci (una per una) in base al menu admin.
                <hr>
                <p class="text-muted" style="margin:0;">
                  Struttura pronta: Controller + Model menu + Sidebar.
                </p>
                <hr>
                <a href="<?= site_url('admin/otp-statistiche') ?>" class="btn btn-primary">
                  <i class="fa fa-line-chart"></i> Apri statistiche OTP
                </a>
                <a href="<?= site_url('admin/whatsapp-reminders') ?>" class="btn btn-success" style="margin-left:8px;">
                  <i class="fa fa-whatsapp"></i> Stato reminder WhatsApp
                </a>
              </div>
            </div>
            </div><!-- /.col -->
          </div><!-- /.row -->
        </section><!-- /.content -->
      </div><!-- /.content-wrapper -->

      <footer class="main-footer">
        <div class="pull-right hidden-xs">
          <b>Version</b> 2.0
        </div>
        <strong>&copy; AmbulatoriCLOUD</strong>
      </footer>

      <aside class="control-sidebar control-sidebar-dark">
      </aside>
      <div class='control-sidebar-bg'></div>
    </div><!-- ./wrapper -->

    <!-- jQuery 2.1.4 -->
    <script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
    <!-- Bootstrap 3.3.2 JS -->
    <script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>" type="text/javascript"></script>
    <!-- Slimscroll -->
    <script src="<?= base_url('public/plugins/slimScroll/jquery.slimscroll.min.js') ?>" type="text/javascript"></script>
    <!-- FastClick -->
    <script src="<?= base_url('public/plugins/fastclick/fastclick.min.js') ?>"></script>
    <!-- AmbulatoriCLOUD app shell -->
    <script src="<?= base_url('public/dist/js/app.min.js') ?>" type="text/javascript"></script>
    <!-- iCheck -->
    <script src="<?= base_url('public/plugins/iCheck/icheck.min.js') ?>" type="text/javascript"></script>
    <!-- Demo -->
    <script src="<?= base_url('public/dist/js/demo.js') ?>" type="text/javascript"></script>

    <script>
      $(function () {
        $('.mailbox-messages input[type="checkbox"]').iCheck({
          checkboxClass: 'icheckbox_flat-blue',
          radioClass: 'iradio_flat-blue'
        });

        $(".checkbox-toggle").click(function () {
          var clicks = $(this).data('clicks');
          if (clicks) {
            $(".mailbox-messages input[type='checkbox']").iCheck("uncheck");
            $(".fa", this).removeClass("fa-check-square-o").addClass('fa-square-o');
          } else {
            $(".mailbox-messages input[type='checkbox']").iCheck("check");
            $(".fa", this).removeClass("fa-square-o").addClass('fa-check-square-o');
          }
          $(this).data("clicks", !clicks);
        });

        // vecchio toggle visuale stella
        $(".mailbox-star").click(function (e) {
          e.preventDefault();
          var $this = $(this).find("a > i");
          var glyph = $this.hasClass("glyphicon");
          var fa = $this.hasClass("fa");
          if (glyph) {
            $this.toggleClass("glyphicon-star");
            $this.toggleClass("glyphicon-star-empty");
          }
          if (fa) {
            $this.toggleClass("fa-star");
            $this.toggleClass("fa-star-o");
          }
        });
      });
    </script>

    <script>
      (function() {
        var loader = $('#page-loader');
        function showLoader(){ loader.stop(true,true).fadeIn(100); }
        function hideLoader(){ loader.stop(true,true).fadeOut(150); }

        $(document).on('click', 'a[href]:not([href^="#"]):not([target="_blank"]):not(.no-loader)', function(e){
          if (e.ctrlKey || e.shiftKey || e.metaKey || (this.href && this.href.indexOf('javascript:') === 0)) return;
          var $a = $(this);
          if ($a.attr('data-toggle') === 'dropdown' || $a.attr('data-toggle') === 'collapse' || $a.data('widget') === 'collapse') return;
          showLoader();
        });

        $(document).on('submit', 'form:not(.no-loader)', function(){ showLoader(); });

        window.addEventListener('beforeunload', function(){ showLoader(); });

        $(window).on('load', function(){ hideLoader(); });

        // ðŸ”„ REFRESH POSTA VIA AJAX
        $('.btn-refresh-mail').on('click', function (e) {
          e.preventDefault();
          showLoader();

          $.get(window.location.href)
            .done(function (html) {
              var $html = $(html);
              var $newTableContent = $html.find('.table-responsive.mailbox-messages').html();

              if ($newTableContent && $newTableContent.length) {
                $('.table-responsive.mailbox-messages').html($newTableContent);

                $('.mailbox-messages input[type="checkbox"]').iCheck({
                  checkboxClass: 'icheckbox_flat-blue',
                  radioClass: 'iradio_flat-blue'
                });
              } else {
                console.warn('Impossibile trovare .table-responsive.mailbox-messages nella risposta');
              }
            })
            .fail(function () {
              alert('Errore durante l\'aggiornamento della posta.');
            })
            .always(function () {
              hideLoader();
            });
        });

      })();
    </script>

    <?php if (!$isSentFolder && !$isDraftFolder): ?>
    <script>
      $(function(){
        // Elimina messaggi selezionati
        $('.btn-delete-mail').on('click', function (e) {
          e.preventDefault();

          var ids = [];
          $('.row-check:checked').each(function () {
            ids.push($(this).val());
          });

          if (!ids.length) {
            alert('Seleziona almeno un messaggio da eliminare.');
            return;
          }

          if (!confirm('Vuoi eliminare i messaggi selezionati?')) {
            return;
          }

          var csrfName  = $('#csrf_name').val();
          var csrfValue = $('#csrf_value').val();

          var data = { ids: ids };
          if (csrfName && csrfValue) data[csrfName] = csrfValue;

          $.ajax({
            url: "<?= site_url('posta/bulkDelete') ?>",
            type: "POST",
            dataType: "json",
            data: data
          })
          .done(function (resp) {
            if (!resp || !resp.ok) {
              alert('Errore durante l\'eliminazione dei messaggi.');
              return;
            }

            if (resp.csrfName && resp.csrfHash) {
              $('#csrf_name').val(resp.csrfName);
              $('#csrf_value').val(resp.csrfHash);
            }

            $('.btn-refresh-mail').trigger('click');
          })
          .fail(function () {
            alert('Errore di comunicazione con il server.');
          });
        });
      });
    </script>

    <script>
      $(function () {
        $('.btn-toggle-gestita').on('click', function (e) {
          e.preventDefault();

          var ids = [];
          $('.row-check:checked').each(function () {
            ids.push($(this).val());
          });

          if (!ids.length) {
            alert('Seleziona almeno una mail.');
            return;
          }

          var csrfName  = $('#csrf_name').val();
          var csrfValue = $('#csrf_value').val();

          var data = { ids: ids };
          if (csrfName && csrfValue) data[csrfName] = csrfValue;

          $.ajax({
            url: "<?= site_url('posta/bulkGestita') ?>",
            type: "POST",
            dataType: "json",
            data: data
          })
          .done(function (resp) {
            if (!resp || !resp.ok) {
              alert('Errore durante l\'aggiornamento dello stato gestita.');
              return;
            }

            if (resp.csrfName && resp.csrfHash) {
              $('#csrf_name').val(resp.csrfName);
              $('#csrf_value').val(resp.csrfHash);
            }

            $('.btn-refresh-mail').trigger('click');
          })
          .fail(function () {
            alert('Errore di comunicazione con il server.');
          });
        });
      });
    </script>
    <?php endif; ?>

    <script>
      $(function(){
        var isDraftFolder = <?= $isDraftFolder ? 'true' : 'false' ?>;

        $(".mailbox-messages").on("click", "tr[data-id]", function(e){
          if ($(e.target).is("input[type=checkbox], .toggle-star, .toggle-star i")) return;

          var uid = $(this).data("id"); // "M:123" oppure "R:..."
          if (!uid) return;

          if (isDraftFolder) {
            var parts = String(uid).split(':');
            var id = (parts.length === 2) ? parts[1] : '';
            if (!id) return;

            // apro la compose in modalitÃ  draft
            window.location.href = "<?= site_url('compose') ?>" + "?id_message=" + encodeURIComponent(id) + "&mode=draft";
            return;
          }

          $("#msg-uid").val(uid);
          $("#open-message").trigger("submit");
        });
      });
    </script>
    <script src="<?= base_url('js/chat-notify.js') ?>"></script>

  </body>
</html>
