<?php $stampWording = \App\Services\BillingStampDuty::wording($document ?? []); ?>
<?php if ($stampWording !== ''): ?>
<p class="billing-stamp-notice" style="font-size:9pt;line-height:1.5;overflow-wrap:break-word;page-break-inside:avoid;"><?= esc($stampWording) ?></p>
<?php endif; ?>
