<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliberationResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Models\Deliberation;
use App\Models\InscriptionPedagogique;
use App\Models\MesureJury;
use App\Models\User;
use App\Services\Lmd\CalculScenario;
use App\Services\Lmd\Deliberations;
use App\Services\Lmd\PermissionsLmd;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Mesures du jury, par scénario (D37). Ajout et retrait passent par le service, délibération non arrêtée. */
#[BelongsToModule('lmd')]
class MesuresRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'mesures';

    protected static ?string $title = 'Mesures du jury';

    protected static ?string $icon = 'heroicon-o-scale';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows() && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var Deliberation $d */
        $d = $this->getOwnerRecord();
        $modifiable = fn (): bool => $d->refresh()->estModifiable() && (bool) auth()->user()?->can(PermissionsLmd::DELIBERATION_PREPARER);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('scenario'))
            ->columns([
                Tables\Columns\TextColumn::make('scenario.libelle')->label('Scénario'),
                Tables\Columns\TextColumn::make('type')->label('Mesure')->badge()->formatStateUsing(fn (string $state): string => MesureJury::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('en_clair')->label('Détail')->wrap()->getStateUsing(fn (MesureJury $r): string => $r->enClair()),
                Tables\Columns\TextColumn::make('beneficiaires')->label('UE concernées')->alignEnd()->placeholder('à calculer'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('ajouter')->label('Ajouter une mesure')->icon('heroicon-o-plus')
                    ->visible($modifiable)
                    ->form(fn (): array => MessagesValidation::appliquer([
                        Forms\Components\Select::make('scenario_id')->label('Scénario')->required()->native(false)
                            ->options($d->scenarios()->pluck('libelle', 'id')->all()),
                        Forms\Components\Select::make('type')->label('Mesure')->required()->native(false)->live()->options(MesureJury::TYPES),
                        Forms\Components\TextInput::make('plage_min')->label('Moyennes d\'UE de')->numeric()->minValue(0)->maxValue(20)
                            ->visible(fn (Forms\Get $get): bool => $get('type') === MesureJury::RELEVEMENT)->required(fn (Forms\Get $get): bool => $get('type') === MesureJury::RELEVEMENT),
                        Forms\Components\TextInput::make('plage_max')->label('à')->numeric()->minValue(0)->maxValue(20)
                            ->visible(fn (Forms\Get $get): bool => $get('type') === MesureJury::RELEVEMENT)->required(fn (Forms\Get $get): bool => $get('type') === MesureJury::RELEVEMENT),
                        Forms\Components\TextInput::make('cible')->label('Portées à')->numeric()->minValue(0)->maxValue(20)->default(12)
                            ->visible(fn (Forms\Get $get): bool => $get('type') === MesureJury::RELEVEMENT),
                        Forms\Components\TextInput::make('credits_min')->label('Auditeurs ayant au moins (crédits)')->integer()->minValue(0)->maxValue(60)
                            ->visible(fn (Forms\Get $get): bool => $get('type') === MesureJury::REPECHAGE)->required(fn (Forms\Get $get): bool => $get('type') === MesureJury::REPECHAGE),
                        Forms\Components\TextInput::make('ecart_max')->label('UE à moins de (points du seuil)')->numeric()->minValue(0.01)->maxValue(5)
                            ->visible(fn (Forms\Get $get): bool => $get('type') === MesureJury::REPECHAGE)->required(fn (Forms\Get $get): bool => $get('type') === MesureJury::REPECHAGE),
                        Forms\Components\TextInput::make('ue_max')->label('UE au plus par auditeur')->integer()->minValue(1)->maxValue(10)->default(1)
                            ->visible(fn (Forms\Get $get): bool => $get('type') === MesureJury::REPECHAGE),
                        Forms\Components\Select::make('inscription_annuelle_id')->label('Auditeur')->searchable()->live()
                            ->visible(fn (Forms\Get $get): bool => $get('type') === MesureJury::CAS_PARTICULIER)->required(fn (Forms\Get $get): bool => $get('type') === MesureJury::CAS_PARTICULIER)
                            ->options(fn (): array => CalculScenario::population($d)->with('auditeur.candidature')->get()
                                ->mapWithKeys(fn ($i): array => [$i->id => $i->auditeur?->candidature?->matricule.' — '.mb_strtoupper((string) $i->auditeur?->candidature?->nom).' '.$i->auditeur?->candidature?->prenom])->all()),
                        Forms\Components\Select::make('maquette_ue_id')->label('UE')->native(false)
                            ->visible(fn (Forms\Get $get): bool => $get('type') === MesureJury::CAS_PARTICULIER)->required(fn (Forms\Get $get): bool => $get('type') === MesureJury::CAS_PARTICULIER)
                            ->options(fn (Forms\Get $get): array => InscriptionPedagogique::query()->where('inscription_annuelle_id', (int) $get('inscription_annuelle_id'))
                                ->with('maquetteUe.ue')->get()->mapWithKeys(fn ($ip): array => [$ip->maquette_ue_id => $ip->maquetteUe?->ue?->code.' — '.$ip->maquetteUe?->ue?->intitule])->all()),
                        Forms\Components\TextInput::make('note')->label('Note retenue (vide : le seuil)')->numeric()->minValue(0)->maxValue(20)
                            ->visible(fn (Forms\Get $get): bool => $get('type') === MesureJury::CAS_PARTICULIER),
                        Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500),
                    ]))
                    ->action(function (array $data) use ($d): void {
                        $scenario = $d->scenarios()->findOrFail((int) $data['scenario_id']);
                        try {
                            app(Deliberations::class)->ajouterMesure($scenario, (string) $data['type'],
                                array_intersect_key($data, array_flip(['plage_min', 'plage_max', 'cible', 'credits_min', 'ecart_max', 'ue_max', 'inscription_annuelle_id', 'maquette_ue_id', 'note'])),
                                (string) $data['motif'], $this->utilisateur());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Mesure refusée')->body($e->getMessage())->persistent()->send();

                            return;
                        }
                        Notification::make()->success()->title('Mesure ajoutée : recalculez le scénario.')->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('retirer')->label('Retirer')->icon('heroicon-o-trash')->color('danger')
                    ->visible($modifiable)->requiresConfirmation()
                    ->action(function (MesureJury $record): void {
                        try {
                            app(Deliberations::class)->retirerMesure($record, $this->utilisateur());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Retrait refusé')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title('Mesure retirée : recalculez le scénario.')->send();
                    }),
            ])
            ->paginated(false);
    }

    private function utilisateur(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
