<?php
namespace App\Services\Pacs;

interface PacsTransport
{
    /** @return array{status:int,type:string,body:string,warning:bool} */
    public function get(array $profile, string $url, string $accept, int $maxBytes): array;
}
