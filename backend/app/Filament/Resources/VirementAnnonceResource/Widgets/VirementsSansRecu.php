<?php

declare(strict_types=1);

namespace App\Filament\Resources\VirementAnnonceResource\Widgets;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource\AuditeurActions;
use App\Filament\Resources\AuditeurResource\RecuActions;
use App\Filament\Resources\VirementAnnonceResource;
use App\Models\Versement;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\VirementsAnnonces;
use App\Support\BelongsToModule;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Virements enregistrés avant le circuit en deux temps, sans reçu (spec virements-bordereaux §2.7). Leur reçu s'émet
 * à la date enregistrée ; une date fausse se corrige par la correction à double regard (ADR-0015).
 */
#[BelongsToModule('scolarite')]
class VirementsSansRecu extends TableWidget
{
    use ChecksModuleAccess;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return self::moduleAllows() && VirementAnnonceResource::canViewAny() && VirementsAnnonces::enregistresSansRecu()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Virements enregistrés sans reçu')
            ->description('Saisis avant le circuit en deux temps : le reçu s\'émet à la date enregistrée. Si cette date est fausse, demandez une correction depuis la fiche de l\'auditeur.')
            ->query(VirementsAnnonces::enregistresSansRecu()->with(['auditeur.candidature:id,nom,prenom,matricule', 'recus']))
            ->defaultSort('date_versement')
            ->columns([
                Tables\Columns\TextColumn::make('auditeur')->label('Auditeur')
                    ->getStateUsing(fn (Versement $v): string => trim(mb_strtoupper((string) $v->auditeur?->candidature?->nom).' '.$v->auditeur?->candidature?->prenom))
                    ->description(fn (Versement $v): ?string => $v->auditeur?->candidature?->matricule),
                Tables\Columns\TextColumn::make('montant')->label('Montant')->formatStateUsing(fn (int $state): string => LibelleSituation::fcfa($state))->alignEnd(),
                Tables\Columns\TextColumn::make('reference')->label('Référence')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('date_versement')->label('Date enregistrée')->date('d/m/Y'),
            ])
            ->actions([AuditeurActions::voirJustificatif(), RecuActions::emettre()])
            ->paginated([10, 25]);
    }
}
