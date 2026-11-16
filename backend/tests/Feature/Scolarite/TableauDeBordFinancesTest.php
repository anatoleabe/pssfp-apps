<?php

declare(strict_types=1);

use App\Filament\Pages\Scolarite\TableauDeBordFinances;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\CriteresFinances;
use App\Services\Scolarite\CriteresListe;
use App\Services\Scolarite\IndicateursFinanciers;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\ListesAuditeurs;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * Tableau de bord de l'espace Finances, sous-lot F2 (spec module-4-scolarite-espace-financier §5) : un seul calcul. Les
 * totaux égalent la somme des situations des fiches ; les effectifs égalent les listes du lot C et les filtres de la liste
 * des auditeurs.
 */

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Mail::fake();
    $this->mock(SmsServiceInterface::class, fn ($mock) => $mock->shouldReceive('send')->andReturnNull());
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->financier = ($this->compte)('service_financier');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());

    // 1re tranche échue le 01/11 (9 jours de retard le 10/11), 2e tranche à venir.
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'tranche1_echeance' => '2026-11-01', 'tranche2_echeance' => '2027-03-01']);
    $this->p14 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    $this->dossiers = collect(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'])->mapWithKeys(fn (string $nom): array => [$nom => Candidature::factory()->forCampagne($this->p14)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+2376990'.random_int(10000, 99999),
        'email' => mb_strtolower($nom).'-'.Str::random(6).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE,
    ])]);
    app(AttributionMatricules::class)->attribuer($this->p14, '26', ($this->compte)('admin'));
    $this->inscription = fn (string $nom): InscriptionAnnuelle => $this->dossiers[$nom]->fresh()->auditeur->inscriptionCourante;
    $this->verser = fn (string $nom, int $montant, bool $confirme = true) => tap(app(RegistreVersements::class)->encaisser(($this->inscription)($nom), [
        'montant' => $montant, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence', 'reference' => 'BOR-'.Str::random(8), 'payeur' => 'auditeur',
    ], $this->financier)->versement, fn ($v) => $confirme ? confirmerVersement($v) : null);

    $droits = (int) ($this->inscription)('Alpha')->montant_inscription;
    $t1 = (int) ($this->inscription)('Alpha')->montant_tranche1;
    $t2 = (int) ($this->inscription)('Alpha')->montant_tranche2;
    ($this->verser)('Alpha', $droits + $t1);                 // à jour : la 2e tranche n'est pas échue
    ($this->verser)('Beta', 50000, false);                   // inscription non soldée, à confirmer
    ($this->verser)('Gamma', $droits + $t1, false);          // à jour sur tout l'argent, non soldée faute de confirmation
    app(RegistreVersements::class)->accorderRemise(($this->inscription)('Delta'), 100000, 'Bourse partielle', $this->responsable);
    ($this->verser)('Delta', $droits + $t1 + $t2 - 100000);  // soldé
    ($this->verser)('Epsilon', $droits);                     // en retard : 1re tranche échue impayée
    $this->droits = $droits;
    $this->t1 = $t1;
    $this->svc = app(IndicateursFinanciers::class);
    $this->tout = fn (array $c = []): array => $this->svc->indicateurs($this->svc->lignes(CriteresFinances::depuis($c + ['annee' => '2026-2027'])));
});

it('donne des totaux égaux à la somme des situations des fiches', function (): void {
    $fiches = InscriptionAnnuelle::query()->get()->map(fn (InscriptionAnnuelle $i) => $i->situation());
    $ind = ($this->tout)();

    expect($ind['du'])->toBe($fiches->sum('duTotal'))
        ->and($ind['verse'])->toBe($fiches->sum('verse'))
        ->and($ind['a_confirmer'])->toBe($fiches->sum('aConfirmer'))
        ->and($ind['reste'])->toBe($fiches->sum('reste'))
        ->and($ind['remises'])->toBe($fiches->sum('remise'))
        ->and($ind['du_brut'])->toBe($ind['du'] + $ind['remises'])
        ->and($ind['reste'])->toBe($ind['du'] - $ind['verse'])
        ->and($ind['confirme'])->toBe($ind['verse'] - $ind['a_confirmer'])
        ->and($ind['taux'])->toBe(round(100 * $ind['verse'] / $ind['du'], 1))
        ->and($ind['inscrits'])->toBe(5);
});

it('donne des effectifs égaux aux listes du lot C et aux filtres de la liste des auditeurs', function (): void {
    $ind = ($this->tout)();
    $liste = fn (string $situation): int => app(ListesAuditeurs::class)->effectif(CriteresListe::depuis([
        'campagne_id' => $this->p14->id, 'situation' => $situation, 'statuts' => array_keys(Auditeur::STATUTS),
    ]));
    $scope = fn (Closure $f): int => $f(InscriptionAnnuelle::query())->count();

    expect($ind['soldes'])->toBe($liste(CriteresListe::SOLDE))->toBe(1)
        ->and($ind['en_retard'])->toBe($liste(CriteresListe::EN_RETARD))->toBe(1)
        ->and($ind['inscrits'] - $ind['soldes'])->toBe($liste(CriteresListe::NON_SOLDE))
        ->and($ind['inscrits'])->toBe($liste(CriteresListe::TOUTES))
        ->and($ind['non_soldes'])->toBe($scope(fn ($q) => $q->inscriptionNonSoldee()))->toBe(2)
        ->and($ind['a_jour'])->toBe($scope(fn ($q) => $q->inscriptionSoldee()->whereNot(fn ($r) => $r->enRetard())->whereNot(fn ($r) => $r->entierementSoldee())))->toBe(1)
        ->and($ind['faute_confirmation'])->toBe(1)
        ->and($ind['avec_a_confirmer'])->toBe(2)
        ->and($ind['acces_ouvert'])->toBe($scope(fn ($q) => $q->accesOuvert()));

    $categories = InscriptionAnnuelle::query()->get()->map(fn (InscriptionAnnuelle $i): string => LibelleSituation::cle($i))->countBy();
    expect($ind['a_jour'])->toBe($categories[LibelleSituation::A_JOUR] ?? 0)
        ->and($ind['non_soldes'])->toBe($categories[LibelleSituation::NON_SOLDEE] ?? 0);
});

it('calcule l\'exigible, l\'impayé échu et son ancienneté', function (): void {
    $lignes = $this->svc->lignes(CriteresFinances::depuis(['annee' => '2026-2027']));
    $epsilon = $lignes->firstWhere('nom', 'Epsilon');
    $anciennete = $this->svc->anciennete($lignes);

    expect($epsilon['exigible'])->toBe($this->droits + $this->t1)
        ->and($epsilon['impaye_echu'])->toBe($this->t1)
        ->and($epsilon['anciennete'])->toBe('1_30')
        ->and($anciennete['1_30']['montant'])->toBe($lignes->sum('impaye_echu'))
        // Alpha et Gamma ne doivent plus que la 2e tranche, non échue.
        ->and($anciennete['non_echu']['auditeurs'])->toBe(2)
        ->and(($this->tout)()['impaye_echu'])->toBe($lignes->sum('impaye_echu'));
});

it('restreint le périmètre par promotion, niveau et statut', function (): void {
    expect(($this->tout)(['niveau' => 'M2'])['inscrits'])->toBe(0)
        ->and(($this->tout)(['campagne_id' => $this->p14->id])['inscrits'])->toBe(5)
        ->and(($this->tout)(['statuts' => ['abandon']])['inscrits'])->toBe(0);
});

it('ventile par dimension et suit les mois de Yaoundé', function (): void {
    $lignes = $this->svc->lignes(CriteresFinances::depuis(['annee' => '2026-2027']));
    $parNiveau = $this->svc->ventilation($lignes, ['niveau']);
    $mensuel = $this->svc->mensuel($lignes);

    expect($parNiveau)->toHaveCount(1)
        ->and($parNiveau[0]['indicateurs']['du'])->toBe(($this->tout)()['du'])
        ->and(end($mensuel)['mois'])->toBe('2026-11')
        ->and(end($mensuel)['encaisse'])->toBe(Versement::query()->whereNull('annule_at')->sum('montant') + 0)
        ->and($mensuel)->toHaveCount(IndicateursFinanciers::MOIS);
});

it('s\'ouvre à l\'espace Finances et s\'affiche', function (): void {
    $this->actingAs($this->financier);
    expect(TableauDeBordFinances::canAccess())->toBeTrue();
    $this->livewire(TableauDeBordFinances::class)->assertOk()->assertSee('Total dû')->assertSee('Ancienneté des impayés')
        ->set('niveau', 'M2')->assertSee('Inscrits');

    $this->actingAs(($this->compte)('scolarite_agent'));
    expect(TableauDeBordFinances::canAccess())->toBeFalse();
});
