<?php namespace App\Config;

class MessageRoles
{
    // Mappa questi ID ai tuoi valori reali in dap04_type_users
    // (oppure usa des_tipo con join, se preferisci)
    public const ROLE_PATIENT = 'PAZIENTE';
    public const ROLE_DOCTOR  = 'DOTTORE';
    public const ROLE_SEGR    = 'SEGRETERIA';
    public const ROLE_INFERM  = 'INFERMIERE';
}