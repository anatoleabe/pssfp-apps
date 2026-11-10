<?php

declare(strict_types=1);

use App\Filament\Pages\Lmd\ControleGestionPage;
use App\Filament\Widgets\ATraiterWidget;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\EtatPaie;
use App\Models\InscriptionAnnuelle;
use App\Models\LigneEtatPaie;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\Requete;
use App\Models\RequeteEvenement;
use App\Models\User;
use App\Services\Lmd\ControleGestion;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd', 'paie');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', 'Africa/Douala'));
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    $tc = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'TC'))->firstOrFail();
    $tc->forceFill(['statut' => Maquette::VALIDEE, 'validee_at' => now()])->save();
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->inscriptions = collect(range(1, 3))->map(function () use ($bareme): InscriptionAnnuelle {
        $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory()->campagneCommune(), 'bareme_id' => $bareme->id]);
        app(InscriptionsPedagogiques::class)->inscrire($i);

        return $i;
    });
    $this->ecue = MaquetteEcue::query()->where('code', 'MPMPP711')->firstOrFail();
    $this->enseignant = User::factory()->create(['name' => 'Enseignante Awa']);
    $this->etat = fn (string $statut, string $mois): EtatPaie => EtatPaie::query()->forceCreate([
        'numero' => 'EP-'.$mois.'-'.substr($statut, 0, 4), 'periode_du' => $mois.'-01', 'periode_au' => $mois.'-28', 'objet' => 'Paie '.$mois, 'statut' => $statut,
        'rectificatif_de' => null, 'reference_bordereau' => 'BT', 'verifie_at' => '2026-10-31 09:00:00', 'pris_en_charge_at' => '2026-11-01 09:00:00',
        ...($statut === EtatPaie::PAYE ? ['paye_le' => '2026-11-05', 'reference_paiement' => 'VIR'] : []),
        ...($statut === EtatPaie::REJETE ? ['motif_rejet' => 'Erreur'] : []),
    ]);
    $this->ligne = fn (EtatPaie $e, string $nature, float $quantite, int $brut, ?int $ecue, int $retenue = 0): LigneEtatPaie => LigneEtatPaie::query()->forceCreate([
        'etat_id' => $e->id, 'enseignant_id' => $this->enseignant->id, 'maquette_ecue_id' => $ecue, 'annee_academique' => '2026-2027', 'nature' => $nature,
        'quantite' => $quantite, 'montant_unitaire' => 0, 'taux_retenue' => 0, 'montant_brut' => $brut, 'montant_retenue' => $retenue, 'montant_net' => $brut - $retenue,
    ]);
    $this->service = app(ControleGestion::class);
});

it('calcule les coûts par UE et par parcours sur le seul réalisé, surveillances hors UE', function (): void {
    $paye = ($this->etat)(EtatPaie::PAYE, '2026-10');
    ($this->ligne)($paye, 'CM', 10, 175000, $this->ecue->id, 19250);
    ($this->ligne)($paye, 'prime_animation', 0.5, 50000, $this->ecue->id);
    ($this->ligne)($paye, 'surveillance', 2, 20000, null);
    $brouillon = EtatPaie::query()->forceCreate(['numero' => 'EP-B', 'periode_du' => '2026-09-01', 'periode_au' => '2026-09-30', 'objet' => 'B', 'statut' => EtatPaie::BROUILLON]);
    ($this->ligne)($brouillon, 'CM', 99, 999999, $this->ecue->id);

    $ues = $this->service->coutsUe('2026-2027');
    $ue = collect($ues)->firstWhere('maquette_ue_id', $this->ecue->maquette_ue_id);
    $parcours = collect($this->service->coutsParcours('2026-2027', $ues))->firstWhere('parcours', $ue['parcours']);
    $autreUe = collect($ues)->first(fn (array $u): bool => $u['maquette_ue_id'] !== $this->ecue->maquette_ue_id && $u['parcours_id'] === $ue['parcours_id']);

    expect($ue)->toMatchArray(['heures_cm' => 10.0, 'cout_cm' => 175000, 'cout_primes' => 50000, 'cout_total' => 225000, 'inscrits' => 3,
        'cout_par_inscrit' => 75000, 'cout_horaire' => 22500])
        ->and($autreUe)->toMatchArray(['cout_total' => 0, 'cout_horaire' => null])
        ->and($parcours)->toMatchArray(['cout_total' => 225000, 'auditeurs' => 3, 'cout_par_auditeur' => 75000])
        ->and($this->service->horsUe('2026-2027'))->toBe(20000);
});

it('remplit le tableau de bord au mois de fin de période, sans brouillons ni rejets', function (): void {
    $transmis = ($this->etat)(EtatPaie::TRANSMIS, '2026-10');
    ($this->ligne)($transmis, 'CM', 4, 70000, $this->ecue->id, 7700);
    $rejete = ($this->etat)(EtatPaie::REJETE, '2026-09');
    ($this->ligne)($rejete, 'CM', 4, 70000, $this->ecue->id);

    $mois = collect($this->service->tableauMensuel())->keyBy('mois');

    expect($mois)->toHaveCount(12)
        ->and($mois['2026-10'])->toMatchArray(['heures_cm_payees' => 4.0, 'paie_brute' => 70000, 'retenues' => 7700, 'enseignants_payes' => 1])
        ->and($mois['2026-09']['paie_brute'])->toBe(0);
});

it('signale les dossiers en retard : requête au-delà du délai réglé, état de paie non payé après 30 jours', function (): void {
    $requete = fn (string $statut, string $deposee): Requete => Requete::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'numero' => 'RQ-'.Str::random(6), 'type' => 'document', 'type_document' => 'autre',
        'auditeur_id' => $this->inscriptions[0]->auditeur_id, 'inscription_annuelle_id' => $this->inscriptions[0]->id,
        'description' => 'Demande', 'statut' => $statut, 'sha256' => str_repeat('a', 64), 'created_at' => $deposee, 'updated_at' => $deposee,
    ]);
    $requete('deposee', '2026-09-20 10:00');
    $requete('cloturee', '2026-09-01 10:00');
    $requete('deposee', '2026-11-01 10:00');
    ($this->etat)(EtatPaie::PRIS_EN_CHARGE, '2026-09');
    ($this->etat)(EtatPaie::PAYE, '2026-08');

    expect(collect($this->service->dossiersEnRetard())->pluck('dossier')->all())->toEqualCanonicalizing(['Requête', 'État de paie'])
        ->and($this->service->nombreEnRetard())->toBe(2);

    $this->service->definirDelaiRequetes(60, ($this->compte)('super_admin'));
    expect($this->service->nombreEnRetard())->toBe(1);
});

it('compte les requêtes déposées et clôturées du mois', function (): void {
    $r = Requete::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'numero' => 'RQ-1', 'type' => 'document', 'type_document' => 'autre',
        'auditeur_id' => $this->inscriptions[0]->auditeur_id, 'inscription_annuelle_id' => $this->inscriptions[0]->id,
        'description' => 'Demande', 'statut' => 'cloturee', 'sha256' => str_repeat('a', 64), 'created_at' => '2026-10-02 10:00', 'updated_at' => '2026-10-02 10:00',
    ]);
    RequeteEvenement::query()->forceCreate(['requete_id' => $r->id, 'statut' => 'cloturee', 'libelle' => 'Requête clôturée', 'created_at' => '2026-11-03 10:00']);

    $mois = collect($this->service->tableauMensuel())->keyBy('mois');

    expect($mois['2026-10']['requetes_deposees'])->toBe(1)->and($mois['2026-11']['requetes_cloturees'])->toBe(1);
});

it('ouvre le contrôle de gestion à la direction et au service financier seulement', function (string $role, int $statut): void {
    $this->actingAs(($this->compte)($role))->get(ControleGestionPage::getUrl())->assertStatus($statut);
})->with([['super_admin', 200], ['admin', 200], ['service_financier', 200], ['scolarite_responsable', 403], ['scolarite_agent', 403], ['editor', 403]]);

it('réserve le réglage du délai à settings.manage et remonte les retards dans « À traiter »', function (): void {
    ($this->etat)(EtatPaie::PRIS_EN_CHARGE, '2026-09');
    $financier = ($this->compte)('service_financier');

    expect(fn () => $this->service->definirDelaiRequetes(45, $financier))->toThrow(HttpException::class);

    $this->actingAs($financier);
    expect(collect(ATraiterWidget::elements())->firstWhere('libelle', 'Dossiers en retard'))->toMatchArray(['nombre' => '1', 'groupe' => 'Enseignants et paie']);
    $this->get(ControleGestionPage::getUrl())->assertOk()->assertSee('EP-2026-09-pris')->assertSee('Coûts de l');
});
