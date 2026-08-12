<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses()->group('applications', 'diplome-requis');

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ]);

    $this->campagne = CampagneCandidature::factory()->create([
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);
});

it('crée les nouvelles lignes avec form_version 2', function (): void {
    $candidature = Candidature::factory()->create(['campagne_id' => $this->campagne->id]);

    expect($candidature->refresh()->form_version)->toBe(2);
});

it('accepte les nouveaux champs et les blocs JSONB', function (): void {
    $candidature = Candidature::factory()->create([
        'campagne_id' => $this->campagne->id,
        'diplome_requis' => 'licence-bachelor',
        'annee_diplome_requis' => 2018,
        'domaine_diplome_requis' => 'droit',
        'institut_diplome_requis' => 'Université de Yaoundé II',
        'autres_diplomes' => [
            ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019],
        ],
        'formations_professionnelles' => [
            ['centre' => 'ISMP', 'qualification' => 'Certificat', 'annee' => 2021],
        ],
    ]);

    $fresh = $candidature->refresh();

    // `toEqual` et non `toBe` : PostgreSQL jsonb réordonne les clés d'un objet
    // (longueur puis ordre binaire). L'ordre n'est donc jamais garanti au
    // relecture — seul le contenu l'est, et c'est tout ce dont le front dépend.
    expect($fresh->diplome_requis)->toBe('licence-bachelor')
        ->and($fresh->annee_diplome_requis)->toBe(2018)
        ->and($fresh->autres_diplomes)->toEqual([
            ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019],
        ])
        ->and($fresh->formations_professionnelles[0]['centre'])->toBe('ISMP');
});

it('refuse un slug de diplôme requis hors liste', function (): void {
    expect(fn () => Candidature::factory()->create([
        'campagne_id' => $this->campagne->id,
        'diplome_requis' => 'doctorat',
    ]))->toThrow(QueryException::class);
});

it('refuse un slug de domaine hors liste', function (): void {
    expect(fn () => Candidature::factory()->create([
        'campagne_id' => $this->campagne->id,
        'domaine_diplome_requis' => 'informatique',
    ]))->toThrow(QueryException::class);
});

it('tolère les nouvelles colonnes à null', function (): void {
    $candidature = Candidature::factory()->create(['campagne_id' => $this->campagne->id]);

    expect($candidature->diplome_requis)->toBeNull()
        ->and($candidature->autres_diplomes)->toBeNull();
});

it('expose les libellés de référence en configuration', function (): void {
    expect(array_keys((array) config('diplome_requis')))->toBe(['licence-bachelor', 'master'])
        ->and(array_keys((array) config('domaines_diplome')))->toBe(['droit', 'economie', 'gestion', 'autres']);
});

it('applique le défaut 2 au niveau du schéma, pas du modèle', function (): void {
    $id = DB::table('candidatures')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'numero_dossier' => 'TEST-'.uniqid(),
        'campagne_id' => $this->campagne->id,
        'phone_e164' => '+237691000111',
        'phone_country' => 'CM',
        'nom' => 'Test',
        'prenom' => 'Direct',
        'date_naissance' => '1990-01-01',
        'statut' => 'postulant',
        'frais_paye' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('candidatures')->where('id', $id)->value('form_version'))->toBe(2);
});

function authedCandidatDiplome(): array
{
    $user = User::factory()->candidat()->create([
        'phone_e164' => '+237691'.fake()->numerify('######'),
        'phone_country' => 'CM',
        'date_naissance' => '1990-06-15',
    ]);
    $token = $user->createToken('candidat', [
        'profile:read', 'profile:write', 'application:create',
        'application:read', 'application:submit',
    ])->plainTextToken;

    return [$user, $token];
}

it('enregistre les nouveaux champs via PUT /v1/applications/me', function (): void {
    [, $token] = authedCandidatDiplome();

    $response = $this->withToken($token)->putJson('/v1/applications/me', [
        'diplome_requis' => 'master',
        'annee_diplome_requis' => 2020,
        'domaine_diplome_requis' => 'gestion',
        'institut_diplome_requis' => 'Université de Douala',
        'autres_diplomes' => [
            ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.diplome_requis', 'master')
        ->assertJsonPath('data.annee_diplome_requis', 2020)
        ->assertJsonPath('data.domaine_diplome_requis', 'gestion')
        ->assertJsonPath('data.institut_diplome_requis', 'Université de Douala')
        ->assertJsonPath('data.autres_diplomes.0.intitule', 'DESS')
        ->assertJsonPath('data.form_version', 2);
});

it('renvoie un tableau vide et non null pour les blocs jamais renseignés', function (): void {
    [, $token] = authedCandidatDiplome();

    $this->withToken($token)->putJson('/v1/applications/me', ['nom' => 'Ndongo'])->assertOk();

    $this->withToken($token)->getJson('/v1/applications/me')
        ->assertOk()
        ->assertJsonPath('data.autres_diplomes', [])
        ->assertJsonPath('data.formations_professionnelles', []);
});

it('rejette un slug de diplôme requis inconnu', function (): void {
    [, $token] = authedCandidatDiplome();

    $this->withToken($token)->putJson('/v1/applications/me', ['diplome_requis' => 'doctorat'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('diplome_requis');
});

it('tolère une ligne incomplète au PUT pour ne pas casser l’auto-save', function (): void {
    [, $token] = authedCandidatDiplome();

    $this->withToken($token)->putJson('/v1/applications/me', [
        'autres_diplomes' => [['intitule' => 'DE', 'etablissement' => '', 'annee' => null]],
    ])->assertOk();
});

it('écarte les clés inconnues envoyées dans une ligne', function (): void {
    [, $token] = authedCandidatDiplome();

    $this->withToken($token)->putJson('/v1/applications/me', [
        'formations_professionnelles' => [
            ['centre' => 'ISMP', 'qualification' => 'Certificat', 'annee' => 2021, 'statut' => 'accepte'],
        ],
    ])->assertOk()
        ->assertJsonPath('data.formations_professionnelles.0.centre', 'ISMP')
        ->assertJsonMissingPath('data.formations_professionnelles.0.statut');
});

it('refuse plus de dix lignes', function (): void {
    [, $token] = authedCandidatDiplome();

    $rows = array_fill(0, 11, ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019]);

    $this->withToken($token)->putJson('/v1/applications/me', ['autres_diplomes' => $rows])
        ->assertStatus(422)
        ->assertJsonValidationErrors('autres_diplomes');
});

it('interdit de forcer form_version via le body', function (): void {
    [, $token] = authedCandidatDiplome();

    $this->withToken($token)->putJson('/v1/applications/me', [
        'nom' => 'Ndongo',
        'form_version' => 1,
    ])->assertOk()
        ->assertJsonPath('data.form_version', 2);
});
