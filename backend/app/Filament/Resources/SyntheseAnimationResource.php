<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Filament\Resources\SyntheseAnimationResource\Pages;
use App\Models\ActiviteAnimation;
use App\Models\BaremeRemuneration;
use App\Models\ProgrammationEnseignant;
use App\Models\SyntheseAnimation;
use App\Services\Lmd\EtatsPaie;
use App\Services\Lmd\SynthesesAnimation;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Primes d'animation en ligne (lot 13b-2 ; D25) : une synthèse par enseignant et par cours, activités attendues et
 * réalisées (saisie ou export WhatsApp), validation, puis paiement sur l'état du mois où le cours se termine.
 */
#[BelongsToModule('lmd')]
class SyntheseAnimationResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = SyntheseAnimation::class;

    protected static ?string $modelLabel = 'Synthèse d\'animation';

    protected static ?string $pluralModelLabel = 'Primes d\'animation';

    protected static ?string $navigationLabel = 'Primes d\'animation';

    protected static ?string $breadcrumb = 'Primes d\'animation';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Paie des enseignants';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'primes-animation';

    /** @var array<string, int>|null Montant validé de la prime par année, lu une fois par requête. */
    private static ?array $baremes = null;

    public static function taux(?float $t): string
    {
        return $t === null ? '—' : str_replace('.', ',', (string) $t).' %';
    }

    /** Prime estimée : barème validé de l'année × coefficient (prorata ou seuil). */
    public static function primeEstimee(SyntheseAnimation $s): string
    {
        self::$baremes ??= BaremeRemuneration::query()->where('nature', EtatsPaie::PRIME)->where('statut', 'valide')
            ->pluck('montant_unitaire', 'annee_academique')->map(fn ($m): int => (int) $m)->all();
        $montant = self::$baremes[$s->annee_academique] ?? null;
        if ($montant === null) {
            return 'barème non validé';
        }

        return EtatsPaie::fcfa((int) round(app(SynthesesAnimation::class)->coefficient($s) * $montant)).' brut';
    }

    /**
     * Programmations payables sans synthèse, cherchées par enseignant ou par cours.
     *
     * @return array<string, string>
     */
    public static function programmations(?string $recherche = null): array
    {
        return ProgrammationEnseignant::query()->whereIn('statut', EtatsPaie::PROGRAMMATIONS_PAYABLES)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('syntheses_animation as s')->join('quotas_horaires as q', 'q.maquette_ecue_id', '=', 's.maquette_ecue_id')
                ->whereColumn('q.id', 'programmations_enseignant.quota_id')->whereColumn('s.enseignant_id', 'programmations_enseignant.enseignant_id'))
            ->when(filled($recherche), fn ($q) => $q->where(fn ($x) => $x->whereHas('enseignant', fn ($e) => $e->where('name', 'ilike', '%'.$recherche.'%'))
                ->orWhereHas('quota.ecue', fn ($e) => $e->where('code', 'ilike', '%'.$recherche.'%')->orWhere('intitule', 'ilike', '%'.$recherche.'%'))))
            ->with(['enseignant', 'quota.ecue'])->orderByDesc('id')->limit(50)->get()
            ->mapWithKeys(fn (ProgrammationEnseignant $p): array => [(string) $p->id => static::libelleProgrammation($p)])
            ->all();
    }

    public static function libelleProgrammation(ProgrammationEnseignant $p): string
    {
        return $p->enseignant->name.' — '.$p->quota->ecue->code.' '.Str::limit($p->quota->ecue->intitule, 50).($p->fenetre() === null ? '' : ' ('.$p->fenetre().')');
    }

    /** @return list<array<string, mixed>> */
    public static function activitesProposees(): array
    {
        return collect(ActiviteAnimation::PROPOSEES)->map(fn (string $famille, string $type): array => ['type_activite' => $type, 'famille' => $famille, 'attendu' => null, 'realise' => 0])
            ->values()->all();
    }

    /** @return list<Forms\Components\Component> */
    public static function champs(): array
    {
        return MessagesValidation::appliquer([
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\DatePicker::make('periode_du')->label('Début du cours')->required()->native(false)->displayFormat('d/m/Y'),
                Forms\Components\DatePicker::make('periode_au')->label('Fin du cours')->required()->native(false)->displayFormat('d/m/Y')->afterOrEqual('periode_du')
                    ->helperText('La prime est payée sur l\'état du mois de cette date.'),
                Forms\Components\TextInput::make('groupe_whatsapp')->label('Groupe WhatsApp du cours')->maxLength(150),
            ]),
            Forms\Components\Repeater::make('activites')->label('Activités')->required()->minItems(1)->reorderable(false)
                ->default(fn (): array => static::activitesProposees())
                ->addActionLabel('Ajouter une activité (classe virtuelle, quiz, devoir corrigé…)')
                ->columns(4)->schema([
                    Forms\Components\TextInput::make('type_activite')->label('Activité')->required()->maxLength(80)->distinct(),
                    Forms\Components\Select::make('famille')->label('Famille')->native(false)->options(ActiviteAnimation::FAMILLES)->required(),
                    Forms\Components\TextInput::make('attendu')->label('Attendu')->integer()->required()->minValue(1)->maxValue(10000),
                    Forms\Components\TextInput::make('realise')->label('Réalisé')->integer()->required()->minValue(0)->maxValue(100000)->default(0),
                ])
                ->helperText('La Scolarité fixe les quantités attendues. L\'import de l\'export WhatsApp remplit le réalisé des activités nommées « '
                    .implode(' », « ', array_keys(ActiviteAnimation::PROPOSEES)).' ».'),
        ], ['after_or_equal' => 'La fin du cours suit son début.', 'distinct' => 'Cette activité figure deux fois.', 'min' => 'L\'attendu est d\'au moins 1 ; le réalisé n\'est pas négatif.']);
    }

    public static function couleur(SyntheseAnimation $s): string
    {
        return $s->estPayee() ? 'info' : match ($s->statut) {
            SyntheseAnimation::VALIDE => 'success', SyntheseAnimation::REJETE => 'danger', default => 'warning'
        };
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::champs());
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $service = app(SynthesesAnimation::class);
        $calcul = fn (): SynthesesAnimation => $service;

        return $infolist->schema([
            Infolists\Components\Section::make('Synthèse')->columns(3)->schema([
                Infolists\Components\TextEntry::make('enseignant.name')->label('Enseignant'),
                Infolists\Components\TextEntry::make('cours')->label('Cours')->getStateUsing(fn (SyntheseAnimation $s): string => $s->ecue->code.' — '.$s->ecue->intitule),
                Infolists\Components\TextEntry::make('periode')->label('Période du cours')
                    ->getStateUsing(fn (SyntheseAnimation $s): string => 'du '.$s->periode_du->format('d/m/Y').' au '.$s->periode_au->format('d/m/Y')),
                Infolists\Components\TextEntry::make('statut')->label('État')->badge()
                    ->formatStateUsing(fn (string $state, SyntheseAnimation $s): string => $s->estPayee() ? 'Sur l\'état de paie '.$s->ligne->etat->numero : (SyntheseAnimation::STATUTS[$state] ?? $state))
                    ->color(fn (string $state, SyntheseAnimation $s): string => static::couleur($s)),
                Infolists\Components\TextEntry::make('valide')->label('Validée')->getStateUsing(fn (SyntheseAnimation $s): string => EtatPaieResource::etape($s->validePar?->name, $s->valide_at)),
                Infolists\Components\TextEntry::make('groupe_whatsapp')->label('Groupe WhatsApp')->placeholder('—'),
                Infolists\Components\TextEntry::make('source')->label('Réalisations')->formatStateUsing(fn (string $state): string => SyntheseAnimation::SOURCES[$state] ?? $state),
                Infolists\Components\TextEntry::make('justificatif')->label('Justificatif')->placeholder('—')->fontFamily('mono')->columnSpan(2)
                    ->helperText(fn (SyntheseAnimation $s): ?string => $s->importe_at === null ? null : 'Importé : '.EtatPaieResource::etape($s->importePar?->name, $s->importe_at).'. Le fichier n\'est pas conservé.'),
                Infolists\Components\TextEntry::make('motif_rejet')->label('Motif du rejet')->visible(fn (SyntheseAnimation $s): bool => $s->motif_rejet !== null)->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Taux de réalisation et prime')->columns(4)->schema([
                Infolists\Components\TextEntry::make('t_contribution')->label('Contributions')->getStateUsing(fn (SyntheseAnimation $s): string => static::taux($calcul()->taux($s)['contribution'])),
                Infolists\Components\TextEntry::make('t_animation')->label('Animations')->getStateUsing(fn (SyntheseAnimation $s): string => static::taux($calcul()->taux($s)['animation'])),
                Infolists\Components\TextEntry::make('t_global')->label('Global')->weight('bold')->getStateUsing(fn (SyntheseAnimation $s): string => static::taux($calcul()->taux($s)['global'])),
                Infolists\Components\TextEntry::make('prime')->label('Prime')
                    ->getStateUsing(fn (SyntheseAnimation $s): string => $s->ligne !== null ? EtatsPaie::fcfa($s->ligne->montant_brut).' brut, '.EtatsPaie::fcfa($s->ligne->montant_net).' net' : static::primeEstimee($s).' (estimée)')
                    ->helperText(fn (): string => SynthesesAnimation::MODES[$calcul()->mode()].($calcul()->mode() === SynthesesAnimation::SEUIL ? ' ('.$calcul()->seuil().' %)' : '').'.'),
            ]),
            Infolists\Components\RepeatableEntry::make('activites')->label('Activités')->columns(4)->columnSpanFull()->schema([
                Infolists\Components\TextEntry::make('type_activite')->label('Activité'),
                Infolists\Components\TextEntry::make('famille')->label('Famille')->formatStateUsing(fn (string $state): string => ActiviteAnimation::FAMILLES[$state] ?? $state),
                Infolists\Components\TextEntry::make('attendu')->label('Attendu'),
                Infolists\Components\TextEntry::make('realise')->label('Réalisé')
                    ->helperText(fn (ActiviteAnimation $a): ?string => $a->realise > $a->attendu ? 'compté pour '.$a->attendu.' (plafonné à l\'attendu)' : null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $service = app(SynthesesAnimation::class);
        $calcul = fn (): SynthesesAnimation => $service;

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['enseignant', 'ecue', 'activites', 'ligne.etat']))
            ->defaultSort('periode_au', 'desc')
            ->recordUrl(fn (SyntheseAnimation $s): string => static::getUrl('view', ['record' => $s]))
            ->columns([
                Tables\Columns\TextColumn::make('enseignant.name')->label('Enseignant')->searchable(),
                Tables\Columns\TextColumn::make('ecue.code')->label('Cours')->searchable()->description(fn (SyntheseAnimation $s): string => Str::limit($s->ecue->intitule, 50))->wrap(),
                Tables\Columns\TextColumn::make('periode_au')->label('Période')
                    ->formatStateUsing(fn (SyntheseAnimation $s): string => $s->periode_du->format('d/m/Y').' → '.$s->periode_au->format('d/m/Y')),
                Tables\Columns\TextColumn::make('taux')->label('Taux de réalisation')->alignEnd()
                    ->getStateUsing(fn (SyntheseAnimation $s): string => static::taux($calcul()->taux($s)['global']))
                    ->description(fn (SyntheseAnimation $s): string => 'contribution '.static::taux($calcul()->taux($s)['contribution']).' · animation '.static::taux($calcul()->taux($s)['animation'])),
                Tables\Columns\TextColumn::make('prime')->label('Prime')->alignEnd()
                    ->getStateUsing(fn (SyntheseAnimation $s): string => $s->ligne !== null ? EtatsPaie::fcfa($s->ligne->montant_brut).' brut' : static::primeEstimee($s))
                    ->description(fn (SyntheseAnimation $s): ?string => $s->ligne !== null ? 'état '.$s->ligne->etat->numero : 'estimée'),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()
                    ->formatStateUsing(fn (string $state, SyntheseAnimation $s): string => $s->estPayee() ? 'Sur un état de paie' : (SyntheseAnimation::STATUTS[$state] ?? $state))
                    ->color(fn (string $state, SyntheseAnimation $s): string => static::couleur($s))
                    ->description(fn (SyntheseAnimation $s): ?string => SyntheseAnimation::SOURCES[$s->source] ?? null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->label('État')->options(SyntheseAnimation::STATUTS),
                Tables\Filters\TernaryFilter::make('ligne_paie_id')->label('Portée sur un état de paie')->nullable(),
                Tables\Filters\SelectFilter::make('enseignant_id')->label('Enseignant')->options(fn (): array => ChampsProgrammation::enseignants())->searchable(),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Ouvrir')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSynthesesAnimation::route('/'),
            'create' => Pages\CreateSyntheseAnimation::route('/nouvelle'),
            'view' => Pages\ViewSyntheseAnimation::route('/{record}'),
        ];
    }
}
