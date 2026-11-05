@php
    /** @var \App\Models\Auditeur $record */
    $inscription = $getRecord()->inscriptionCourante;
    $situation = $inscription?->situation();
    $fcfa = fn (int $m): string => \App\Services\Scolarite\LibelleSituation::fcfa($m);
@endphp
@if ($situation === null)
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Aucune inscription : le barème M1 de l'année de cette promotion n'existe pas encore. Un responsable de scolarité le saisit dans Vie académique &gt; Barèmes, puis « Créer l'inscription ».
    </p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <caption class="sr-only">Paliers de paiement de l'inscription {{ $inscription->annee_academique }}</caption>
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                    <th scope="col" class="py-2 pr-4">Palier</th>
                    <th scope="col" class="py-2 pr-4 text-right">Dû</th>
                    <th scope="col" class="py-2 pr-4 text-right">Payé</th>
                    <th scope="col" class="py-2 pr-4 text-right">Reste</th>
                    <th scope="col" class="py-2">Échéance</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($situation->paliers as $palier)
                    <tr>
                        <th scope="row" class="py-2 pr-4 text-left font-medium">{{ $palier->libelle() }}</th>
                        <td class="py-2 pr-4 text-right tabular-nums">{{ $fcfa($palier->du) }}</td>
                        <td class="py-2 pr-4 text-right tabular-nums">{{ $fcfa($palier->paye) }}</td>
                        <td class="py-2 pr-4 text-right tabular-nums">
                            @if ($palier->solde())
                                <span class="font-semibold text-success-600">Soldé</span>
                            @else
                                {{ $fcfa($palier->reste) }}
                            @endif
                        </td>
                        <td class="py-2">
                            @if ($palier->cle === 'inscription')
                                {{ $inscription->bareme?->inscription_echeance ?? '—' }}
                            @else
                                {{ $palier->echeance?->format('d/m/Y') ?? '—' }}
                            @endif
                            @if ($palier->enRetard)
                                <span class="ml-1 font-semibold text-warning-600">En retard</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-semibold">
                    <th scope="row" class="py-2 pr-4 text-left">Total</th>
                    <td class="py-2 pr-4 text-right tabular-nums">{{ $fcfa($situation->duTotal) }}</td>
                    <td class="py-2 pr-4 text-right tabular-nums">{{ $fcfa($situation->verse) }}</td>
                    <td class="py-2 pr-4 text-right tabular-nums">{{ $fcfa($situation->reste) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    @if ($situation->remise > 0)
        <p class="mt-3 text-sm">Remise accordée : <strong>{{ $fcfa($situation->remise) }}</strong>@if ($inscription->remise_motif) — {{ $inscription->remise_motif }}@endif</p>
    @endif
    @if ($situation->tropPercu > 0)
        <p class="mt-2 text-sm font-semibold text-warning-600">Trop-perçu : {{ $fcfa($situation->tropPercu) }}</p>
    @endif
@endif
