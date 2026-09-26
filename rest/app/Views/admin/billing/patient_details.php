<?php
$patientDetails = is_array($template['patient_details'] ?? null) ? $template['patient_details'] : [];
$patientLabels = ['address' => 'Indirizzo', 'city' => 'Comune', 'email' => 'Email', 'phone' => 'Telefono', 'mobile' => 'Cellulare'];
foreach ($patientLabels as $key => $label):
    $value = trim((string) ($patientDetails['patient_' . $key] ?? $document['patient_' . $key] ?? ''));
    if (empty($fields['show_patient_' . $key]) || $value === '') {
        continue;
    }
?>
  <div class="label" style="margin-top:8px;"><?= esc($label) ?></div>
  <div class="value" style="overflow-wrap:break-word;"><?= esc($value) ?></div>
<?php endforeach; ?>
