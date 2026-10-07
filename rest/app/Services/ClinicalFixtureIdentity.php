<?php
namespace App\Services;

/** Display-only identity for the explicitly generated presentation dataset. */
final class ClinicalFixtureIdentity
{
    public static function fiscalCode(array $patient): string
    {
        $nameCode=static function(string $name,bool $first): string {
            $name=preg_replace('/[^A-Z]/','',strtoupper($name));
            $consonants=preg_replace('/[AEIOU]/','',$name);
            if($first && strlen($consonants)>=4) return $consonants[0].$consonants[2].$consonants[3];
            return substr($consonants.preg_replace('/[^AEIOU]/','',$name).'XXX',0,3);
        };
        $birth=\DateTimeImmutable::createFromFormat('!Y-m-d',$patient['patient_birth_date'] ?? '') ?: new \DateTimeImmutable('1987-04-12');
        $stem=$nameCode($patient['patient_last_name'] ?? 'Rossi',false).$nameCode($patient['patient_first_name'] ?? 'Giulia',true)
            .$birth->format('y').'ABCDEHLMPRST'[(int)$birth->format('n')-1].$birth->format('d').'H501';
        $odd=[1,0,5,7,9,13,15,17,19,21,2,4,18,20,11,3,6,8,12,14,16,10,22,25,24,23]; $sum=0;
        for($i=0;$i<15;$i++){ $n=ctype_digit($stem[$i])?(int)$stem[$i]:ord($stem[$i])-65; $sum+=($i%2===0)?$odd[$n]:$n; }
        return $stem.chr(65+$sum%26);
    }
}
