<?php

declare(strict_types=1);

use App\Filament\Resources\VersementResource\Pages\ListVersements;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CorrectionVersement;
use App\Models\ImputationVersement;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use App\Services\Scolarite\CorrectionsVersements;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use App\Services\Supervision\Controles\VerCorrections;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Rectification immédiate par le service financier seul, sous-lot F5 (décision Q2 du 16/11/2026) : erreur descriptive,
 * montant et auditeur égaux, versement non confirmé, moins de 48 heures. Même pièce que le circuit, sans demande.
 */

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    Mail::fake();
    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[] = $texte;
        });
    });
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->financier = ($this->compte)('service_financier');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());
    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    foreach (['Alpha', 'Beta'] as $nom) {
        Candidature::factory()->forCampagne($campagne)->submitted()->create(['nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE,
            'phone_e164' => '+2376990'.random_int(10000, 99999), 'email' => mb_strtolower($nom).'-'.Str::random(6).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE]);
    }
    app(AttributionMatricules::class)->attribuer($campagne, '26', ($this->compte)('admin'));
    $this->a = Candidature::query()->where('nom', 'Alpha')->firstOrFail();
    $this->b = Candidature::query()->where('nom', 'Beta')->firstOrFail();
    $this->v = app(RegistreVersements::class)->encaisser($this->a->auditeur->inscriptionCourante, ['montant' => 100000, 'date_versement' => '2026-11-09',
        'mode' => 'cremincam_agence', 'reference' => 'BOR-FAUTE', 'payeur' => 'auditeur'], $this->financier)->versement;
    $this->rectifier = fn (array $saisie, ?Versement $v = null): CorrectionVersement => app(CorrectionsVersements::class)
        ->rectifier($v ?? $this->v, $saisie, 'Faute de frappe sur la référence du bordereau', $this->financier);
});

it('rectifie seul une erreur descriptive : même pièce que le circuit, sans demande, reçu lié et avis', function (): void {
    $ancien = $this->v->recuEnVigueur()->numero;
    $avant = ImputationVersement::query()->whereNull('annule_at')->sum('montant');
    $this->sms = [];

    $c = ($this->rectifier)(['categorie' => 'reference', 'reference' => 'BOR-JUSTE']);

    expect($c->rectification_immediate)->toBeTrue()
        ->and($c->demande_id)->toBeNull()
        ->and($c->demandee_par)->toBe($this->financier->id)
        ->and($c->executee_par)->toBe($this->financier->id)
        ->and($c->versementRemplacant->reference)->toBe('BOR-JUSTE')
        ->and($c->recuRemplacant->remplace->numero)->toBe($ancien)
        ->and((int) ImputationVersement::query()->whereNull('annule_at')->sum('montant'))->toBe((int) $avant)
        ->and($this->sms[0])->toContain('remplace le recu '.$ancien);
    $this->artisan('scolarite:verifier-comptes')->assertSuccessful();
});

it('refuse le montant, l\'auditeur, un versement confirmé, trop ancien ou visé par une demande', function (): void {
    expect(fn () => ($this->rectifier)(['categorie' => 'montant', 'montant' => 90000]))->toThrow(InvalidArgumentException::class, 'passe par une demande')
        ->and(fn () => ($this->rectifier)(['categorie' => 'auditeur', 'auditeur_id' => $this->b->auditeur->id]))->toThrow(InvalidArgumentException::class, 'passe par une demande');

    $confirme = app(RegistreVersements::class)->encaisser($this->b->auditeur->inscriptionCourante, ['montant' => 50000, 'date_versement' => '2026-11-09',
        'mode' => 'virement', 'reference' => 'VIR-B', 'payeur' => 'auditeur'], $this->financier)->versement;
    confirmerVersement($confirme);
    expect(fn () => ($this->rectifier)(['categorie' => 'reference', 'reference' => 'VIR-B2'], $confirme))->toThrow(InvalidArgumentException::class, 'confirmé');

    app(Demandes::class)->soumettre($this->financier, TypesDemande::CORRIGER_VERSEMENT, $this->v->id, 'Erreur de saisie au guichet du service financier', null,
        ['nature' => 'annulation', 'categorie' => 'doublon']);
    expect(fn () => ($this->rectifier)(['categorie' => 'reference', 'reference' => 'BOR-X']))->toThrow(InvalidArgumentException::class, 'déjà demandée');
});

it('ferme la fenêtre après 48 heures, réglable', function (): void {
    $this->travel(49)->hours();
    expect(fn () => ($this->rectifier)(['categorie' => 'reference', 'reference' => 'BOR-TARD']))->toThrow(InvalidArgumentException::class, '48 heures');

    AppSettings::set(CorrectionsVersements::CLE_FENETRE, '72');
    expect(($this->rectifier)(['categorie' => 'reference', 'reference' => 'BOR-TARD'])->rectification_immediate)->toBeTrue();
});

it('n\'est ouverte qu\'au service financier, depuis la liste des versements', function (): void {
    expect(fn () => app(CorrectionsVersements::class)->rectifier($this->v, ['categorie' => 'reference', 'reference' => 'BOR-R'], 'Faute de frappe sur la référence', ($this->compte)('scolarite_responsable')))
        ->toThrow(HttpException::class);

    $this->actingAs($this->financier);
    $this->livewire(ListVersements::class)
        ->assertTableActionVisible('rectifier', $this->v)
        ->callTableAction('rectifier', $this->v, data: ['categorie' => 'mode', 'mode' => 'virement', 'motif' => 'Mode de paiement mal choisi au guichet'])
        ->assertHasNoTableActionErrors();
    expect(CorrectionVersement::query()->sole()->versementRemplacant->mode)->toBe('virement');

    $this->actingAs(($this->compte)('scolarite_responsable'));
    $this->livewire(ListVersements::class)->assertTableActionHidden('rectifier', CorrectionVersement::query()->sole()->versementRemplacant);
});

it('compte les rectifications dans le seuil du contrôle VER_CORRECTIONS', function (): void {
    ($this->rectifier)(['categorie' => 'reference', 'reference' => 'BOR-1']);
    // Seuil à 1 : deux rectifications du même compte le dépassent.
    AppSettings::set(VerCorrections::CLE_SEUIL, '1');
    ($this->rectifier)(['categorie' => 'reference', 'reference' => 'BOR-2'], CorrectionVersement::query()->sole()->versementRemplacant);

    expect(app(VerCorrections::class)->cas())->toHaveKey('compte:'.$this->financier->id);
});
