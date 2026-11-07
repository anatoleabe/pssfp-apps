<x-filament-panels::page>
    <form wire:submit.prevent>{{ $this->form }}</form>

    @php
        $etape = $this->etapeCourante();
        $lignes = $this->lignes();
        $examen = $etape?->etape === \App\Models\EtapeSaisie::EXAMEN;
        $modifiable = $etape?->statut === \App\Models\EtapeSaisie::OUVERTE;
        $cellule = 'padding:.4rem .6rem .4rem 0;border-bottom:1px solid rgb(var(--gray-200));vertical-align:middle;';
        $champ = 'width:100%;border:1px solid rgb(var(--gray-300));border-radius:.5rem;padding:.35rem .5rem;font-size:.875rem;background:transparent;';
    @endphp

    @if ($etape === null)
        <x-filament::section>
            <p style="font-size:.875rem;">Choisissez une maquette validée, un ECUE, la session et l'étape.</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">
                {{ $etape->ecue->code }} — {{ $etape->ecue->intitule }}
            </x-slot>
            <x-slot name="description">
                {{ \App\Models\EtapeSaisie::SESSIONS[$etape->session] }} · {{ \App\Models\EtapeSaisie::ETAPES[$etape->etape] }} ·
                étape <strong>{{ mb_strtolower(\App\Models\EtapeSaisie::STATUTS[$etape->statut]) }}</strong>
                · {{ $lignes->count() }} auditeur(s) inscrit(s)
                @if ($etape->statut === \App\Models\EtapeSaisie::VERROUILLEE && $etape->verrouillee_at)
                    · verrouillée le {{ $etape->verrouillee_at->timezone('Africa/Douala')->format('d/m/Y à H:i') }}
                @endif
            </x-slot>

            @if (! $modifiable)
                <p style="font-size:.875rem;margin-bottom:.75rem;">
                    {{ $etape->statut === \App\Models\EtapeSaisie::FERMEE ? 'L\'étape est fermée : la saisie ouvre à l\'ouverture de l\'étape.' : 'L\'étape est verrouillée : une note ne change plus que par une requête ou une réouverture motivée.' }}
                </p>
            @endif

            @if ($lignes->isEmpty())
                <p style="font-size:.875rem;">Aucun auditeur n'est inscrit à cette UE.</p>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                        <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Notes de l'étape</caption>
                        <thead>
                            <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                                <th scope="col" style="padding:.35rem .6rem .35rem 0;">Matricule</th>
                                <th scope="col" style="padding:.35rem .6rem .35rem 0;">Nom et prénom</th>
                                <th scope="col" style="padding:.35rem .6rem .35rem 0;text-align:right;">Note actuelle</th>
                                <th scope="col" style="padding:.35rem .6rem .35rem 0;width:7rem;">Note / 20</th>
                                @if ($examen)
                                    <th scope="col" style="padding:.35rem .6rem .35rem 0;width:11rem;">Absence</th>
                                @endif
                                <th scope="col" style="padding:.35rem 0;">Motif (si la note change)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lignes as $l)
                                <tr wire:key="ligne-{{ $l['id'] }}">
                                    <td style="{{ $cellule }}font-family:ui-monospace,monospace;white-space:nowrap;">{{ $l['matricule'] }}</td>
                                    <td style="{{ $cellule }}">{{ $l['nom'] }}</td>
                                    <td style="{{ $cellule }}text-align:right;white-space:nowrap;">
                                        {{ $l['actuelle'] ?? (in_array($l['presence'], ['absent_justifie', 'absent_non_justifie'], true) ? \App\Models\NoteEcue::PRESENCES[$l['presence']] : '—') }}
                                    </td>
                                    <td style="{{ $cellule }}">
                                        <label for="note-{{ $l['id'] }}" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Note de {{ $l['matricule'] }}</label>
                                        <input id="note-{{ $l['id'] }}" type="text" inputmode="decimal" maxlength="5" wire:model="saisies.{{ $l['id'] }}.note" @disabled(! $modifiable) style="{{ $champ }}text-align:right;">
                                    </td>
                                    @if ($examen)
                                        <td style="{{ $cellule }}">
                                            <label for="presence-{{ $l['id'] }}" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Absence de {{ $l['matricule'] }}</label>
                                            <select id="presence-{{ $l['id'] }}" wire:model="saisies.{{ $l['id'] }}.presence" @disabled(! $modifiable) style="{{ $champ }}">
                                                <option value="">—</option>
                                                <option value="absent_justifie">Absent justifié</option>
                                                <option value="absent_non_justifie">Absent non justifié</option>
                                            </select>
                                        </td>
                                    @endif
                                    <td style="{{ $cellule }}padding-right:0;">
                                        <label for="motif-{{ $l['id'] }}" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Motif pour {{ $l['matricule'] }}</label>
                                        <input id="motif-{{ $l['id'] }}" type="text" maxlength="500" wire:model="saisies.{{ $l['id'] }}.motif" @disabled(! $modifiable || $l['actuelle'] === null && $l['presence'] === null) style="{{ $champ }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($modifiable)
                    <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;margin-top:1rem;">
                        <div style="flex:1 1 18rem;">
                            <label for="motif-commun" style="display:block;font-size:.875rem;font-weight:500;margin-bottom:.25rem;">Motif commun des corrections (s'applique aux lignes sans motif)</label>
                            <input id="motif-commun" type="text" maxlength="500" wire:model="motifCommun" style="{{ $champ }}">
                        </div>
                        <x-filament::button wire:click="enregistrer" icon="heroicon-o-check">Enregistrer les notes</x-filament::button>
                    </div>
                @endif
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
