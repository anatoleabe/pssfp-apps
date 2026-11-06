<?php

declare(strict_types=1);

use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Models\Versement;
use App\Services\DocumentsAuditeurs\LettreAccesFoadPdf;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\EspaceCandidat\ConsultationEspaceCandidat;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Sms\SmsServiceInterface;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses()->group('scolarite', 'applications');

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->mock(SmsServiceInterface::class)->shouldReceive('send')->andReturnNull();
    $admin = tap(User::factory()->create(['email' => 'adm-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('admin'));
    $this->campagne = CampagneCandidature::factory()->closed()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14,
        'parametres_lettres' => ['date_lettres' => '2026-11-04'],
    ]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $retenu = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Test', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel, 'email' => Str::random(8).'@example.org',
        'ecampus_statut' => ComptesEcampus::CREE,
        'user_id' => User::factory()->create(['phone_e164' => $tel, 'email' => Str::random(8).'@example.org'])->id,
    ]);
    $this->awa = $retenu('Abena', '+237699003001');
    $this->bruno = $retenu('Bekono', '+237699003002');
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $admin);
    $this->awa->refresh();
    $this->bruno->refresh();
    Versement::factory()->create(['inscription_annuelle_id' => $this->awa->auditeur->inscriptionCourante->id, 'montant' => 185000, 'reference' => 'BOR-L1']);

    $this->jeton = fn (Candidature $c, array $abilities = ['profile:read', 'application:read', 'application:create']): string => $c->user->createToken('candidat', $abilities)->plainTextToken;
    $this->url = '/v1/applications/me/acces/lettre';
});

it('remet à l\'auditeur en règle sa lettre d\'accès en PDF, sans cache, et le journalise sans le mot de passe', function (): void {
    $reponse = $this->withToken(($this->jeton)($this->awa))->get($this->url)->assertOk();

    expect($reponse->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($reponse->headers->get('Content-Disposition'))->toContain('acces-foad-'.mb_strtolower((string) $this->awa->matricule).'.pdf')
        ->and($reponse->headers->get('Cache-Control'))->toContain('no-store')
        ->and(substr((string) $reponse->getContent(), 0, 4))->toBe('%PDF');

    $journal = Activity::query()->where('event', 'acces_foad_telecharge')->sole();
    expect($journal->subject_id)->toBe($this->awa->id)
        ->and(json_encode($journal->toArray()))->not->toContain((string) $this->awa->moodle_password);
});

it('met dans la lettre les identifiants de l\'auditeur et la mention (é) du signataire', function (): void {
    $html = app(LettreAccesFoadPdf::class)->html($this->awa);

    expect($html)->toContain((string) $this->awa->moodle_password)
        ->toContain(mb_strtolower((string) $this->awa->matricule))
        ->toContain('ABENA Test')
        ->toContain('(é) Le Président du Comité de Pilotage')
        ->toContain('04 novembre 2026');
});

it('intègre le scan de signature de la promotion, et part sans lui s\'il est illisible', function (): void {
    Storage::fake(CandidatureObjectStorage::DISK);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    Storage::disk(CandidatureObjectStorage::DISK)->put('signatures/p14.png', $png);
    $this->campagne->forceFill(['signature_lettres_path' => 'signatures/p14.png'])->save();
    $this->awa->unsetRelation('campagne');

    expect(app(LettreAccesFoadPdf::class)->html($this->awa))->toContain('data:image/png;base64,'.base64_encode($png));

    $this->campagne->forceFill(['signature_lettres_path' => 'signatures/absente.png'])->save();
    $this->awa->unsetRelation('campagne');
    expect(app(LettreAccesFoadPdf::class)->html($this->awa))->not->toContain('alt="Signature et cachet"')
        ->toContain('(é) Le Président du Comité de Pilotage');
});

it('ne remet à chaque auditeur que sa propre lettre', function (): void {
    Versement::factory()->create(['inscription_annuelle_id' => $this->bruno->auditeur->inscriptionCourante->id, 'montant' => 185000, 'reference' => 'BOR-L2']);

    $reponse = $this->withToken(($this->jeton)($this->bruno))->get($this->url)->assertOk();

    expect($reponse->headers->get('Content-Disposition'))->toContain(mb_strtolower((string) $this->bruno->matricule))
        ->not->toContain(mb_strtolower((string) $this->awa->matricule))
        ->and(Activity::query()->where('event', 'acces_foad_telecharge')->sole()->subject_id)->toBe($this->bruno->id);
    expect(app(LettreAccesFoadPdf::class)->html($this->bruno))->toContain((string) $this->bruno->moodle_password)
        ->not->toContain((string) $this->awa->moodle_password)
        ->not->toContain((string) $this->awa->matricule);
});

it('la refuse tant que l\'inscription n\'est pas soldée', function (): void {
    $this->withToken(($this->jeton)($this->bruno))->get($this->url)
        ->assertForbidden()
        ->assertJsonPath('code', 'acces_indisponible');
});

it('la refuse tant que le compte eCampus est en préparation', function (): void {
    $this->awa->forceFill(['ecampus_statut' => null])->save();

    $this->withToken(($this->jeton)($this->awa))->get($this->url)
        ->assertForbidden()
        ->assertJsonPath('code', 'acces_indisponible');
});

it('annonce la lettre dans la scolarité seulement quand elle peut être produite', function (): void {
    // Plusieurs comptes dans un même test : la garde Sanctum garde sinon le premier utilisateur.
    $scolarite = function (Candidature $c) {
        $this->app['auth']->forgetGuards();

        return $this->withToken(($this->jeton)($c))->getJson('/v1/applications/me/scolarite')->assertOk();
    };

    $scolarite($this->awa)->assertJsonPath('data.acces.lettre_disponible', true);
    $scolarite($this->bruno)->assertJsonMissingPath('data.acces.lettre_disponible');

    $this->campagne->forceFill(['parametres_lettres' => []])->save();
    $scolarite($this->awa)->assertJsonPath('data.acces.lettre_disponible', false);
});

it('la refuse à un agent qui consulte l\'espace', function (): void {
    $jeton = $this->awa->user->createToken(ConsultationEspaceCandidat::NOM_JETON, ConsultationEspaceCandidat::ABILITIES)->plainTextToken;

    $this->withToken($jeton)->get($this->url)->assertForbidden();
    expect(Activity::query()->where('event', 'acces_foad_telecharge')->exists())->toBeFalse();
});

it('répond 404 quand le portail ne connaît pas le mot de passe (auditeur importé)', function (): void {
    $this->awa->forceFill(['moodle_password' => null])->save();

    $this->withToken(($this->jeton)($this->awa))->get($this->url)
        ->assertNotFound()
        ->assertJsonPath('code', 'mot_de_passe_inconnu');
});

it('répond 409 tant que la date des lettres n\'est pas saisie sur la promotion', function (): void {
    $this->campagne->forceFill(['parametres_lettres' => []])->save();

    $this->withToken(($this->jeton)($this->awa))->get($this->url)
        ->assertStatus(409)
        ->assertJsonPath('code', 'lettre_non_prete');
});

it('exige une session', function (): void {
    $this->getJson($this->url)->assertUnauthorized();
});

it('limite les téléchargements à 10 par heure', function (): void {
    $jeton = ($this->jeton)($this->awa);
    foreach (range(1, 10) as $_) {
        $this->withToken($jeton)->get($this->url)->assertOk();
    }

    $this->withToken($jeton)->get($this->url)->assertStatus(429);
});
