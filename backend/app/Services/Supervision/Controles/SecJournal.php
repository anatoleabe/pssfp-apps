<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Services\Audit\ChaineJournal;
use App\Services\Supervision\Controle;

/** Chaîne d'empreintes du journal d'activité rompue (D30, SEC_JOURNAL). */
final class SecJournal implements Controle
{
    public function __construct(private readonly ChaineJournal $chaine) {}

    public function code(): string
    {
        return 'SEC_JOURNAL';
    }

    public function libelle(): string
    {
        return 'Chaîne du journal d\'activité rompue';
    }

    public function domaine(): string
    {
        return 'Sécurité';
    }

    public function criticite(): string
    {
        return self::BLOQUANT;
    }

    public function delaiJours(): int
    {
        return 1;
    }

    public function responsable(): string
    {
        return 'admin';
    }

    public function cas(): array
    {
        $this->chaine->sceller();
        $cas = [];
        foreach ($this->chaine->ruptures() as $r) {
            $cle = $r['nature'].':'.($r['id'] ?? 'rang-'.$r['rang']);
            $cas[$cle] = [
                'resume' => 'Journal, '.($r['id'] !== null ? 'entrée n° '.$r['id'] : 'rang '.$r['rang']).' : '.(ChaineJournal::NATURES[$r['nature']] ?? $r['nature']),
                'entree' => $r['id'], 'rang' => $r['rang'],
            ];
        }

        return $cas;
    }
}
