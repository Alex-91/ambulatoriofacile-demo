<?php
helper('admin_menu');

// fallback: se $menu_items e` vuoto o non definito, prendo dalla sessione
if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = session()->get('header_menu_items') ?? [];
}

$currentPath = trim(service('uri')->getPath(), '/');
$otpStatsActive = str_starts_with($currentPath, 'admin/otp-statistiche') ? 'active' : '';
$whatsappRemindersActive = str_starts_with($currentPath, 'admin/whatsapp-reminders') ? 'active' : '';
$moduleVisibilityActive = str_starts_with($currentPath, 'admin/personale/visibilita-moduli') ? 'active' : '';
$tenantSpacesActive = str_starts_with($currentPath, 'admin/piattaforma/spazi-clienti') ? 'active' : '';
$dap14Active = str_starts_with($currentPath, 'admin/personale/dap14') ? 'active' : '';
$dap15Active = str_starts_with($currentPath, 'admin/personale/dap15') ? 'active' : '';
?>
              <!-- Cartelle -->
              <div class="box box-solid" style="margin-bottom: 0px !important">
                <div class="box-header with-border">
                  <h3 class="box-title">Menu</h3>
                  <div class='box-tools'>
                    <button class='btn btn-box-tool' data-widget='collapse'><i class='fa fa-minus'></i></button>
                  </div>
                </div>
                <div class="box-body no-padding">
                  <ul class="nav nav-pills nav-stacked">
                    <?php if (!empty($menu_items)): ?>
                      <?php foreach ($menu_items as $menu): ?>
                        <?php
                          $menuLink = trim((string)($menu['link'] ?? ''));
                          $menuTitle = strtolower(trim((string)($menu['titolo_menu'] ?? '')));
                          $normalizedMenuLink = strtolower(str_replace('\\', '/', $menuLink));
                          $isLogoutEntry = $normalizedMenuLink === 'logout'
                              || $normalizedMenuLink === 'admin/personale/logout'
                              || $menuTitle === 'logout';
                          if ($isLogoutEntry) {
                              continue;
                          }

                          $menuLabel = admin_menu_pretty_title((string)($menu['titolo_menu'] ?? ''), $menuLink);
                          $icon = admin_menu_resolve_icon(
                              (string)($menu['icon'] ?? $menu['class_icon'] ?? ''),
                              $menuLabel,
                              $menuLink
                          );
                        ?>
                        <li class="<?= esc((string)($menu['class'] ?? '')) ?>">
                          <a href="<?= base_url('admin/personale/' . $menuLink) ?>">
                            <i class="fa <?= esc($icon) ?>"></i>
                            <?= esc($menuLabel) ?>
                            <?php if (!empty($menu['conteggio'])): ?>
                              <span class="label label-primary pull-right"><?= esc($menu['conteggio']) ?></span>
                            <?php endif; ?>
                          </a>
                        </li>
                      <?php endforeach; ?>
                    <?php endif; ?>
                    <li class="<?= esc($otpStatsActive) ?>">
                      <a href="<?= site_url('admin/otp-statistiche') ?>">
                        <i class="fa fa-line-chart"></i>
                        Statistiche OTP
                      </a>
                    </li>
                    <li class="<?= esc($whatsappRemindersActive) ?>">
                      <a href="<?= site_url('admin/whatsapp-reminders') ?>">
                        <i class="fa fa-whatsapp"></i>
                        Stato reminder WhatsApp
                      </a>
                    </li>
                    <li class="<?= esc($moduleVisibilityActive) ?>">
                      <a href="<?= site_url('admin/personale/visibilita-moduli') ?>">
                        <i class="fa fa-toggle-on"></i>
                        Visibilita moduli
                      </a>
                    </li>
                    <li class="<?= esc($tenantSpacesActive) ?>">
                      <a href="<?= site_url('admin/piattaforma/spazi-clienti') ?>">
                        <i class="fa fa-sitemap"></i>
                        Spazi cliente
                      </a>
                    </li>
                    <li class="<?= esc($dap14Active) ?>">
                      <a href="<?= site_url('admin/personale/dap14') ?>">
                        <i class="fa fa-users"></i>
                        Segretarie e medici
                      </a>
                    </li>
                    <li class="<?= esc($dap15Active) ?>">
                      <a href="<?= site_url('admin/personale/dap15') ?>">
                        <i class="fa fa-heartbeat"></i>
                        Infermieri e medici
                      </a>
                    </li>
                    <li>
                      <a href="<?= site_url('admin/personale/logout') ?>">
                        <i class="fa fa-sign-out"></i>
                        Logout
                      </a>
                    </li>
                  </ul>
                </div>
              </div><!-- /.box -->
