<?php
helper('admin_menu');

$result = session()->get('menuData') ?? [];

$menu_items       = $menu_items ?? ($result['result'] ?? []);
$dottori          = $dottori ?? ($result['dottori'] ?? []);
$contDott         = $contDott ?? ($result['cont_dottori'] ?? 0);
$selectedDoctorId = $selectedDoctorId ?? null;
$activeFolder     = strtolower((string)($activeFolder ?? ''));
$counts           = $counts ?? [];
$showDoctorsFilter = $showDoctorsFilter ?? false;

$hasDbMenu = !empty($menu_items) && is_array($menu_items);

$isActiveByLink = static function (string $folder, string $link): bool {
    $l = strtolower(trim($link));
    if ($folder === 'inbox') {
        return str_contains($l, 'messaggi/inbox') || $l === 'posta' || str_contains($l, '/posta');
    }
    if ($folder === 'sent') {
        return str_contains($l, 'messaggi/inviati') || str_contains($l, 'inviata') || str_contains($l, '/inviata');
    }
    if ($folder === 'drafts') {
        return str_contains($l, 'messaggi/bozze') || str_contains($l, 'bozze') || str_contains($l, 'draft');
    }
    if ($folder === 'compose') {
        return str_contains($l, 'messaggi/scrivi') || str_contains($l, 'compose') || str_contains($l, 'scrivi');
    }
    return false;
};

$prettyMailboxTitle = static function (string $title, string $link): string {
    $pretty = admin_menu_pretty_title($title, $link);
    $l = strtolower(trim($link));

    return match (true) {
        str_contains($l, 'messaggi/inbox') || $l === 'posta' || str_contains($l, '/posta') => 'Posta in arrivo',
        str_contains($l, 'messaggi/inviati') || str_contains($l, 'inviata') || str_contains($l, '/inviata') => 'Inviati',
        str_contains($l, 'messaggi/bozze') || str_contains($l, 'bozze') || str_contains($l, 'draft') => 'Bozze',
        str_contains($l, 'messaggi/scrivi') || str_contains($l, 'compose') || str_contains($l, 'scrivi') => 'Scrivi',
        default => $pretty,
    };
};
?>

<!-- Cartelle -->
<div class="box box-solid" style="margin-bottom: 0px !important">
  <div class="box-header with-border">
    <h3 class="box-title">Cartelle</h3>
    <div class='box-tools'>
      <button class='btn btn-box-tool' data-widget='collapse'><i class='fa fa-minus'></i></button>
    </div>
  </div>
  <div class="box-body no-padding">
    <ul class="nav nav-pills nav-stacked">
      <?php if ($hasDbMenu): ?>
        <?php foreach ($menu_items as $menu): ?>
          <?php
            $link = trim((string)($menu['link'] ?? ''));
            if ($link === '') {
                continue;
            }

            $href = preg_match('#^https?://#i', $link) ? $link : site_url(ltrim($link, '/'));
            $icon = (string)($menu['icon'] ?? $menu['class_icon'] ?? 'fa-circle-o');
            $titolo = $prettyMailboxTitle((string)($menu['titolo_menu'] ?? $menu['titolo'] ?? ''), $link);
            $badge = (int)($menu['conteggio'] ?? 0);

            $liClass = $isActiveByLink($activeFolder, $link)
                ? 'active'
                : ((string)($menu['class'] ?? '') ?: '');
          ?>
          <li class="<?= esc($liClass) ?>">
            <a href="<?= esc($href) ?>">
              <i class="fa <?= esc($icon) ?>"></i> <?= esc($titolo) ?>
              <?php if ($badge > 0): ?>
                <span class="label label-primary pull-right"><?= $badge ?></span>
              <?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      <?php else: ?>
        <li class="<?= $activeFolder === 'inbox' ? 'active' : '' ?>">
          <a href="<?= site_url('messaggi/inbox') ?>">
            <i class="fa fa-inbox"></i> Posta in arrivo
            <?php if (!empty($counts['inbox'] ?? null)): ?>
              <span class="label label-primary pull-right"><?= (int)$counts['inbox'] ?></span>
            <?php endif; ?>
          </a>
        </li>
        <li class="<?= $activeFolder === 'sent' ? 'active' : '' ?>">
          <a href="<?= site_url('messaggi/inviati') ?>">
            <i class="fa fa-paper-plane-o"></i> Inviati
          </a>
        </li>
        <li class="<?= $activeFolder === 'drafts' ? 'active' : '' ?>">
          <a href="<?= site_url('messaggi/bozze') ?>">
            <i class="fa fa-file-text-o"></i> Bozze
          </a>
        </li>
        <li class="<?= $activeFolder === 'compose' ? 'active' : '' ?>">
          <a href="<?= site_url('messaggi/scrivi') ?>">
            <i class="fa fa-pencil"></i> Scrivi
          </a>
        </li>
      <?php endif; ?>
    </ul>
  </div>
</div>

<?php if (!empty($dottori) && is_array($dottori) && ($hasDbMenu || $showDoctorsFilter)): ?>
  <div class="box box-solid">
    <div class="box-header with-border">
      <h3 class="box-title">Dottori<?= $contDott ? ' (' . (int)$contDott . ')' : '' ?></h3>
      <div class='box-tools'>
        <button class='btn btn-box-tool' data-widget='collapse'><i class='fa fa-minus'></i></button>
      </div>
    </div>
    <div class="box-body no-padding">
      <ul class="nav nav-pills nav-stacked">
        <?php foreach ($dottori as $id => $dottore): ?>
          <?php if (!empty($dottore)): ?>
            <?php $isActiveDoc = isset($selectedDoctorId) && (int)$selectedDoctorId === (int)$id; ?>
            <li class="<?= $isActiveDoc ? 'active' : '' ?>">
              <a href="<?= site_url('posta') . '?id_dottore=' . urlencode((string)$id) ?>">
                <i class="fa fa-user-md"></i> <?= esc($dottore['titolo'] ?? ('Dottore #' . $id)) ?>
                <?php if (!empty($dottore['conteggio'])): ?>
                  <span class="label label-primary pull-right"><?= (int)$dottore['conteggio'] ?></span>
                <?php endif; ?>
              </a>
            </li>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!empty($result['resultLogout'])): ?>
          <li class="logout">
            <a href="<?= site_url('logout') ?>">
              <i class="fa fa-sign-out"></i> Logout
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>
