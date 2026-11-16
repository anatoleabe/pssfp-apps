<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArchiveAcmaResource;

use App\Models\AcmaDossier;
use App\Models\AcmaInscription;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Parcours;
use App\Models\Pays;
use App\Models\ReinscriptionAcma;
use App\Services\Acma\CoordonneesAcma;
use App\Services\Acma\ReinscriptionAncien;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\Scolarite\AnneeEnCours;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * Action « Réinscrire cet ancien auditeur » de la fiche d'archive (spec de la réinscription des anciens, §7) :
 * formulaire prérempli depuis l'archive, tout modifiable sauf le matricule. Unitaire (Q5, 16/11/2026).
 */
final class ReinscriptionAncienActions
{
    public static function reinscrire(): Action
    {
        return Action::make('reinscrire')
            ->label('Réinscrire cet ancien auditeur')
            ->icon('heroicon-o-user-plus')
            ->visible(fn (AcmaDossier $record): bool => self::possible($record))
            ->modalHeading(fn (AcmaDossier $record): string => 'Réinscrire '.$record->matricule.' en '.AnneeEnCours::annee())
            ->modalDescription('Crée l\'auditeur sous son matricule ACMA et l\'inscrit dans l\'année en cours. Aucun message ne part maintenant : ses accès s\'ouvriront par le paiement confirmé ou un déblocage, et le lien de sa lettre d\'accès partira alors. Vérifiez chaque coordonnée : celles d\'ACMA peuvent être anciennes.')
            ->modalWidth('4xl')
            ->fillForm(fn (AcmaDossier $record): array => self::valeurs($record))
            ->form(fn (AcmaDossier $record): array => self::formulaire($record))
            ->action(function (AcmaDossier $record, array $data, Action $action): void {
                $candidature = null;
                ArchiveAcmaActions::executer($action, function () use ($record, $data, &$candidature): void {
                    $candidature = app(ReinscriptionAncien::class)->reinscrire($record, $data, auth()->user());
                }, 'Ancien auditeur réinscrit');
                if ($candidature instanceof Candidature) {
                    $ecampus = app(ReinscriptionAncien::class)->ouvrirEcampus($candidature, auth()->user());
                    Notification::make()->info()->title('Compte eCampus')
                        ->body(match ($ecampus) {
                            ComptesEcampus::CREE => 'Créé dans la cohorte de l\'année d\'accueil.',
                            ComptesEcampus::EXISTANT => 'Un compte existait déjà sous ce matricule : il est gardé tel quel, avec le mot de passe de l\'auditeur.',
                            null => 'À créer depuis la fiche de l\'auditeur (bouton eCampus).',
                            default => 'Non créé : réessayez depuis la fiche de l\'auditeur.',
                        })->send();
                }
            });
    }

    private static function possible(AcmaDossier $d): bool
    {
        return (bool) auth()->user()?->can(ReinscriptionAncien::PERMISSION)
            && AnneeEnCours::annee() !== null
            && $d->retire_de_la_source_at === null
            && in_array($d->statut_rapprochement, ['sans_auditeur'], true)
            && ! ReinscriptionAcma::query()->where('dossier_acma_id', $d->id)->exists();
    }

    /** @return array<string, mixed> */
    private static function valeurs(AcmaDossier $d): array
    {
        $mots = preg_split('/\s+/', (string) $d->nom_complet, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $coordonnees = CoordonneesAcma::propositions($d);
        $m2 = $d->inscriptions()->whereNull('retire_de_la_source_at')->where('niveau', 'M2')->orderByDesc('annee')->first();

        return [
            'nom' => $mots[0] ?? null,
            'prenom' => implode(' ', array_slice($mots, 1)) ?: null,
            'date_naissance' => ReinscriptionAncien::dateNaissanceArchive($d),
            'genre' => $d->sexe,
            'lieu_naissance' => $d->lieu_naissance,
            'nationalite' => 'CM',
            'phone_e164' => $coordonnees['telephone'],
            'email' => $coordonnees['email'],
            'adresse' => $d->adresse_source,
            'campagne_id' => self::promotionOrigine($d)?->id,
            'niveau' => 'M1',
            'parcours' => Parcours::TRONC_COMMUN,
            'parcours_m2' => $m2?->parcours,
        ];
    }

    /** Promotion d'origine : année de la première inscription M1, sinon l'année qui précède la première inscription M2. */
    public static function promotionOrigine(AcmaDossier $d): ?CampagneCandidature
    {
        $inscriptions = $d->inscriptions()->whereNull('retire_de_la_source_at')->orderBy('annee')->get();
        $m1 = $inscriptions->firstWhere('niveau', 'M1');
        $entree = $m1 !== null ? $m1->annee : (($m2 = $inscriptions->firstWhere('niveau', 'M2')) instanceof AcmaInscription ? AnneeEnCours::suivante($m2->annee, -1) : null);

        return $entree === null ? null : AnneeEnCours::promotionDeLAnnee($entree);
    }

    /** @return list<Forms\Components\Component> */
    private static function formulaire(AcmaDossier $d): array
    {
        $promotions = fn (): array => CampagneCandidature::query()->whereNotNull('promotion_numero')->orderByDesc('promotion_numero')->get()
            ->mapWithKeys(fn (CampagneCandidature $c): array => [$c->id => $c->promotion_numero.'e promotion ('.$c->anneeAcademique().')'])->all();
        $optionsM2 = fn (): array => Parcours::query()->where('niveau', 'M2')->where('actif', true)->orderBy('ordre')->pluck('libelle', 'code')->all();

        return [
            Forms\Components\Section::make('Identité')->columns(3)->schema([
                Forms\Components\Placeholder::make('matricule')->label('Matricule (ACMA, conservé)')->content($d->matricule),
                Forms\Components\TextInput::make('nom')->label('Nom')->required()->maxLength(100),
                Forms\Components\TextInput::make('prenom')->label('Prénoms')->required()->maxLength(100)
                    ->helperText('ACMA n\'a qu\'un champ « NOM PRÉNOMS » : corrigez d\'après la pièce.'),
                Forms\Components\TextInput::make('epouse')->label('Nom d\'épouse')->maxLength(100),
                Forms\Components\DatePicker::make('date_naissance')->label('Date de naissance')->required()->native(false)->displayFormat('d/m/Y'),
                Forms\Components\TextInput::make('date_naissance_piece')->label('Pièce de la date (si elle diffère de l\'archive ou y manque)')->maxLength(100),
                Forms\Components\Select::make('genre')->label('Genre')->options(['M' => 'Masculin', 'F' => 'Féminin'])->required(),
                Forms\Components\Select::make('civilite')->label('Civilité')->options(['M.' => 'M.', 'Mme' => 'Mme', 'Mlle' => 'Mlle']),
                Forms\Components\TextInput::make('lieu_naissance')->label('Lieu de naissance')->maxLength(100),
                Forms\Components\Select::make('nationalite')->label('Nationalité')->options(fn () => Pays::orderBy('nom')->pluck('nom', 'code_iso'))->searchable()->required(),
            ]),
            Forms\Components\Section::make('Coordonnées (vérifiées auprès de l\'auditeur)')->columns(2)->schema([
                Forms\Components\TextInput::make('phone_e164')->label('Téléphone (connexion à l\'espace)')->required()->placeholder('+237699000000'),
                Forms\Components\TextInput::make('email')->label('E-mail')->email()->maxLength(150)
                    ->helperText('Obligatoire pour créer le compte eCampus, si aucun n\'existe sous ce matricule.'),
                Forms\Components\TextInput::make('adresse')->label('Adresse')->maxLength(200),
                Forms\Components\TextInput::make('ville_residence')->label('Ville de résidence')->maxLength(100),
                Forms\Components\Checkbox::make('confirmer_signaux')->label('Ce téléphone ou cet e-mail figure sur un autre dossier : je l\'ai vérifié')->columnSpanFull(),
            ]),
            Forms\Components\Section::make('Inscription en '.AnneeEnCours::annee())->columns(2)->schema([
                Forms\Components\Select::make('campagne_id')->label('Promotion de rattachement')->options($promotions)->required()
                    ->helperText('Proposée : la promotion d\'origine d\'après l\'archive. Une promotion antérieure absente se crée d\'abord (Vie académique > Promotions).'),
                Forms\Components\Radio::make('niveau')->label('Niveau')->options(['M1' => 'M1', 'M2' => 'M2'])->required()->live()
                    ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set, ?string $state) => $set('parcours', $state === 'M2' ? $get('parcours_m2') : Parcours::TRONC_COMMUN)),
                Forms\Components\Hidden::make('parcours_m2'),
                Forms\Components\Select::make('parcours')->label('Parcours')->required()
                    ->options(fn (Forms\Get $get): array => $get('niveau') === 'M2' ? $optionsM2() : [Parcours::TRONC_COMMUN => 'Tronc commun']),
                Forms\Components\Checkbox::make('confirmer_m1_admis')->label('Déjà admis en M1 dans l\'archive : je confirme la réinscription en M1')
                    ->visible(fn (Forms\Get $get): bool => $get('niveau') === 'M1'),
                Forms\Components\Toggle::make('derogation_m2')->label('Dérogation : M2 sans M1 admis dans l\'archive')->live()
                    ->visible(fn (Forms\Get $get): bool => $get('niveau') === 'M2'),
                Forms\Components\TextInput::make('derogation_piece')->label('Pièce de la dérogation')->maxLength(100)
                    ->visible(fn (Forms\Get $get): bool => $get('niveau') === 'M2' && (bool) $get('derogation_m2'))
                    ->required(fn (Forms\Get $get): bool => (bool) $get('derogation_m2')),
            ]),
            Forms\Components\Textarea::make('motif')->label('Motif (par exemple la demande écrite de l\'auditeur)')->required()
                ->minLength(ReinscriptionAncien::MOTIF_MINIMUM)->maxLength(500)->rows(2),
        ];
    }
}
