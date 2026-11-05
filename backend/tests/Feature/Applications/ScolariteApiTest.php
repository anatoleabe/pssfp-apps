<?php

declare(strict_types=1);

use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\EspaceCandidat\ConsultationEspaceCandidat;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses()->group('scolarite', 'applications');

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->mock(SmsServiceInterface::class)->shouldReceive('send')->andReturnNull();
    $admin = tap(User::factory()->create(['email' => 'adm-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('admin'));
    $this->campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'tranche1_echeance' => '2026-12-15', 'inscription_echeance' => 'Avant la rentrée']);
    $retenu = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'nom' => $nom, 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel, 'email' => Str::random(8).'@example.org',
        'ecampus_statut' => ComptesEcampus::CREE,
        'user_id' => User::factory()->create(['phone_e164' => $tel, 'email' => Str::random(8).'@example.org'])->id,
    ]);
    $this->awa = $retenu('Abena', '+237699002001');
    $this->bruno = $retenu('Bekono', '+237699002002');
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $admin);
    $this->awa->refresh();
    $this->bruno->refresh();
    $this->inscription = $this->awa->auditeur->inscriptionCourante;
    Versement::factory()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 300000, 'reference' => 'BOR-A1', 'date_versement' => '2026-11-02']);
    Versement::factory()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 50000, 'reference' => 'BOR-A2', 'annule_at' => now(), 'annule_motif' => 'Erreur interne']);

    $this->jeton = fn (Candidature $c, array $abilities = ['profile:read', 'application:read', 'application:create']): string => $c->user->createToken('candidat', $abilities)->plainTextToken;
});

it('donne à l\'auditeur sa situation, ses versements valides et son accès', function (): void {
    $reponse = $this->withToken(($this->jeton)($this->awa))->getJson('/v1/applications/me/scolarite')->assertOk();

    $reponse->assertJsonPath('data.matricule', $this->awa->matricule)
        ->assertJsonPath('data.identifiant', mb_strtolower((string) $this->awa->matricule))
        ->assertJsonPath('data.niveau', 'M1')
        ->assertJsonPath('data.annee_academique', '2026-2027')
        ->assertJsonPath('data.promotion_numero', 14)
        ->assertJsonPath('data.du_total', 1185000)
        ->assertJsonPath('data.verse', 300000)
        ->assertJsonPath('data.reste', 885000)
        ->assertJsonPath('data.paliers.0.cle', 'inscription')
        ->assertJsonPath('data.paliers.0.solde', true)
        ->assertJsonPath('data.paliers.0.echeance_texte', 'Avant la rentrée')
        ->assertJsonPath('data.paliers.1.paye', 115000)
        ->assertJsonPath('data.paliers.1.echeance', '2026-12-15')
        ->assertJsonPath('data.acces.etat', 'disponible')
        ->assertJsonCount(1, 'data.versements')
        ->assertJsonPath('data.versements.0.reference', 'BOR-A1')
        ->assertJsonPath('data.versements.0.mode', 'cremincam_agence');

    $json = $reponse->getContent();
    expect($json)->not->toContain((string) $this->awa->moodle_password)
        ->not->toContain('Erreur interne')
        ->not->toContain('saisi_par')
        ->not->toContain('"id"');
});

it('ne montre pas l\'identifiant tant que l\'accès est bloqué', function (): void {
    $this->withToken(($this->jeton)($this->bruno))->getJson('/v1/applications/me/scolarite')
        ->assertOk()
        ->assertJsonPath('data.acces.etat', 'bloque')
        ->assertJsonPath('data.acces.reste_inscription', 185000)
        ->assertJsonPath('data.verse', 0);
});

it('répond 404 sans fiche auditeur, ou pour un dossier retiré', function (): void {
    $sansFiche = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'statut' => Candidature::STATUT_CANDIDAT, 'phone_e164' => '+237699002003', 'email' => 'x@example.org',
        'user_id' => User::factory()->create(['phone_e164' => '+237699002003', 'email' => 'y@example.org'])->id,
    ]);
    $this->withToken(($this->jeton)($sansFiche))->getJson('/v1/applications/me/scolarite')->assertNotFound();

    $this->awa->forceFill(['withdrawn_at' => now()])->save();
    $this->withToken(($this->jeton)($this->awa))->getJson('/v1/applications/me/scolarite')->assertNotFound();
});

it('exige une session', function (): void {
    $this->getJson('/v1/applications/me/scolarite')->assertUnauthorized();
    $this->postJson('/v1/applications/me/acces/reveler')->assertUnauthorized();
    $this->getJson('/v1/applications/me/releve-scolarite')->assertUnauthorized();
});

it('révèle le mot de passe à l\'auditeur à jour, sans cache et en le journalisant sans le copier', function (): void {
    Log::spy();
    $reponse = $this->withToken(($this->jeton)($this->awa))->postJson('/v1/applications/me/acces/reveler')->assertOk();

    $reponse->assertJsonPath('data.mot_de_passe', $this->awa->moodle_password)
        ->assertJsonPath('data.identifiant', mb_strtolower((string) $this->awa->matricule));
    expect($reponse->headers->get('Cache-Control'))->toContain('no-store');

    $journal = Activity::query()->where('event', 'acces_mot_de_passe_affiche')->sole();
    expect(json_encode($journal->toArray()))->not->toContain((string) $this->awa->moodle_password)
        ->and($journal->subject_id)->toBe($this->awa->id);
});

it('le refuse à un auditeur dont l\'inscription n\'est pas soldée', function (): void {
    $this->withToken(($this->jeton)($this->bruno))->postJson('/v1/applications/me/acces/reveler')
        ->assertForbidden()
        ->assertJsonMissingPath('data.mot_de_passe');
});

it('le refuse à un agent qui consulte l\'espace', function (): void {
    $jeton = $this->awa->user->createToken(ConsultationEspaceCandidat::NOM_JETON, ConsultationEspaceCandidat::ABILITIES)->plainTextToken;

    $this->withToken($jeton)->getJson('/v1/applications/me/scolarite')->assertOk()->assertJsonPath('data.verse', 300000);
    $this->withToken($jeton)->postJson('/v1/applications/me/acces/reveler')->assertForbidden();
});

it('l\'accepte pour une connexion par matricule (lecture seule)', function (): void {
    $this->withToken(($this->jeton)($this->awa, ['profile:read', 'application:read']))
        ->postJson('/v1/applications/me/acces/reveler')->assertOk();
});

it('limite les demandes à 10 par heure', function (): void {
    $jeton = ($this->jeton)($this->awa);
    foreach (range(1, 10) as $_) {
        $this->withToken($jeton)->postJson('/v1/applications/me/acces/reveler')->assertOk();
    }

    $this->withToken($jeton)->postJson('/v1/applications/me/acces/reveler')->assertStatus(429);
});

it('n\'expose jamais le mot de passe dans /me', function (): void {
    $this->withToken(($this->jeton)($this->awa))->getJson('/v1/applications/me')
        ->assertOk()
        ->assertDontSee((string) $this->awa->moodle_password);
});

it('produit le relevé PDF de l\'auditeur, sans mot de passe', function (): void {
    $reponse = $this->withToken(($this->jeton)($this->awa))->get('/v1/applications/me/releve-scolarite')->assertOk();

    expect($reponse->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($reponse->headers->get('Content-Disposition'))->toContain('releve-scolarite')
        ->and($reponse->headers->get('Cache-Control'))->toContain('no-store')
        ->and(substr((string) $reponse->getContent(), 0, 4))->toBe('%PDF');
});
