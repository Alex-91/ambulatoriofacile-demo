<?php $onboarding = $settings['onboarding'] ?? []; ?>
<section aria-label="Preparazione FSE per sede"><h3>Preparazione guidata — sedi e regimi</h3>
<p><a class="btn btn-default" href="<?= portal_tenant_space_url('fse2') ?>?new=1">Nuovo profilo sede / regime</a></p>
<ul><?php foreach (($settings['profiles'] ?? []) as $item): ?><li><a href="<?= portal_tenant_space_url('fse2') ?>?profile=<?= (int)$item['id_fse_profile'] ?>"><?= esc($item['profile_name']) ?></a> — <?= esc(($item['site_code'] ?: 'sede da definire').' / '.($item['care_regime'] ?: 'regime da definire')) ?><?= !empty($item['is_default'])?' (predefinito)':'' ?></li><?php endforeach ?></ul>
<input type="hidden" name="id_fse_profile" value="<?= (int)($profile['id_fse_profile']??0) ?>">
<input type="hidden" name="profile_edit_token" value="<?= esc((string)($profile['profile_edit_token']??'')) ?>">
<div class="row"><div class="col-sm-6 form-group"><label for="fse-site">Codice sede / impianto</label><input id="fse-site" class="form-control" name="site_code" maxlength="80" value="<?= esc((string)(old('site_code')??($profile['site_code']??''))) ?>"></div>
<div class="col-sm-6 form-group"><label for="fse-regime">Regime del profilo</label><select id="fse-regime" class="form-control" name="care_regime"><option value="">Da confermare</option><?php foreach(['NOSSN'=>'Privato / non SSN','SSN'=>'SSN','SSR'=>'SSR'] as $code=>$label): ?><option value="<?= $code ?>" <?= (old('care_regime')??($profile['care_regime']??''))===$code?'selected':'' ?>><?= $label ?></option><?php endforeach ?></select></div></div>
<div class="form-group"><label for="fse-audience">Audience JWT confermata dall’ente (necessaria per Toscana)</label><input id="fse-audience" class="form-control" name="jwt_audience" maxlength="500" value="<?= esc((string)(old('jwt_audience')??($profile['jwt_audience']??''))) ?>"></div>
<label><input type="checkbox" name="make_default" value="1" <?= !empty($profile['is_default'])?'checked':'' ?>> Predefinito per i nuovi referti; non modifica quelli esistenti</label>
<h4>Dati salvati: cosa manca</h4><p>La checklist si aggiorna dopo il salvataggio. “Inseriti” non equivale a “verificati”.</p>
<ul><?php foreach (($onboarding['groups'] ?? []) as $group): ?><li><strong><?= esc($group['title']) ?>:</strong> <?= $group['missing'] ? 'mancano '.esc(implode(', ', $group['missing'])) : 'dati inseriti — da verificare' ?></li><?php endforeach ?></ul>
<p class="alert alert-info"><?= esc((string)($onboarding['message']??'')) ?></p></section>
