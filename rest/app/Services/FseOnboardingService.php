<?php
namespace App\Services;

/** Fixed-language checklist; never contacts external systems or declares a structure accredited. */
final class FseOnboardingService
{
    public function check(array $profile): array
    {
        $groups = [
            'Struttura e sede' => ['region_code'=>'Regione', 'organization_id'=>'Identificativo organizzazione', 'organization_name'=>'Organizzazione', 'site_code'=>'Sede / impianto', 'facility_name'=>'Nome struttura', 'facility_code'=>'Codice struttura', 'facility_oid'=>'OID struttura', 'care_regime'=>'Regime'],
            'Identificativi e percorso' => ['document_oid_root'=>'OID documenti', 'submission_oid_root'=>'OID sottomissioni', 'repository_id'=>'Repository', 'locality'=>'Locality', 'organizational_setting'=>'Assetto organizzativo'],
            'Medico e prodotto' => ['author_cf'=>'CF medico', 'author_first_name'=>'Nome medico', 'author_last_name'=>'Cognome medico', 'app_vendor'=>'Vendor', 'app_id'=>'Applicazione', 'app_version'=>'Versione'],
            'Certificati di collegamento' => ['auth_certificate_path'=>'Certificato mTLS', 'auth_private_key_path'=>'Chiave mTLS', 'signature_certificate_path'=>'Certificato JWT', 'signature_private_key_path'=>'Chiave JWT'],
        ];
        if (($profile['access_mode'] ?? '') === 'toscana_privati') $groups['Identificativi e percorso']['jwt_audience'] = 'Audience confermata da CART';
        $checks = [];
        foreach ($groups as $title=>$fields) {
            $missing = [];
            foreach ($fields as $key=>$label) if (trim((string) ($profile[$key] ?? '')) === '') $missing[] = $label;
            $checks[] = ['title'=>$title, 'status'=>$missing ? 'missing' : 'entered', 'missing'=>$missing];
        }
        return ['groups'=>$checks, 'operational_ready'=>false, 'message'=>'Dati inseriti non significa dati verificati: servono controlli tecnici, firma effettiva e abilitazioni ufficiali.'];
    }
}
