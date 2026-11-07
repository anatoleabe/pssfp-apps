<?php

declare(strict_types=1);

use App\Filament\Pages\Lmd\SaisieAnonyme;
use App\Filament\Resources\EpreuveAnonymeResource;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CopieAnonyme;
use App\Models\EtapeSaisie;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\NoteEcue;
use App\Models\User;
use App\Services\Lmd\Anonymat;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\SaisieNotes;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

const PHRASE = 'phrase-secrete-du-gestionnaire';

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    Maquette::query()->update(['statut' => Maquette::VALIDEE, 'validee_at' => now()]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->gestionnaire = ($this->compte)('gestionnaire_anonymats');
    $this->op1 = ($this->compte)('operateur_saisie');
    $this->op2 = ($this->compte)('operateur_saisie');
    $this->op3 = ($this->compte)('operateur_saisie');
    $this->temoin = ($this->compte)('scolarite_responsable');
    $this->service = app(Anonymat::class);
    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->inscriptions = collect(range(1, 3))->map(function () use ($bareme): InscriptionAnnuelle {
        $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory()->campagneCommune(), 'bareme_id' => $bareme->id]);
        app(InscriptionsPedagogiques::class)->inscrire($i);

        return $i;
    });
    $this->ecue = MaquetteEcue::query()->where('code', 'MPMPP711')->firstOrFail();
    $this->epreuve = $this->service->creer($this->ecue, 'normale', $this->gestionnaire);
    $this->service->anonymiser($this->epreuve, PHRASE, $this->gestionnaire);
    $this->copies = fn () => $this->epreuve->copies()->get();
    // Saisie concordante de deux opérateurs pour une correction
    $this->noter = function (string $numero, string $note, int $correction = 1): void {
        $this->service->saisir($this->epreuve, $numero, $correction, $note, $this->op1);
        $this->service->saisir($this->epreuve, $numero, $correction, $note, $this->op2);
    };
});

it('attribue des numéros uniques, bien formés, avec un caractère de contrôle valide', function (): void {
    $numeros = ($this->copies)()->pluck('numero');

    expect($numeros)->toHaveCount(3)->and($numeros->unique())->toHaveCount(3);
    foreach ($numeros as $n) {
        expect(Anonymat::numeroValide($n))->toBeTrue()->and($n)->not->toMatch('/[01IO]/');
    }
    expect(Anonymat::numeroValide(substr($numeros[0], 0, 8).($numeros[0][8] === 'A' ? 'B' : 'A')))->toBeFalse();
});

it('ne relie une copie à un auditeur qu\'avec la bonne phrase secrète, et trace la lecture', function (): void {
    expect(fn () => $this->service->correspondance($this->epreuve->refresh(), 'mauvaise-phrase-secrete', $this->gestionnaire, 'essai'))
        ->toThrow(InvalidArgumentException::class, 'incorrecte');
    expect(fn () => $this->service->correspondance($this->epreuve->refresh(), 'court', $this->gestionnaire, 'essai'))
        ->toThrow(InvalidArgumentException::class, 'trop courte');

    $c = $this->service->correspondance($this->epreuve->refresh(), PHRASE, $this->gestionnaire, 'essai');
    expect(collect($c)->map->id->sort()->values()->all())
        ->toBe($this->inscriptions->map(fn ($i) => $i->inscriptionsPedagogiques()->where('maquette_ue_id', $this->ecue->maquette_ue_id)->value('id'))->sort()->values()->all())
        ->and(Activity::query()->where('event', 'correspondance_consultee')->exists())->toBeTrue()
        ->and(DB::table('copies_anonymes')->pluck('correspondance')->every(fn (string $c): bool => strlen((string) base64_decode($c, true)) > 28 && ! ctype_digit($c)))->toBeTrue();
});

it('sépare les tâches : le gestionnaire ne saisit pas, un opérateur ne double pas sa propre saisie', function (): void {
    $n = ($this->copies)()->first()->numero;
    $this->service->constater($this->epreuve, $n, 'presente', null, $this->gestionnaire);

    expect(fn () => $this->service->saisir($this->epreuve, $n, 1, '12', $this->gestionnaire))->toThrow(HttpException::class);
    $this->service->saisir($this->epreuve, $n, 1, '12', $this->op1);
    expect(fn () => $this->service->saisir($this->epreuve, $n, 1, '12', $this->op1))->toThrow(InvalidArgumentException::class, 'autre opérateur');
});

it('tranche un écart de double saisie par un troisième opérateur, et double-corrige la zone 10-12', function (): void {
    $n = ($this->copies)()->first()->numero;
    $this->service->constater($this->epreuve, $n, 'presente', null, $this->gestionnaire);
    $this->service->saisir($this->epreuve, $n, 1, '15', $this->op1);
    expect($this->service->saisir($this->epreuve, $n, 1, '16', $this->op2)['ecart'])->toBeTrue();
    expect($this->service->noteFinale(CopieAnonyme::query()->where('numero', $n)->first()))->toBeNull();
    $this->service->saisir($this->epreuve, $n, 1, '16', $this->op3);
    expect($this->service->noteFinale(CopieAnonyme::query()->where('numero', $n)->first()))->toBe(16.0);

    $m = ($this->copies)()->skip(1)->first()->numero;
    $this->service->constater($this->epreuve, $m, 'presente', null, $this->gestionnaire);
    ($this->noter)($m, '11');
    $copie = CopieAnonyme::query()->where('numero', $m)->first();
    expect($this->service->correctionsAttendues($copie))->toBe([1, 2])->and($this->service->noteFinale($copie))->toBeNull();
    ($this->noter)($m, '15', 2);
    expect($this->service->correctionsAttendues($copie->refresh()))->toBe([1, 2, 3]);
    ($this->noter)($m, '12.5', 3);
    expect($this->service->noteFinale($copie->refresh()))->toBe(12.5);
});

it('refuse le verrouillage tant qu\'une copie est non constatée, égarée ou sans note', function (): void {
    [$a, $b, $c] = ($this->copies)()->pluck('numero')->all();
    expect(fn () => $this->service->verrouiller($this->epreuve, $this->gestionnaire))->toThrow(InvalidArgumentException::class, 'non constatée');
    $this->service->constater($this->epreuve, $a, 'presente', null, $this->gestionnaire);
    $this->service->constater($this->epreuve, $b, 'egaree', null, $this->gestionnaire);
    $this->service->constater($this->epreuve, $c, 'absente', null, $this->gestionnaire);
    expect(fn () => $this->service->verrouiller($this->epreuve, $this->gestionnaire))->toThrow(InvalidArgumentException::class, 'égarée');
    expect(fn () => $this->service->constater($this->epreuve, $b, 'egaree_traitee', 'court', $this->gestionnaire))->toThrow(InvalidArgumentException::class, 'motivée');
    $this->service->constater($this->epreuve, $b, 'egaree_traitee', 'Copie égarée au secrétariat : absence justifiée', $this->gestionnaire);
    expect(fn () => $this->service->verrouiller($this->epreuve, $this->gestionnaire))->toThrow(InvalidArgumentException::class, 'sans note');
    ($this->noter)($a, '14');
    $this->service->verrouiller($this->epreuve, $this->gestionnaire);
    expect($this->epreuve->refresh()->statut)->toBe('verrouillee');
});

it('lève l\'anonymat avec le témoin, reporte les notes, verrouille l\'examen, et ne se rejoue pas', function (): void {
    [$a, $b, $c] = ($this->copies)()->pluck('numero')->all();
    $this->service->constater($this->epreuve, $a, 'presente', null, $this->gestionnaire);
    $this->service->constater($this->epreuve, $b, 'blanche', null, $this->gestionnaire);
    $this->service->constater($this->epreuve, $c, 'absente', null, $this->gestionnaire);
    ($this->noter)($a, '14');
    $this->service->verrouiller($this->epreuve, $this->gestionnaire);

    expect(fn () => $this->service->lever($this->epreuve, PHRASE, $this->gestionnaire))->toThrow(InvalidArgumentException::class, 'témoin');
    expect(fn () => $this->service->autoriserLevee($this->epreuve, $this->gestionnaire))->toThrow(HttpException::class);
    $this->service->autoriserLevee($this->epreuve, $this->temoin);
    expect(fn () => $this->service->lever($this->epreuve, 'mauvaise-phrase-secrete', $this->gestionnaire))->toThrow(InvalidArgumentException::class, 'incorrecte');

    expect($this->service->lever($this->epreuve, PHRASE, $this->gestionnaire))->toBe(3);
    $notes = NoteEcue::query()->where('maquette_ecue_id', $this->ecue->id)->get();
    expect($notes->pluck('examen')->filter()->map(fn ($v) => (float) $v)->sort()->values()->all())->toBe([0.0, 14.0])
        ->and($notes->where('presence_examen', 'absent_non_justifie'))->toHaveCount(1)
        ->and(EtapeSaisie::query()->where('maquette_ecue_id', $this->ecue->id)->where('etape', 'examen')->value('statut'))->toBe('verrouillee')
        ->and($this->epreuve->refresh()->pv_sha256)->toHaveLength(64);
    expect(fn () => $this->service->lever($this->epreuve, PHRASE, $this->gestionnaire))->toThrow(InvalidArgumentException::class, 'déjà levé');
});

it('refuse la saisie de l\'examen en clair tant que l\'épreuve est sous anonymat, et garde les saisies en ajout seul', function (): void {
    $saisie = app(SaisieNotes::class);
    $etape = $saisie->etape($this->ecue, 'normale', 'examen');
    $etape->forceFill(['statut' => 'ouverte'])->save();
    $ip = $this->inscriptions[0]->inscriptionsPedagogiques()->where('maquette_ue_id', $this->ecue->maquette_ue_id)->firstOrFail();
    expect(fn () => $saisie->saisir($etape, [$ip->id => ['note' => 12]], ($this->compte)('scolarite_agent')))->toThrow(InvalidArgumentException::class, 'anonymat');

    $n = ($this->copies)()->first()->numero;
    $this->service->constater($this->epreuve, $n, 'presente', null, $this->gestionnaire);
    $this->service->saisir($this->epreuve, $n, 1, '12', $this->op1);
    expect(fn () => DB::transaction(fn () => DB::table('saisies_anonymes')->update(['note' => 20])))->toThrow(QueryException::class, 'ajout seul')
        ->and(DB::table('notes_ecue')->whereNotNull('examen')->count())->toBe(0);
});

it('ouvre l\'anonymat au gestionnaire, la saisie sur numéros aux opérateurs seulement', function (): void {
    $this->actingAs($this->gestionnaire)->get(EpreuveAnonymeResource::getUrl('view', ['record' => $this->epreuve]))->assertOk();
    $this->actingAs($this->op1)->get(SaisieAnonyme::getUrl())->assertOk();
    $this->actingAs($this->gestionnaire)->get(SaisieAnonyme::getUrl())->assertForbidden();
});
