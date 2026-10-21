<?php
// CLI-only rendering fixture: no framework, environment files or databases.
if (PHP_SAPI !== 'cli') { exit(1); }
function base_url($path = '') { return '/' . $path; }
function site_url($path = '') { return '/' . $path; }
function view($name, $data = []) { return ''; }
$menu_items = [];
require dirname(__DIR__) . '/app/Views/admin/billing/sdi_channels.php';
