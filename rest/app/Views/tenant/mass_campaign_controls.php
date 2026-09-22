<?php
$campaignStatus = (string) ($campaign['status'] ?? '');
$isPaused = $campaignStatus === 'paused';
?>
<?php if (in_array($campaignStatus, ['queued', 'running', 'paused'], true)): ?>
<form method="post" action="<?= esc(portal_tenant_space_url('invii-massivi/' . ($isPaused ? 'resume' : 'pause')), 'attr') ?>" style="display:inline-block;margin:4px 0;">
  <?= csrf_field() ?>
  <input type="hidden" name="campaign_id" value="<?= (int) $campaign['id_mass_campaign'] ?>">
  <button type="submit" class="btn btn-sm <?= $isPaused ? 'btn-success' : 'btn-default' ?>" aria-label="<?= $isPaused ? 'Riprendi' : 'Metti in pausa' ?> campagna #<?= (int) $campaign['id_mass_campaign'] ?>">
    <i class="fa <?= $isPaused ? 'fa-play' : 'fa-pause' ?>" aria-hidden="true"></i> <?= $isPaused ? 'Riprendi' : 'Pausa' ?>
  </button>
</form>
<?php endif; ?>
