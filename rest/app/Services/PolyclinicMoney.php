<?php
namespace App\Services;

final class PolyclinicMoney
{
    public static function cents($value): int
    {
        $value = str_replace(',', '.', trim((string)$value));
        if (!preg_match('/^(-?)(\d{1,7})(?:\.(\d{1,2}))?$/D', $value, $m)) {
            throw new \InvalidArgumentException('Importo non valido: usare al massimo due decimali, senza separatori delle migliaia.');
        }
        return ($m[1]==='-' ? -1 : 1) * ((int)$m[2]*100 + (int)str_pad($m[3]??'',2,'0'));
    }

    public static function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents),100) . '.' . str_pad((string)(abs($cents)%100),2,'0',STR_PAD_LEFT);
    }

    public static function proportion(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator<=0 || $numerator<0 || $amount<0) throw new \InvalidArgumentException('Ripartizione non valida.');
        return intdiv($amount * $numerator + intdiv($denominator,2),$denominator);
    }

    /** Allocate a partial reversal exactly, with deterministic largest-remainder rounding. */
    public static function allocate(int $amount,array $weights): array
    {
        $sum=array_sum($weights);
        if ($amount<0 || $amount>$sum || array_filter($weights,static fn($v)=>!is_int($v) || $v<0)) throw new \InvalidArgumentException('Ripartizione oltre gli importi disponibili.');
        $out=array_fill_keys(array_keys($weights),0); if (!$sum) return $out;
        $remainders=[]; $used=0;
        foreach ($weights as $key=>$weight) { $out[$key]=intdiv($amount*$weight,$sum); $used+=$out[$key]; $remainders[$key]=($amount*$weight)%$sum; }
        arsort($remainders,SORT_NUMERIC);
        foreach ($remainders as $key=>$unused) { if ($used===$amount) break; ++$out[$key]; ++$used; }
        return $out;
    }
}
