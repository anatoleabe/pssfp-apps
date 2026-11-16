<?php

declare(strict_types=1);

use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\RecuVersement;
use App\Models\RubriqueFrais;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\CorrectionsVersements;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\VerificationRecus;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * Reçu A4 en deux exemplaires, R1 : ventilation par palier figée à l'émission, QR code de vérification, page publique
 * minimale, écriture d'annulation au même format. Données fictives.
 */

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('app.url', 'https://api.pssfp.org');
    Mail::fake();
    $this->mock(SmsServiceInterface::class, fn ($mock) => $mock->shouldReceive('send')->andReturnNull());
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->financier = ($this->compte)('service_financier');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());
    // Ventilation par palier : sans rubriques de frais (R2), le reçu retombe sur les paliers.
    RubriqueFrais::query()->delete();
    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    Candidature::factory()->forCampagne($campagne)->submitted()->create(['nom' => 'Essai', 'prenom' => 'Auditeur Fictif', 'statut' => Candidature::STATUT_ACCEPTE,
        'phone_e164' => '+2376990'.random_int(10000, 99999), 'email' => 'essai-'.Str::random(6).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE]);
    app(AttributionMatricules::class)->attribuer($campagne, '26', ($this->compte)('admin'));
    $this->inscription = Candidature::query()->where('nom', 'Essai')->firstOrFail()->auditeur->inscriptionCourante;
    $this->encaisser = fn (int $montant, string $reference): RecuVersement => app(RegistreVersements::class)->encaisser($this->inscription->fresh(), [
        'montant' => $montant, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence', 'reference' => $reference, 'payeur' => 'auditeur',
    ], $this->financier)->recu;
    $this->droits = (int) $this->inscription->montant_inscription;
    $this->t1 = (int) $this->inscription->montant_tranche1;
    $this->t2 = (int) $this->inscription->montant_tranche2;
});

describe('ventilation figée à l\'émission', function (): void {
    it('répartit chaque versement sur les paliers, dans l\'ordre, puis l\'avance', function (): void {
        $premier = ($this->encaisser)($this->droits, 'BOR-1');
        $second = ($this->encaisser)($this->t1 + 20000, 'BOR-2');

        expect($premier->ventilation)->toBe([
            ['libelle' => 'Droits d\'inscription', 'code' => null, 'du' => $this->droits, 'ce_versement' => $this->droits, 'total_verse' => $this->droits, 'reste' => 0],
            ['libelle' => '1re tranche', 'code' => null, 'du' => $this->t1, 'ce_versement' => 0, 'total_verse' => 0, 'reste' => $this->t1],
            ['libelle' => '2e tranche', 'code' => null, 'du' => $this->t2, 'ce_versement' => 0, 'total_verse' => 0, 'reste' => $this->t2],
        ])
            ->and(array_column($second->ventilation, 'ce_versement'))->toBe([0, $this->t1, 20000])
            ->and(array_sum(array_column($second->ventilation, 'ce_versement')))->toBe($second->montant)
            ->and(array_sum(array_column($second->ventilation, 'total_verse')))->toBe($second->total_verse);

        $avance = ($this->encaisser)($this->t2, 'BOR-3');
        expect(collect($avance->ventilation)->last())->toBe(['libelle' => 'Avance sur la scolarité à venir', 'code' => null, 'du' => null, 'ce_versement' => 20000, 'total_verse' => null, 'reste' => null])
            ->and(array_sum(array_column($avance->ventilation, 'ce_versement')))->toBe($avance->montant);
    });

    it('montre le dû après remise, retirée de la dernière tranche', function (): void {
        app(RegistreVersements::class)->accorderRemise($this->inscription, 100000, 'Bourse partielle', ($this->compte)('scolarite_responsable'));
        $recu = ($this->encaisser)($this->droits, 'BOR-REM');

        expect(array_column($recu->ventilation, 'du'))->toBe([$this->droits, $this->t1, $this->t2 - 100000]);
    });

    it('reste immuable : la base refuse de modifier la ventilation', function (): void {
        $recu = ($this->encaisser)($this->droits, 'BOR-IMM');
        DB::beginTransaction();
        try {
            expect(fn () => DB::table('recus_versements')->where('id', $recu->id)->update(['ventilation' => '[]']))->toThrow(QueryException::class, 'immuable');
        } finally {
            DB::rollBack();
        }
        $migration = require database_path('migrations/2026_12_12_100100_add_ventilation_to_recus_versements.php');
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'ventilation');
    });
});

describe('rendu A4', function (): void {
    it('rend deux exemplaires, le QR code, la ventilation, sans signature de l\'auditeur, en A4 portrait', function (): void {
        $recu = ($this->encaisser)($this->droits, 'BOR-PDF');
        $recus = app(RecusVersements::class);
        $html = view('pdf.recu-versement', $recus->donneesPdf($recu))->render();

        expect(substr_count($html, 'EXEMPLAIRE DE L'))->toBe(2)
            ->and($html)->toContain('EXEMPLAIRE DE L&#039;AUDITEUR')->toContain('EXEMPLAIRE DE L&#039;ADMINISTRATION')
            ->toContain('data:image/svg+xml;base64,')->toContain('data:image/png;base64,')
            ->toContain('https://api.pssfp.org/recus/verifier/'.$recu->uuid)
            ->toContain('Droits d&#039;inscription')->toContain('Cachet et signature du service financier')
            ->not->toContain('Signature de l&#039;auditeur')
            ->and($recus->pdf($recu))->toStartWith('%PDF')->toContain('595.28');
    });

    it('affiche un reçu émis avant la ventilation avec sa seule ligne « objet »', function (): void {
        $recu = ($this->encaisser)($this->droits, 'BOR-ANCIEN');
        $ancien = (clone $recu)->forceFill(['ventilation' => null]);
        $html = view('pdf.recu-versement', app(RecusVersements::class)->donneesPdf($ancien))->render();

        expect($html)->toContain(e($recu->objet))->not->toContain('1re tranche');
    });

    it('garde les mentions d\'un reçu annulé et remplacé, et rend l\'écriture d\'annulation en A4', function (): void {
        $recu = ($this->encaisser)($this->droits, 'BOR-AVANT');
        $c = app(CorrectionsVersements::class)->rectifier(Versement::query()->findOrFail($recu->versement_id),
            ['categorie' => 'reference', 'reference' => 'BOR-APRES'], 'Faute de frappe sur la référence du bordereau', $this->financier);
        $recus = app(RecusVersements::class);

        $annule = view('pdf.recu-versement', $recus->donneesPdf($recu->fresh()))->render();
        $nouveau = view('pdf.recu-versement', $recus->donneesPdf($c->recuRemplacant))->render();
        $annulation = $recu->fresh()->annulation;

        expect($annule)->toContain('ANNULÉ — écriture '.$recu->numero.'-A')->toContain('remplacé par '.$c->recuRemplacant->numero)
            ->and($nouveau)->toContain('Remplace le reçu '.$recu->numero)->toContain($c->numero)
            ->and(view('pdf.annulation-recu', $recus->donneesPdfAnnulation($annulation))->render())->toContain('ANNULATION DE REÇU')->toContain('EXEMPLAIRE DE L&#039;ADMINISTRATION')
            ->and($recus->pdfAnnulation($annulation))->toStartWith('%PDF')->toContain('595.28');

        // Un exemplaire par demi-page : chaque pièce tient sur une seule page A4, même annulée et remplacée.
        foreach ([$recus->pdf($recu->fresh()), $recus->pdf($c->recuRemplacant), $recus->pdfAnnulation($annulation)] as $pdf) {
            expect($pdf)->toContain('/Count 1')->not->toContain('/Count 2');
        }
    });
});

describe('page publique de vérification', function (): void {
    it('montre le minimum, masque le nom et le matricule, et n\'est pas indexée', function (): void {
        $recu = ($this->encaisser)($this->droits, 'BOR-VERIF');

        $page = $this->get('/recus/verifier/'.$recu->uuid)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Reçu valide')->assertSee($recu->numero)->assertSee('185 000 FCFA')->assertSee('2026-2027')
            ->assertSee(VerificationRecus::initiales($recu->auditeur_nom))->assertSee(VerificationRecus::masquer($recu->matricule))
            ->assertSee('noindex', false);
        expect($page->getContent())->not->toContain($recu->matricule)->not->toContain('Essai')->not->toContain('BOR-VERIF');
    });

    it('dit qu\'un reçu est annulé et par quoi il est remplacé', function (): void {
        $recu = ($this->encaisser)($this->droits, 'BOR-AV');
        $c = app(CorrectionsVersements::class)->rectifier(Versement::query()->findOrFail($recu->versement_id),
            ['categorie' => 'reference', 'reference' => 'BOR-AP'], 'Faute de frappe sur la référence du bordereau', $this->financier);

        $this->get('/recus/verifier/'.$recu->uuid)->assertOk()->assertSee('Reçu annulé le 10/11/2026')->assertSee('remplacé par le reçu '.$c->recuRemplacant->numero);
    });

    it('répond 404 à un identifiant inconnu ou mal formé, et limite le débit', function (): void {
        $this->get('/recus/verifier/'.Str::uuid())->assertNotFound()->assertSee('Aucun reçu ne correspond');
        $this->get('/recus/verifier/pas-un-uuid')->assertNotFound();

        foreach (range(1, 18) as $k) {
            $this->get('/recus/verifier/'.Str::uuid());
        }
        $this->get('/recus/verifier/'.Str::uuid())->assertStatus(429);
    });

    it('n\'ouvre aucune session : ni cookie de session ni jeton CSRF', function (): void {
        $recu = ($this->encaisser)($this->droits, 'BOR-SANS-SESSION');

        $reponse = $this->get('/recus/verifier/'.$recu->uuid)->assertOk();
        $cookies = array_map(fn ($c): string => $c->getName(), $reponse->headers->getCookies());
        expect($cookies)->not->toContain(config('session.cookie'))->not->toContain('XSRF-TOKEN');

        // Témoin : une page web ordinaire, elle, ouvre une session.
        $ordinaire = array_map(fn ($c): string => $c->getName(), $this->get('/')->headers->getCookies());
        expect($ordinaire)->toContain(config('session.cookie'));
    });

    it('masque comme convenu', function (): void {
        expect(VerificationRecus::initiales('ESSAI Auditeur Fictif'))->toBe('E. A. F.')
            ->and(VerificationRecus::masquer('26EFP999'))->toBe('26****99')
            ->and(VerificationRecus::masquer('ABC'))->toBe('***');
    });
});
