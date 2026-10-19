<?php
namespace App\Services;

/** Shared contract for the tenant form, preview and inbound conversation. */
final class WhatsAppBookingConfig
{
    public static function defaults(): array
    {
        return ['configured' => false, 'keywords' => ['prenota', 'appuntamento', 'visita'],
            'order' => 'day', 'single_visit' => false, 'default_visit_id' => 0, 'visit_ids' => [],
            'doctor_id' => 0, 'location_id' => 0, 'location' => '', 'lead_hours' => 24, 'window_days' => 30,
            'confirmation' => 'auto', 'patient_mode' => 'cf', 'unknown_patient' => 'create',
            'handoff' => true, 'welcome' => 'Buongiorno, sono l’assistente dello studio. Ti aiuto a prenotare un appuntamento.',
            'no_availability' => 'Non ci sono disponibilità per questa scelta. Prova un altro giorno o contatta la segreteria.',
            'handoff_message' => 'La richiesta è stata inoltrata alla segreteria. Ti risponderemo negli orari di apertura.'];
    }

    public static function normalize(array $input): array
    {
        $c = self::defaults();
        foreach (['configured','single_visit','handoff'] as $key) $c[$key] = filter_var($input[$key] ?? $c[$key], FILTER_VALIDATE_BOOLEAN);
        foreach (['order'=>['visit','day'], 'confirmation'=>['auto','manual'], 'patient_mode'=>['name','birth','cf','both'], 'unknown_patient'=>['create','staff']] as $key=>$values) {
            $c[$key] = in_array($input[$key] ?? null, $values, true) ? $input[$key] : $c[$key];
        }
        foreach (['doctor_id','default_visit_id','location_id'] as $key) $c[$key] = max(0, (int)($input[$key] ?? 0));
        $c['visit_ids'] = array_values(array_unique(array_filter(array_map('intval', (array)($input['visit_ids'] ?? [])), static fn($id)=>$id>0)));
        $keywords = $input['keywords'] ?? $c['keywords'];
        if (is_string($keywords)) $keywords = preg_split('/[,;\r\n]+/u', $keywords);
        $c['keywords'] = array_slice(array_values(array_unique(array_filter(array_map(static fn($v)=>mb_strtolower(mb_substr(trim((string)$v),0,50)), (array)$keywords)))),0,20);
        $c['lead_hours'] = max(1,min(168,(int)($input['lead_hours'] ?? 24)));
        $c['window_days'] = max(1,min(90,(int)($input['window_days'] ?? 30)));
        foreach (['location','welcome','no_availability','handoff_message'] as $key) $c[$key] = mb_substr(trim((string)($input[$key] ?? $c[$key])),0,$key==='location'?250:1000);
        return $c;
    }

    public static function validate(array $c, array $catalog): void
    {
        if (!$c['keywords'] || array_intersect($c['keywords'], ['annulla','segreteria','altro','conferma'])) throw new \InvalidArgumentException('Inserisci parole chiave diverse dai comandi annulla, segreteria, altro e conferma.');
        if (!in_array($c['doctor_id'], array_map('intval',array_column($catalog['doctors'],'id')),true)) throw new \InvalidArgumentException('Seleziona un’agenda dello spazio.');
        if (!empty($catalog['locations']) && !in_array($c['location_id'],array_map('intval',array_column($catalog['locations'],'id')),true)) throw new \InvalidArgumentException('Seleziona la sede dell’agenda.');
        $ids = $c['single_visit'] ? [$c['default_visit_id']] : $c['visit_ids'];
        if (!$ids || array_diff($ids,array_map('intval',array_column($catalog['visits'],'id')))) throw new \InvalidArgumentException('Seleziona almeno una prestazione attiva dello spazio.');
        if (!$c['location'] || !$c['welcome'] || !$c['no_availability'] || !$c['handoff_message']) throw new \InvalidArgumentException('Completa sede e messaggi automatici.');
        if ($c['lead_hours'] >= $c['window_days']*24) throw new \InvalidArgumentException('Il periodo prenotabile deve superare il preavviso minimo.');
    }
}
