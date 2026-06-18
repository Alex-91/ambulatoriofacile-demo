<!DOCTYPE html>
<html>
  <head>
    <meta charset="UTF-8">
    <title>AmbulatoriCLOUD | Agenda</title>
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
    <!-- AmbulatoriCLOUD theme -->
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
    <!-- iCheck -->
    <link href="<?= base_url('public/plugins/iCheck/flat/blue.css') ?>" rel="stylesheet" type="text/css" />
    <style>
      #calendar .fc-time-grid-container,
      #calendar .fc-scroller {
        overflow-x: hidden !important;
      }
    </style>
  </head>
  <body class="skin-blue sidebar-mini">
    <div class="wrapper">

  <!-- HEADER identico -->
        <?= view('partials/header', [
    'menu_items' => $menu_items ?? [],
]) ?>


      <!-- Sidebar (nascosta ma lasciata per coerenza) -->
      <aside class="main-sidebar" style="display:none"><section class="sidebar"></section></aside>

      <!-- ============ CONTENUTO INVARIATO ============ -->
      <div class="content-wrapper">
        <!-- Header pagina -->
        <section class="content-header">
          <h1>Agenda</h1>
          <ol class="breadcrumb">
            <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
            <li class="active">Agenda</li>
          </ol>
        </section>

        <!-- Contenuto -->
        <section class="content">
          <!-- >>> TUTTO IL TUO CONTENUTO ORIGINALE, MENU COMPRESO, RESTA IDENTICO <<< -->
          <div class="row">
            <!-- Menu sinistra -->
            <div class="col-md-3">
              <div class="box box-solid" style="margin-bottom:0!important">
                <div class="box-header with-border">
                  <h3 class="box-title">Menu</h3>
                  <div class="box-tools"><button class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button></div>
                </div>
                <div class="box-body no-padding">
                  <?php
                  $menuTree = $menu ?? ($data['menu'] ?? (session()->get('menuAgenda')['menu'] ?? []));
                  $uri         = service('uri');
                  $currentPath = trim($uri->getPath(), '/');

                  if (!function_exists('menu_norm_icon')) {
                    function menu_norm_icon($icon, $isExternal = false) {
                      $icon = trim((string)$icon);
                      if (strpos($icon, 'fas ') === 0) $icon = 'fa ' . substr($icon, 4);
                      if ($icon !== '') return $icon;
                      return $isExternal ? 'fa fa-external-link' : 'fa fa-circle';
                    }
                    function menu_href_from_node(array $node) {
                      $isExternal = !empty($node['isExternal']);
                      $url   = trim((string)($node['url']   ?? ''));
                      $route = trim((string)($node['route'] ?? ''));
                      if ($isExternal && $url !== '') return $url;
                      if ($url !== '' && preg_match('#^https?://#i', $url)) return $url;
                      if ($route !== '') return base_url($route);
                      return '#';
                    }
                    function menu_path_from_href($href) {
                      $path = parse_url($href, PHP_URL_PATH) ?? '';
                      return trim($path, '/');
                    }
                    function menu_render_nodes(array $nodes, $level, &$uid, $currentPath) {
                      $html = ''; $anyActive = false;
                      foreach ($nodes as $node) {
                        $type  = $node['type'] ?? 'item';
                        $label = htmlspecialchars($node['label'] ?? $node['name'] ?? 'Voce', ENT_QUOTES, 'UTF-8');
                        $icon  = menu_norm_icon($node['icon'] ?? '', !empty($node['isExternal']));
                        if ($type === 'group') {
                          $uid++; $toggleId = 'submenu_'.$uid;
                          $children = is_array($node['children'] ?? null) ? $node['children'] : [];
                          [$childrenHtml, $childActive] = menu_render_nodes($children, $level+1, $uid, $currentPath);
                          $isOpen = $childActive;
                          $collapseCls  = 'collapse submenu' . ($isOpen ? ' in' : '');
                          $ariaExpanded = $isOpen ? 'true' : 'false';
                          $html .= '<li class="has-children">';
                          $html .=   '<a href="#'.$toggleId.'" data-toggle="collapse" aria-expanded="'.$ariaExpanded.'" class="submenu-toggle">';
                          $html .=     '<i class="'.$icon.'"></i> '.$label.'<i class="fa fa-angle-left pull-right"></i>';
                          $html .=   '</a>';
                          $html .=   '<ul class="nav nav-pills nav-stacked '.$collapseCls.'" id="'.$toggleId.'">'.$childrenHtml.'</ul>';
                          $html .= '</li>';
                          if ($childActive) $anyActive = true;
                        } else {
                          $href   = menu_href_from_node($node);
                          $target = !empty($node['target']) ? ' target="'.htmlspecialchars($node['target'], ENT_QUOTES, 'UTF-8').'"' : '';
                          $path   = menu_path_from_href($href);
                          $isActive = $path !== '' && $path === $currentPath;
                          $html .= '<li'.($isActive ? ' class="active"' : '').'>';
                          $html .=   '<a href="'.$href.'"'.$target.'><i class="'.$icon.'"></i> '.$label.'</a>';
                          $html .= '</li>';
                          if ($isActive) $anyActive = true;
                        }
                      }
                      return [$html, $anyActive];
                    }
                  }

                  echo '<ul class="nav nav-pills nav-stacked">';
                  $uid = 0;
                  [$menuHtml] = menu_render_nodes($menuTree, 0, $uid, $currentPath);
                  echo $menuHtml;
                  echo '</ul>';
                  ?>
                </div>
              </div>
            </div>

            <!-- Colonna contenuti (INVARIATA) -->
            <div class="col-md-9">
              <div class="row">
                <!-- Calendario -->
                <div class="col-lg-8 col-md-7 col-sm-12">
                  <div class="box box-primary">
                    <div class="box-header with-border">
                      <h3 class="box-title"><i class="fa fa-calendar"></i> Calendario</h3>
                      <div class="box-tools"><button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button></div>
                    </div>
                    <div class="box-body">
                      <div id="calendar"></div>
                    </div>
                  </div>
                </div>

                <!-- Visite domiciliari -->
                <div class="col-lg-4 col-md-5 col-sm-12">
                  <div class="box box-primary">
                    <div class="box-header with-border">
                      <h3 class="box-title"><i class="fa fa-home"></i> Visite domiciliari</h3>
                      <div class="box-tools"><button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button></div>
                    </div>
                    <div class="box-body no-padding">
                      <div class="table-responsive">
                        <table class="table table-striped table-hover">
                          <thead>
                            <tr>
                              <th style="width:110px;">Data/Ora</th>
                              <th>Paziente</th>
                              <th>Indirizzo</th>
                              <th style="width:90px;">Stato</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php
                            $domiciliari = $domiciliari ?? [
                              ['quando' => '11/08 15:30', 'paziente' => 'Mario Rossi',   'indirizzo' => 'Via Verdi 10', 'stato' => 'Prevista'],
                              ['quando' => '12/08 09:00', 'paziente' => 'Luisa Bianchi', 'indirizzo' => 'P.zza Duomo 2','stato' => 'Confermata'],
                              ['quando' => '12/08 11:15', 'paziente' => 'Giorgio Neri',  'indirizzo' => 'Via Roma 45',  'stato' => 'Da confer.'],
                            ];
                            foreach ($domiciliari as $r):
                              $label = 'label-default';
                              if (stripos($r['stato'], 'confer') !== false) $label = 'label-success';
                              if (stripos($r['stato'], 'prev')   !== false) $label = 'label-info';
                            ?>
                            <tr>
                              <td><?= esc($r['quando']) ?></td>
                              <td><?= esc($r['paziente']) ?></td>
                              <td><?= esc($r['indirizzo']) ?></td>
                              <td><span class="label <?= $label ?>"><?= esc($r['stato']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <div class="box-footer text-right">
                      <a href="<?= site_url('domiciliari'); ?>" class="btn btn-xs btn-primary">
                        <i class="fa fa-list"></i> Tutte le visite
                      </a>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Memo -->
              <div class="row">
                <div class="col-xs-12">
                  <div class="box box-primary">
                    <div class="box-header with-border">
                      <h3 class="box-title"><i class="fa fa-sticky-note"></i> Memo del dottore</h3>
                      <div class="box-tools"><button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button></div>
                    </div>
                    <div class="box-body no-padding">
                      <div class="table-responsive">
                        <table class="table table-striped table-hover">
                          <thead>
                            <tr>
                              <th style="width:110px;">Data</th>
                              <th>Titolo</th>
                              <th>Nota</th>
                              <th style="width:110px;">PrioritÃ </th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php
                            $memo = $memo ?? [
                              ['data' => '11/08/2025', 'titolo' => 'Controllo esami',    'nota' => 'Richiamare paziente per risultati.', 'prio' => 'Alta'],
                              ['data' => '12/08/2025', 'titolo' => 'Ricetta ripetibile', 'nota' => 'Preparare prescrizione farmaco X.',  'prio' => 'Media'],
                              ['data' => '13/08/2025', 'titolo' => 'Referto ecografia',  'nota' => 'Inviare via mail a paziente.',      'prio' => 'Bassa'],
                            ];
                            foreach ($memo as $m):
                              $pLabel = 'label-default';
                              if (strcasecmp($m['prio'], 'Alta') === 0)  $pLabel = 'label-danger';
                              if (strcasecmp($m['prio'], 'Media') === 0) $pLabel = 'label-warning';
                              if (strcasecmp($m['prio'], 'Bassa') === 0) $pLabel = 'label-info';
                            ?>
                            <tr>
                              <td><?= esc($m['data']) ?></td>
                              <td><?= esc($m['titolo']) ?></td>
                              <td><?= esc($m['nota']) ?></td>
                              <td><span class="label <?= $pLabel ?>"><?= esc($m['prio']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <div class="box-footer text-right">
                      <a href="<?= site_url('memo'); ?>" class="btn btn-xs btn-primary">
                        <i class="fa fa-sticky-note"></i> Gestisci memo
                      </a>
                    </div>
                  </div>
                </div>
              </div>

            </div><!-- /.col-md-9 -->
          </div><!-- /.row -->
        </section>
      </div><!-- /.content-wrapper -->
      <!-- ============ /CONTENUTO INVARIATO ============ -->

      <footer class="main-footer">
        <div class="pull-right hidden-xs"><b>Version</b> 2.0</div>
        <strong>Copyright &copy; AmbulatoriCLOUD.</strong> Tutti i diritti riservati.
      </footer>

      <aside class="control-sidebar control-sidebar-dark"></aside>
      <div class="control-sidebar-bg"></div>
    </div><!-- ./wrapper -->

    <!-- JS CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jQuery-slimScroll/1.3.8/jquery.slimscroll.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fastclick/1.0.6/fastclick.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/2.4.18/js/adminlte.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/iCheck/1.0.2/icheck.min.js"></script>

    <!-- FullCalendar deps -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/3.10.2/fullcalendar.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/3.10.2/locale/it.js"></script>

    <script>
      $(function () {
        // iCheck (solo se ci sono checkbox della mailbox)
        $('.mailbox-messages input[type="checkbox"]').iCheck({
          checkboxClass: 'icheckbox_flat-blue',
          radioClass: 'iradio_flat-blue'
        });

        // FullCalendar
        var fcEvents = <?= json_encode($events ?? [
          ['title' => 'Visita ambulatoriale', 'start' => date('Y-m-d', strtotime('+1 day'))],
          ['title' => 'Domiciliare - Rossi',  'start' => date('Y-m-d').'T15:30:00'],
          ['title' => 'Consulenza',           'start' => date('Y-m-d', strtotime('+3 days')).'T10:00:00'],
        ]) ?>;

        $('#calendar').fullCalendar({
          header: { left: 'prev,next today', center: 'title', right: 'month,agendaWeek,agendaDay' },
          locale: 'it',
          editable: false,
          droppable: false,
          eventLimit: true,
          events: fcEvents
        });

        // Aggiusta aria-expanded per submenu
        $('.submenu').on('shown.bs.collapse', function(){
          $('a[href="#'+this.id+'"]').attr('aria-expanded', 'true');
        }).on('hidden.bs.collapse', function(){
          $('a[href="#'+this.id+'"]').attr('aria-expanded', 'false');
        });
      });
    </script>
  </body>
</html>
