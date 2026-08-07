<?php
$addressAutocompleteCssPath = 'public/css/italian-address-autocomplete.css';
$addressAutocompleteCssAbsolutePath = rtrim(FCPATH, DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $addressAutocompleteCssPath);
$addressAutocompleteCssMtime = is_file($addressAutocompleteCssAbsolutePath)
    ? @filemtime($addressAutocompleteCssAbsolutePath)
    : false;
$addressAutocompleteCssVersion = $addressAutocompleteCssMtime
    ? '?v=' . rawurlencode((string) $addressAutocompleteCssMtime)
    : '';
?>
<link
    href="<?= base_url($addressAutocompleteCssPath) . $addressAutocompleteCssVersion ?>"
    rel="stylesheet"
    type="text/css"
>
