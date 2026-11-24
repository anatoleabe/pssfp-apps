@php
    use App\Filament\Resources\PointageEnseignantResource;
    use App\Models\Seance;
    use App\Services\Lmd\EspaceEnseignant;

    $enseignant = $this->enseignant();
    $aVenir = $this->espace()->aVenir($enseignant);
    $surveillances = $this->espace()->surveillances($enseignant);
    $recentes = $this->espace()->recentes($enseignant);
    $carte = 'padding:.75rem 0;display:flex;flex-wrap:wrap;gap:.25rem 1rem;align-items:baseline;';
    $quand = 'flex:0 0 13rem;font-weight:600;';
    $quoi = 'flex:1 1 16rem;min-width:0;';
    $jour = fn ($date): string => ucfirst($date->locale('fr')->isoFormat('dddd D MMMM YYYY'));
    $heures = fn (Seance $s): string => substr((string) $s->debut, 0, 5).' – '.substr((string) $s->fin, 0, 5);
    $lieu = fn (Seance $s): string => $s->salleAffectee?->libelle ?? ($s->salle ?: (Seance::MODALITES[$s->modalite] ?? 'Salle à préciser'));
    $couleurs = ['a_emarger' => 'warning', 'a_valider' => 'info', 'valide' => 'success', 'rejete' => 'danger'];
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Séances à venir ({{ $aVenir->count() }})</x-slot>
        @forelse ($aVenir as $s)
            <div class="border-b border-gray-200 dark:border-white/10" style="{{ $carte }}">
                <div style="{{ $quand }}">{{ $jour($s->date_seance) }}<br><span style="font-weight:400;">{{ $heures($s) }}</span></div>
                <div style="{{ $quoi }}">
                    <div style="font-weight:600;">{{ $s->ecue?->code }} — {{ $s->ecue?->intitule }}</div>
                    <div class="text-gray-500 dark:text-gray-400" style="font-size:.875rem;">
                        {{ Seance::NATURES[$s->nature] ?? 'Séance' }} · {{ $s->emploiDuTemps?->parcours?->libelleComplet() }} · {{ $lieu($s) }}
                    </div>
                    @if ($s->lien_reunion)
                        <div style="font-size:.875rem;"><a href="{{ $s->lien_reunion }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 dark:text-primary-400" style="text-decoration:underline;">Rejoindre la réunion</a></div>
                    @endif
                    @if (! in_array($s->statut, Seance::ACTIVES, true))
                        <div style="margin-top:.25rem;">
                            <x-filament::badge color="danger" style="display:inline-flex;">{{ Seance::STATUTS[$s->statut] ?? $s->statut }}</x-filament::badge>
                            <span style="font-size:.875rem;">
                                {{ $s->statut === 'reportee' ? $s->motif_report : $s->motif_annulation }}
                                @if ($s->remplacement !== null) — nouvelle date : {{ $s->remplacement->date_seance->format('d/m/Y') }}, {{ $heures($s->remplacement) }} @endif
                            </span>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <p style="font-size:.875rem;margin:0;">Aucune séance à venir dans un emploi du temps publié.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Surveillances à venir ({{ count($surveillances) }})</x-slot>
        @forelse ($surveillances as $l)
            <div class="border-b border-gray-200 dark:border-white/10" style="{{ $carte }}">
                <div style="{{ $quand }}">{{ $jour($l['epreuve']->date_epreuve) }}<br><span style="font-weight:400;">{{ $l['epreuve']->heures() }}</span></div>
                <div style="{{ $quoi }}">
                    <div style="font-weight:600;">{{ $l['epreuve']->code_ecue }} — {{ $l['epreuve']->intitule }}</div>
                    <div class="text-gray-500 dark:text-gray-400" style="font-size:.875rem;">
                        {{ $l['epreuve']->planning?->session?->libelle }} · {{ $l['salle']?->libelle ?? 'Salle à préciser' }}
                    </div>
                </div>
            </div>
        @empty
            <p style="font-size:.875rem;margin:0;">Aucune surveillance à venir dans un planning d'examens publié.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Séances des {{ EspaceEnseignant::JOURS_PASSES }} derniers jours ({{ $recentes->count() }})</x-slot>
        <x-slot name="description">L'émargement se fait dans <a href="{{ PointageEnseignantResource::getUrl('index') }}" style="text-decoration:underline;">Mes émargements</a>.</x-slot>
        @forelse ($recentes as $s)
            @php $etat = EspaceEnseignant::emargement($s); @endphp
            <div class="border-b border-gray-200 dark:border-white/10" style="{{ $carte }}">
                <div style="{{ $quand }}">{{ $jour($s->date_seance) }}<br><span style="font-weight:400;">{{ $heures($s) }}</span></div>
                <div style="{{ $quoi }}">
                    <div style="font-weight:600;">{{ $s->ecue?->code }} — {{ $s->ecue?->intitule }}</div>
                    <div class="text-gray-500 dark:text-gray-400" style="font-size:.875rem;">{{ Seance::NATURES[$s->nature] ?? 'Séance' }} · {{ $s->emploiDuTemps?->parcours?->libelleComplet() }}</div>
                    <div style="margin-top:.25rem;"><x-filament::badge :color="$couleurs[$etat] ?? 'gray'" style="display:inline-flex;">{{ EspaceEnseignant::EMARGEMENTS[$etat] ?? $etat }}</x-filament::badge></div>
                </div>
            </div>
        @empty
            <p style="font-size:.875rem;margin:0;">Aucune séance sur la période.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
