<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * Reçu A4, pire cas de mise en page (relecture du 17/11/2026) : reçu annulé, cinq rubriques, une ligne
 * « autres années » et une ligne d'avance. Chaque exemplaire a une hauteur fixe (overflow: hidden) : un contenu trop
 * haut serait coupé sans bruit. Le test retire cette hauteur : tout contenu trop haut se voit alors en page 2.
 */

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('app.url', 'https://api.pssfp.org');
    Mail::fake();
    $this->mock(SmsServiceInterface::class, fn ($mock) => $mock->shouldReceive('send')->andReturnNull());
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());
    $financier = tap(User::factory()->create(['email' => 'fin-'.Str::random(6).'@pssfp.local', 'name' => 'Agent service financier']), fn (User $u) => $u->assignRole('service_financier'));
    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2025-2026'], 'promotion_numero' => 13]);
    $dossier = Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => 'ESSAI-LONGUE-FAMILLE', 'prenom' => 'Auditeur Fictif Aux Prénoms Nombreux', 'statut' => Candidature::STATUT_ACCEPTE,
        'phone_e164' => '+2376990'.random_int(10000, 99999), 'email' => Str::random(8).'@example.org',
    ]);
    $dossier->forceFill(['matricule' => '25EFP901'])->save();
    $auditeur = Auditeur::query()->create(['candidature_id' => $dossier->id]);
    foreach (['2025-2026', '2026-2027'] as $annee) {
        $b = BaremeScolarite::factory()->create(['annee_academique' => $annee, 'tranche1_echeance' => '2027-01-01', 'tranche2_echeance' => '2027-03-01']);
        InscriptionAnnuelle::query()->create([
            'auditeur_id' => $auditeur->id, 'bareme_id' => $b->id, 'annee_academique' => $annee, 'niveau' => 'M1',
            'montant_inscription' => $b->montant_inscription, 'montant_tranche1' => $b->montant_tranche1, 'montant_tranche2' => $b->montant_tranche2,
        ]);
    }
    // Arriéré 2025-2026, toute l'année 2026-2027, et 50 000 d'avance : 5 rubriques + autres années + avance.
    $this->recu = app(RegistreVersements::class)->encaisser($auditeur->fresh()->inscriptionCourante, [
        'montant' => 2 * 1185000 + 50000, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence',
        'reference' => 'BOR-PIRE-CAS-AVEC-UNE-REFERENCE-TRES-LONGUE-0001', 'payeur' => 'auditeur',
    ], $financier)->recu;
    // Un versement sur deux années ne se rectifie pas : son reçu s'annule seul, ce qui pose le bandeau « ANNULÉ ».
    app(RecusVersements::class)->annuler($this->recu, 'Reçu remis par erreur à un tiers', tap(User::factory()->create(['email' => 'admin-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole('admin')));
});

it('tient chaque exemplaire du pire cas dans sa demi-page, sans rien couper', function (): void {
    $recus = app(RecusVersements::class);
    $annule = $this->recu->fresh();
    expect(count($annule->ventilation ?? []))->toBeGreaterThanOrEqual(7);

    expect($annule->annulation)->not->toBeNull();

    foreach ([$annule] as $recu) {
        $html = view('pdf.recu-versement', $recus->donneesPdf($recu))->render();
        expect($html)->toContain('.exemplaire { height: 141mm; overflow: hidden; }');
        $libre = str_replace('.exemplaire { height: 141mm; overflow: hidden; }', '.exemplaire { }', $html);

        $pages = fn (string $pdf): int => preg_match('#/Type /Pages\s.*?/Count (\d+)#s', $pdf, $m) === 1 ? (int) $m[1] : 0;
        expect($pages(Pdf::loadHTML($libre)->setPaper('a4', 'portrait')->output()))->toBe(1)
            ->and($pages($recus->pdf($recu)))->toBe(1);
    }
});
