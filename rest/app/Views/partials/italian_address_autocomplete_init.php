<?php
$addressAutocompleteGroups = is_array($groups ?? null) ? array_values($groups) : [];
$addressAutocompleteVersion = static function (string $relativePath): string {
    $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
    $absolutePath = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalizedPath;
    $mtime = is_file($absolutePath) ? @filemtime($absolutePath) : false;

    return $mtime ? '?v=' . rawurlencode((string) $mtime) : '';
};
$addressAutocompleteGroupsJson = json_encode(
    $addressAutocompleteGroups,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($addressAutocompleteGroupsJson) || $addressAutocompleteGroupsJson === '') {
    $addressAutocompleteGroupsJson = '[]';
}
?>
<?php if ($addressAutocompleteGroups !== []): ?>
<script src="<?= base_url('public/js/italian-address-autocomplete.js') . $addressAutocompleteVersion('public/js/italian-address-autocomplete.js') ?>"></script>
<script>
(function ($) {
    'use strict';

    if (!$) {
        return;
    }

    $(function () {
        if (!window.ItalianAddressAutocomplete) {
            return;
        }

        window.ItalianAddressAutocomplete.init({
            dataUrl: <?= json_encode(base_url('public/data/italian-addresses.json') . $addressAutocompleteVersion('public/data/italian-addresses.json')) ?>,
            groups: <?= $addressAutocompleteGroupsJson ?>
        });
    });
})(window.jQuery);
</script>
<?php endif; ?>
