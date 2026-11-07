@php
    $inscription = $getRecord()->inscriptionCourante;
    $resultats = collect();
    if ($inscription !== null) {
        $regle = \App\Models\RegleEvaluation::pourAnnee($inscription->annee_academique);
        $service = app(\App\Services\Lmd\SaisieNotes::class);
        $resultats = $inscription->inscriptionsPedagogiques()
            ->with(['maquetteUe.ue', 'maquetteUe.ecues', 'notes', 'derogationRattrapage'])->get()
            ->sortBy(fn ($ip) => [$ip->maquetteUe?->semestre, $ip->maquetteUe?->ordre])
            ->map(fn ($ip) => ['ip' => $ip, 'r' => $service->resultat($ip, $regle)]);
    }
    $cellule = 'padding:.4rem .75rem .4rem 0;border-bottom:1px solid rgb(var(--gray-200));vertical-align:top;';
    $num = 'text-align:right;white-space:nowrap;';
    $fmt = fn (?float $v): string => $v === null ? '—' : number_format($v, 2, ',', ' ');
@endphp
@if ($resultats->isEmpty())
    <p style="font-size:.875rem;">Aucune UE inscrite pour l'année en cours.</p>
@else
    <p style="font-size:.8rem;color:rgb(var(--gray-500));margin-bottom:.5rem;">Calcul provisoire : seuls les résultats arrêtés par le jury font foi.</p>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
            <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Notes de l'année</caption>
            <thead>
                <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                    <th scope="col" style="padding:.35rem .75rem .35rem 0;">UE / ECUE</th>
                    <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">CC</th>
                    <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">Assid.</th>
                    <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">Examen</th>
                    <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">Ratt.</th>
                    <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">Moyenne</th>
                    <th scope="col" style="padding:.35rem 0;">État</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($resultats as ['ip' => $ip, 'r' => $r])
                    @php
                        [$etat, $fond] = match (true) {
                            $r->acquise => ['Acquise', 'background:rgb(220 252 231);color:rgb(22 101 52);'],
                            ! $r->complete && ! $r->rattrapageSaisi => ['Incomplète', 'background:rgb(var(--gray-100));color:rgb(var(--gray-700));'],
                            $r->eliminatoire => ['Note éliminatoire', 'background:rgb(254 226 226);color:rgb(153 27 27);'],
                            default => ['Non acquise'.($r->eligibleRattrapage ? ' · rattrapage' : ''), 'background:rgb(254 243 199);color:rgb(146 64 14);'],
                        };
                    @endphp
                    <tr style="background:rgb(var(--gray-50));font-weight:600;">
                        <th scope="row" style="{{ $cellule }}text-align:left;">{{ $r->code }} — {{ $ip->maquetteUe?->ue?->intitule }} <span style="font-weight:400;">({{ $r->credits }} cr.)</span></th>
                        <td style="{{ $cellule }}" colspan="4"></td>
                        <td style="{{ $cellule }}{{ $num }}">{{ $fmt($r->note) }}</td>
                        <td style="{{ $cellule }}padding-right:0;"><span style="font-size:.75rem;padding:.05rem .45rem;border-radius:.375rem;{{ $fond }}">{{ $etat }}</span>
                            @if ($ip->derogationRattrapage)
                                <span style="display:block;font-weight:400;font-size:.72rem;">Dérogation : {{ $ip->derogationRattrapage->reference_acte }}</span>
                            @endif
                        </td>
                    </tr>
                    @foreach ($r->ecues as $e)
                        <tr>
                            <td style="{{ $cellule }}padding-left:1rem;">{{ $e->code }}</td>
                            <td style="{{ $cellule }}{{ $num }}">{{ $fmt($e->cc) }}</td>
                            <td style="{{ $cellule }}{{ $num }}">{{ $fmt($e->assiduite) }}</td>
                            <td style="{{ $cellule }}{{ $num }}">{{ $e->examen !== null ? $fmt($e->examen) : ($e->presence && $e->presence !== 'present' ? 'Abs.' : '—') }}</td>
                            <td style="{{ $cellule }}{{ $num }}">{{ $fmt($e->rattrapage) }}</td>
                            <td style="{{ $cellule }}{{ $num }}">{{ $fmt($e->moyenneRetenue) }}</td>
                            <td style="{{ $cellule }}padding-right:0;"></td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
@endif
