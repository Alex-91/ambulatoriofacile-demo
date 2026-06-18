<?php

namespace App\Models;

use CodeIgniter\Model;

class MenuPrenotazioniModel extends Model
{
    protected $table      = 'dap_menu_prenotazioni';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'codice',
        'titolo',
        'url',
        'icona',
        'ordinamento',
        'attivo',
        'descrizione'
    ];

    public function getMenuAttivo()
    {
        return $this->where('attivo', 1)
                    ->orderBy('ordinamento', 'ASC')
                    ->findAll();
    }
}
