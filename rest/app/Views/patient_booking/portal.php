<?php
helper(['portal','form']);
$menuDataAdmin=session()->get('menuDataAdmin');
$headerMenuItems=session()->get('header_menu_items');
$menuItems=is_array($menuDataAdmin['result']??null)?$menuDataAdmin['result']:(is_array($headerMenuItems)?$headerMenuItems:[]);
$assetUrl=static function(string $path): string {
    return base_url($path).'?v='.substr(hash_file('sha256',dirname(__DIR__,4).'/'.$path),0,16);
};
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prenotazione online | AmbulatorioFacile</title>
    <link rel="stylesheet" href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('public/dist/css/AdminLTE.css') ?>">
    <link rel="stylesheet" href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('public/assets/patient-booking/design/font/inter.css') ?>">
    <link rel="stylesheet" href="<?= $assetUrl('public/assets/patient-booking/portal.css') ?>">
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
    <?= view('partials/header',['menu_items'=>$menuItems,'portal_console_header'=>false]) ?>
    <div class="content-wrapper">
        <div class="content booking-portal-shell">
            <aside class="booking-portal-navigation" aria-label="Menu dello spazio">
                <?= view('partials/sidebar_admin',['menu_items'=>$menuItems]) ?>
            </aside>
            <div id="patient-booking-admin">
                <template id="patient-booking-content">
                    <style><?= file_get_contents(dirname(__DIR__,4).'/public/assets/patient-booking/portal-content.css') ?></style>
                    <?= $bookingContent ?>
                </template>
            </div>
        </div>
    </div>
</div>
<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>
<script>window.bookingAdmin=<?= json_encode($bookingBoot,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?>;</script>
<script src="<?= $assetUrl('public/assets/patient-booking/original-admin.js') ?>"></script>
</body>
</html>
