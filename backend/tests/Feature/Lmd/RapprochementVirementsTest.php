<?php

declare(strict_types=1);

use App\Filament\Pages\Lmd\RapprochementVirementsPage;
use App\Filament\Resources\EtatPaieResource\Pages\ViewEtatPaie;
use App\Filament\Widgets\ATraiterWidget;
use App\Models\AvisVirement;
use App\Models\EtatPaie;
use App\Models\LigneEtatPaie;
use App\Models\User;
use App\Services\Lmd\RapprochementVirements as R;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd', 'paie');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed(RolePermissionSeeder::class);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->financier = ($this->compte)('service_financier');
    $this->awa = User::factory()->create(['name' => 'Enseignante Awa']);
    $this->bruno = User::factory()->create(['name' => 'Enseignant Bruno']);
    $this->claire = User::factory()->create(['name' => 'Enseignante Claire']);
    $this->etat = fn (string $statut, string $mois = '2026-10'): EtatPaie => EtatPaie::query()->forceCreate([
        'numero' => 'EP-'.$mois, 'periode_du' => $mois.'-01', 'periode_au' => $mois.'-28', 'objet' => 'Paie '.$mois, 'statut' => $statut,
        'reference_bordereau' => 'BT-'.$mois, 'verifie_at' => '2026-10-31 09:00:00', 'pris_en_charge_at' => '2026-11-01 09:00:00',
        ...($statut === EtatPaie::PAYE ? ['paye_le' => '2026-11-05', 'reference_paiement' => 'VIR-GLOBAL'] : []),
    ]);
    $this->ligne = fn (EtatPaie $e, User $u, int $net): LigneEtatPaie => LigneEtatPaie::query()->forceCreate([
        'etat_id' => $e->id, 'enseignant_id' => $u->id, 'annee_academique' => '2026-2027', 'nature' => 'CM', 'quantite' => 1,
        'montant_unitaire' => $net, 'taux_retenue' => 0, 'montant_brut' => $net, 'montant_retenue' => 0, 'montant_net' => $net,
    ]);
    $this->avis = fn (User $u, int $montant, string $ref, array $extra = []): array => [
        'enseignant_id' => $u->id, 'montant' => $montant, 'date_valeur' => '2026-11-06', 'reference' => $ref, 'source' => 'releve_bancaire', ...$extra,
    ];
    $this->service = app(R::class);
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
});

it('rapproche chaque enseignant de son net dû, avis annulés exclus', function (): void {
    $etat = ($this->etat)(EtatPaie::PAYE);
    ($this->ligne)($etat, $this->awa, 100000);
    ($this->ligne)($etat, $this->awa, 50000);
    ($this->ligne)($etat, $this->bruno, 80000);
    ($this->ligne)($etat, $this->claire, 60000);
    $this->service->enregistrer($etat, [
        ($this->avis)($this->awa, 100000, 'VIR-1'), ($this->avis)($this->awa, 50000, 'VIR-2'),
        ($this->avis)($this->bruno, 50000, 'VIR-3'), ($this->avis)($this->bruno, 99999, 'VIR-ERREUR'),
    ], $this->financier);
    $this->service->annuler(AvisVirement::query()->where('reference', 'VIR-ERREUR')->firstOrFail(), 'Montant mal saisi sur le relevé', $this->financier);

    $r = collect($this->service->rapprochement($etat))->keyBy('enseignant');

    expect($r['Enseignante Awa'])->toMatchArray(['net_du' => 150000, 'vire' => 150000, 'ecart' => 0, 'situation' => R::RAPPROCHE, 'references' => 'VIR-1, VIR-2'])
        ->and($r['Enseignant Bruno'])->toMatchArray(['net_du' => 80000, 'vire' => 50000, 'ecart' => -30000, 'situation' => R::PARTIEL])
        ->and($r['Enseignante Claire'])->toMatchArray(['vire' => 0, 'situation' => R::NON_VIRE])
        ->and($this->service->bilanEtat($etat))->toBe('ecarts');
});

it('ne compte un non viré comme anomalie que sur un état payé, et signale les virements en trop ou sans état', function (): void {
    $pris = ($this->etat)(EtatPaie::PRIS_EN_CHARGE, '2026-09');
    ($this->ligne)($pris, $this->awa, 100000);
    $paye = ($this->etat)(EtatPaie::PAYE, '2026-10');
    ($this->ligne)($paye, $this->bruno, 80000);
    ($this->ligne)($paye, $this->claire, 60000);
    $this->service->enregistrer($paye, [($this->avis)($this->claire, 70000, 'VIR-C')], $this->financier);
    $this->service->enregistrer(null, [($this->avis)($this->awa, 15000, 'VIR-ORPHELIN')], $this->financier);

    $anomalies = collect($this->service->anomalies())->map(fn (array $a): string => $a['enseignant'].' : '.$a['situation'])->all();

    expect($anomalies)->toEqualCanonicalizing([
        'Enseignant Bruno : '.R::NON_VIRE,
        'Enseignante Claire : '.R::EN_TROP,
        'Enseignante Awa : '.R::SANS_ETAT,
    ])->and($this->service->bilanEtat($pris))->toBe('a_faire');
});

it('refuse les saisies invalides', function (string $cas): void {
    $etat = ($this->etat)(EtatPaie::PAYE);
    ($this->ligne)($etat, $this->awa, 100000);
    $brouillon = EtatPaie::query()->forceCreate(['numero' => 'EP-B', 'periode_du' => '2026-08-01', 'periode_au' => '2026-08-31', 'objet' => 'B', 'statut' => EtatPaie::BROUILLON]);

    $appel = match ($cas) {
        'montant nul' => fn () => $this->service->enregistrer($etat, [($this->avis)($this->awa, 0, 'V')], $this->financier),
        'état en brouillon' => fn () => $this->service->enregistrer($brouillon, [($this->avis)($this->awa, 1000, 'V')], $this->financier),
        'enseignant absent de l\'état' => fn () => $this->service->enregistrer($etat, [($this->avis)($this->bruno, 1000, 'V')], $this->financier),
        'date future' => fn () => $this->service->enregistrer($etat, [($this->avis)($this->awa, 1000, 'V', ['date_valeur' => '2026-12-01'])], $this->financier),
        'référence vide' => fn () => $this->service->enregistrer($etat, [($this->avis)($this->awa, 1000, '  ')], $this->financier),
    };

    expect($appel)->toThrow(InvalidArgumentException::class);
    expect(AvisVirement::query()->count())->toBe(0);
})->with(['montant nul', 'état en brouillon', 'enseignant absent de l\'état', 'date future', 'référence vide']);

it('accepte le rejeu identique, refuse une référence réutilisée, l\'accepte après annulation', function (): void {
    $etat = ($this->etat)(EtatPaie::PAYE);
    ($this->ligne)($etat, $this->awa, 100000);
    $this->service->enregistrer($etat, [($this->avis)($this->awa, 100000, 'VIR-1')], $this->financier);

    expect($this->service->enregistrer($etat, [($this->avis)($this->awa, 100000, 'VIR-1')], $this->financier))->toHaveCount(0)
        ->and(fn () => $this->service->enregistrer($etat, [($this->avis)($this->awa, 90000, 'VIR-1')], $this->financier))->toThrow(InvalidArgumentException::class, 'déjà enregistrée');

    $avis = AvisVirement::query()->where('reference', 'VIR-1')->firstOrFail();
    expect(fn () => $this->service->annuler($avis, 'court', $this->financier))->toThrow(InvalidArgumentException::class, 'au moins');
    $this->service->annuler($avis, 'Montant erroné sur le relevé', $this->financier);
    expect(fn () => $this->service->annuler($avis, 'Montant erroné sur le relevé', $this->financier))->toThrow(InvalidArgumentException::class, 'déjà annulé');

    expect($this->service->enregistrer($etat, [($this->avis)($this->awa, 90000, 'VIR-1')], $this->financier))->toHaveCount(1);
});

it('refuse en base la modification et la suppression d\'un avis, sauf l\'annulation', function (): void {
    $etat = ($this->etat)(EtatPaie::PAYE);
    ($this->ligne)($etat, $this->awa, 100000);
    $avis = $this->service->enregistrer($etat, [($this->avis)($this->awa, 100000, 'VIR-1')], $this->financier)->first();

    expect(fn () => DB::transaction(fn () => DB::table('avis_virement')->where('id', $avis->id)->update(['montant' => 1])))->toThrow(QueryException::class, 'ajout seul')
        ->and(fn () => DB::transaction(fn () => DB::table('avis_virement')->where('id', $avis->id)->delete()))->toThrow(QueryException::class, 'ajout seul');

    $this->service->annuler($avis, 'Virement rejeté par la banque', $this->financier);
    expect(fn () => DB::transaction(fn () => DB::table('avis_virement')->where('id', $avis->id)->update(['motif_annulation' => 'Autre motif plus long'])))->toThrow(QueryException::class, 'ajout seul');
});

it('réserve la saisie au service financier et la consultation à qui vérifie la paie', function (): void {
    $etat = ($this->etat)(EtatPaie::PAYE);
    ($this->ligne)($etat, $this->awa, 100000);
    $responsable = ($this->compte)('scolarite_responsable');
    $agent = ($this->compte)('scolarite_agent');

    expect(fn () => $this->service->enregistrer($etat, [($this->avis)($this->awa, 1000, 'V')], $responsable))->toThrow(HttpException::class)
        ->and(R::peutConsulter($responsable))->toBeTrue()
        ->and(R::peutConsulter($agent))->toBeFalse()
        ->and(R::peutSaisir($this->financier))->toBeTrue();
});

it('journalise l\'enregistrement et l\'annulation dans le journal paie', function (): void {
    $etat = ($this->etat)(EtatPaie::PAYE);
    ($this->ligne)($etat, $this->awa, 100000);
    $avis = $this->service->enregistrer($etat, [($this->avis)($this->awa, 100000, 'VIR-1')], $this->financier)->first();
    $this->service->annuler($avis, 'Virement rejeté par la banque', $this->financier);

    expect(Activity::query()->where('log_name', 'paie')->whereIn('event', ['avis_virement_enregistre', 'avis_virement_annule'])->count())->toBe(2);
});

it('ouvre l\'écran du rapprochement au service financier et à qui vérifie la paie, pas aux autres', function (string $role, int $statut): void {
    $this->actingAs(($this->compte)($role))->get(RapprochementVirementsPage::getUrl())->assertStatus($statut);
})->with([['service_financier', 200], ['scolarite_responsable', 200], ['scolarite_agent', 403], ['editor', 403]]);

it('signale les écarts dans la pastille du menu et dans l\'encart « À traiter »', function (): void {
    $etat = ($this->etat)(EtatPaie::PAYE);
    ($this->ligne)($etat, $this->awa, 100000);
    ($this->ligne)($etat, $this->bruno, 50000);
    $this->service->enregistrer($etat, [($this->avis)($this->awa, 100000, 'VIR-1')], $this->financier);
    $this->actingAs($this->financier);

    expect(RapprochementVirementsPage::getNavigationBadge())->toBe('1')
        ->and(collect(ATraiterWidget::elements())->firstWhere('libelle', 'Écarts entre la paie et les virements'))
        ->toMatchArray(['nombre' => '1', 'groupe' => 'Enseignants et paie']);

    $this->get(RapprochementVirementsPage::getUrl())->assertOk()->assertSee('Enseignant Bruno')->assertSee('Non viré');
});

it('enregistre les virements depuis la grille de la fiche et les annule', function (): void {
    $etat = ($this->etat)(EtatPaie::PAYE);
    ($this->ligne)($etat, $this->awa, 100000);
    ($this->ligne)($etat, $this->bruno, 50000);
    $this->actingAs($this->financier);

    \Pest\Livewire\livewire(ViewEtatPaie::class, ['record' => $etat->uuid])
        ->mountAction('enregistrerVirements')
        ->assertActionDataSet(['virements' => fn (array $v): bool => count($v) === 2])
        ->setActionData(['virements' => collect(\Pest\Livewire\livewire(ViewEtatPaie::class, ['record' => $etat->uuid])
            ->mountAction('enregistrerVirements')->get('mountedActionsData.0.virements'))
            ->map(fn (array $l): array => [...$l, 'reference' => $l['enseignant'] === 'Enseignante Awa' ? 'VIR-AWA' : null])->all()])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(AvisVirement::query()->enVigueur()->pluck('reference')->all())->toBe(['VIR-AWA'])
        ->and(collect($this->service->rapprochement($etat))->firstWhere('enseignant', 'Enseignante Awa')['situation'])->toBe(R::RAPPROCHE);

    $avis = AvisVirement::query()->firstOrFail();
    \Pest\Livewire\livewire(ViewEtatPaie::class, ['record' => $etat->uuid])
        ->callAction('annulerAvis', ['avis' => $avis->uuid, 'motif' => 'Virement rejeté par la banque'])
        ->assertHasNoActionErrors();

    expect($avis->refresh()->estAnnule())->toBeTrue();
});

it('ne vire qu\'à un enseignant : ni candidat, ni administrateur, ni le financier lui-même', function (): void {
    $admin = ($this->compte)('admin');
    $enseignantSansLigne = ($this->compte)('enseignant');

    foreach ([$admin, $this->financier, User::factory()->create()] as $cible) {
        expect(fn () => $this->service->enregistrer(null, [($this->avis)($cible, 1000, 'V-'.$cible->id)], $this->financier))
            ->toThrow(InvalidArgumentException::class, 'enseignant');
    }
    expect($this->service->enregistrer(null, [($this->avis)($enseignantSansLigne, 1000, 'V-OK')], $this->financier))->toHaveCount(1);
});

it('borne le montant et refuse une date de valeur antérieure à la prise en charge', function (): void {
    $etat = ($this->etat)(EtatPaie::PAYE);
    ($this->ligne)($etat, $this->awa, 100000);

    expect(fn () => $this->service->enregistrer($etat, [($this->avis)($this->awa, R::MONTANT_MAX + 1, 'V-1')], $this->financier))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->service->enregistrer($etat, [($this->avis)($this->awa, 1000, 'V-2', ['date_valeur' => '2026-01-01'])], $this->financier))
        ->toThrow(InvalidArgumentException::class, 'prise en charge');
});

it('met à jour la pastille dès la saisie ou l\'annulation, malgré le cache', function (): void {
    $etat = ($this->etat)(EtatPaie::PAYE);
    ($this->ligne)($etat, $this->awa, 100000);

    expect($this->service->nombreAnomalies())->toBe(1);
    $avis = $this->service->enregistrer($etat, [($this->avis)($this->awa, 100000, 'VIR-1')], $this->financier)->first();
    expect($this->service->nombreAnomalies())->toBe(0);
    $this->service->annuler($avis, 'Virement rejeté par la banque', $this->financier);
    expect($this->service->nombreAnomalies())->toBe(1);
});

it('calcule le bilan de plusieurs états en requêtes groupées', function (): void {
    $etats = collect(['2026-07', '2026-08', '2026-09', '2026-10'])->map(function (string $mois): EtatPaie {
        $e = ($this->etat)(EtatPaie::PAYE, $mois);
        ($this->ligne)($e, $this->awa, 1000);

        return $e;
    });

    DB::enableQueryLog();
    $bilans = $this->service->bilans($etats);
    $requetes = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($bilans)->toHaveCount(4)->and($requetes)->toBeLessThanOrEqual(3);
});

it('affiche la colonne du rapprochement dans la liste paginée des états', function (): void {
    $etat = ($this->etat)(EtatPaie::PAYE);
    ($this->ligne)($etat, $this->awa, 100000);
    $this->service->enregistrer($etat, [($this->avis)($this->awa, 100000, 'VIR-1')], $this->financier);

    $this->actingAs($this->financier)->get(\App\Filament\Resources\EtatPaieResource::getUrl('index'))->assertOk()->assertSee('Complet');
});
