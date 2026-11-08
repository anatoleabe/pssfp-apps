<?php

declare(strict_types=1);

use App\Filament\Resources\EmploiDuTempsResource;
use App\Filament\Resources\SalleResource;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\EmploiDuTemps;
use App\Models\InscriptionAnnuelle;
use App\Models\JourNonOuvre;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\Parcours;
use App\Models\PlageHoraire;
use App\Models\Salle;
use App\Models\Seance;
use App\Models\User;
use App\Services\Lmd\Calendriers;
use App\Services\Lmd\ConflitsEmploiDuTemps;
use App\Services\Lmd\ControlesCalendrier;
use App\Services\Lmd\EmploisDuTemps;
use App\Services\Lmd\ImportMaquettes;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

beforeEach(function (): void {
    Storage::fake(CandidatureObjectStorage::DISK);
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    Maquette::query()->update(['statut' => Maquette::VALIDEE, 'validee_at' => now()]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    $this->prof = ($this->compte)('enseignant');
    $this->service = app(EmploisDuTemps::class);
    $this->conflits = app(ConflitsEmploiDuTemps::class);

    // Calendrier signé : deux périodes de cours, l'interruption, les examens M1 en fin de seconde période.
    $calendriers = app(Calendriers::class);
    $this->c = $calendriers->creer('2026-2027', '2026-10-01', '2027-08-31', $this->agent);
    $ajouter = fn (string $type, string $libelle, ?string $niveau, string $debut, string $fin, ?string $session = null) => $calendriers->enregistrer($this->c,
        ['type' => $type, 'libelle' => $libelle, 'niveau' => $niveau, 'session' => $session, 'debut_prevu' => $debut, 'fin_prevue' => $fin], $this->agent);
    $this->periode1 = $ajouter('periode_cours', 'Première période de cours', null, '2026-11-02', '2026-12-19');
    $ajouter('vacances', 'Interruption de fin d\'année', null, '2026-12-20', '2027-01-04');
    $this->periode2 = $ajouter('periode_cours', 'Seconde période de cours', null, '2027-01-05', '2027-02-12');
    $ajouter('examens', 'Examens M1', 'M1', '2027-02-08', '2027-02-12', 'normale');
    $calendriers->soumettre($this->c->refresh(), $this->agent);
    $calendriers->signer($this->c->refresh(), UploadedFile::fake()->createWithContent('cal.pdf', "%PDF-1.4\n%x\n"), 'Le Chef de la structure', '2026-11-01', ($this->compte)('chef_structure'));
    JourNonOuvre::query()->create(['jour' => '2026-11-20', 'libelle' => 'Journée d\'essai']);

    $this->tc = Parcours::query()->where('code', 'TC')->sole();
    $this->ffcp = Parcours::query()->where('code', 'FFCP')->sole();
    $this->gtfp = Parcours::query()->where('code', 'GTFP')->sole();
    $this->ecue = MaquetteEcue::query()->where('code', 'MPMPP711')->sole();      // 30 h CM + 5 h TD
    $this->ecue2 = MaquetteEcue::query()->where('code', 'MPMPP712')->sole();
    $this->matin = PlageHoraire::query()->where('code', 'MATIN')->sole();
    $this->salle = Salle::query()->create(['code' => 'A1', 'libelle' => 'Amphi A1', 'capacite' => 120]);
    // Cours commun aux options FFCP et GTFP : même code d'ECUE dans les deux maquettes.
    $commun = fn (Parcours $p): MaquetteEcue => MaquetteEcue::query()->create(['maquette_ue_id' => Maquette::query()->where('parcours_id', $p->id)->sole()->ues()->value('id'),
        'code' => 'MPCOM901', 'intitule' => 'Séminaire commun', 'credits' => 1, 'heures_cm' => 15, 'heures_td' => 0, 'heures_tp' => 0, 'heures_tpe' => 0, 'ordre' => 99]);
    $this->communFfcp = $commun($this->ffcp);
    $this->communGtfp = $commun($this->gtfp);
    $this->seance = fn (EmploiDuTemps $e, MaquetteEcue $x, string $date, array $plus = []) => $this->service->programmer($e,
        ['maquette_ecue_id' => $x->id, 'date_seance' => $date, 'plage_horaire_id' => $this->matin->id, 'modalite' => 'presentiel', 'nature' => 'CM', ...$plus], $this->agent);
    $this->controles = fn (EmploiDuTemps $e): array => collect($this->conflits->pour($e))->pluck('controle')->all();
});

it('signale enseignant, salle et parcours programmés deux fois, refuse la publication, mais admet le cours commun', function (): void {
    $m1 = $this->service->creer($this->periode1, $this->tc, $this->agent);
    ($this->seance)($m1, $this->ecue, '2026-11-16', ['enseignant_id' => $this->prof->id, 'salle_id' => $this->salle->id]);
    ($this->seance)($m1, $this->ecue2, '2026-11-16', ['enseignant_id' => $this->prof->id, 'salle_id' => $this->salle->id]);
    expect(($this->controles)($m1))->toContain('enseignant_double', 'salle_double', 'classe_double')
        ->and(fn () => $this->service->publier($m1, $this->agent))->toThrow(InvalidArgumentException::class, 'Publication refusée');

    $ffcp = $this->service->creer($this->periode2, $this->ffcp, $this->agent);
    $gtfp = $this->service->creer($this->periode2, $this->gtfp, $this->agent);
    ($this->seance)($ffcp, $this->communFfcp, '2027-01-11', ['enseignant_id' => $this->prof->id, 'salle_id' => $this->salle->id]);
    ($this->seance)($gtfp, $this->communGtfp, '2027-01-11', ['enseignant_id' => $this->prof->id, 'salle_id' => $this->salle->id]);
    expect(array_filter(($this->controles)($gtfp), fn (string $k): bool => str_ends_with($k, '_double')))->toBe([]);
    $this->service->publier($gtfp, $this->agent);
    expect($gtfp->refresh()->statut)->toBe(EmploiDuTemps::PUBLIE);

    $autre = MaquetteEcue::query()->whereHas('maquetteUe.maquette', fn ($q) => $q->where('parcours_id', $this->ffcp->id))->where('code', '<>', 'MPCOM901')->firstOrFail();
    ($this->seance)($ffcp, $autre, '2027-01-11', ['enseignant_id' => $this->prof->id]);
    expect(($this->controles)($ffcp))->toContain('enseignant_double', 'classe_double');
});

it('signale une séance pendant l\'interruption, hors période, un jour non ouvré ou pendant les examens du niveau', function (): void {
    $m1 = $this->service->creer($this->periode1, $this->tc, $this->agent);
    ($this->seance)($m1, $this->ecue, '2026-12-21');
    ($this->seance)($m1, $this->ecue, '2026-11-20');
    expect(($this->controles)($m1))->toEqualCanonicalizing(['hors_periode', 'pendant_evenement', 'jour_non_ouvre']);

    $m1b = $this->service->creer($this->periode2, $this->tc, $this->agent);
    $m2 = $this->service->creer($this->periode2, $this->ffcp, $this->agent);
    ($this->seance)($m1b, $this->ecue, '2027-02-09');
    ($this->seance)($m2, $this->communFfcp, '2027-02-09');
    expect(($this->controles)($m1b))->toBe(['pendant_evenement'])
        ->and(($this->controles)($m2))->toBe([]);
});

it('répète une séance chaque semaine en sautant le jour non ouvré', function (): void {
    $m1 = $this->service->creer($this->periode1, $this->tc, $this->agent);
    $b = $this->service->programmer($m1, ['maquette_ecue_id' => $this->ecue2->id, 'date_seance' => '2026-11-06', 'plage_horaire_id' => $this->matin->id,
        'modalite' => 'hybride'], $this->agent, '2027-03-01');

    expect($b['creees'])->toBe(6)
        ->and($b['sautees'])->toBe(['20/11/2026 (Journée d\'essai)'])
        ->and($m1->seances()->where('maquette_ecue_id', $this->ecue2->id)->max('date_seance'))->toBe('2026-12-18');
});

it('reporte une séance prévue avec motif, pas une séance tenue', function (): void {
    $m1 = $this->service->creer($this->periode1, $this->tc, $this->agent);
    ($this->seance)($m1, $this->ecue, '2026-11-16', ['salle_id' => $this->salle->id]);
    ($this->seance)($m1, $this->ecue, '2026-11-23');
    [$a, $b] = $m1->seances()->orderBy('date_seance')->get()->all();

    expect(fn () => $this->service->reporter($a, '', ['date_seance' => '2026-11-18'], $this->agent))->toThrow(InvalidArgumentException::class, 'motif')
        ->and(fn () => $this->service->reporter($a, 'Enseignant en mission', ['date_seance' => '2026-11-18'], ($this->compte)('librarian')))->toThrow(HttpException::class);
    $r = $this->service->reporter($a, 'Enseignant en mission', ['date_seance' => '2026-11-18'], $this->agent);
    expect($a->refresh()->statut)->toBe('reportee')
        ->and($a->reportee_vers_id)->toBe($r->id)
        ->and($r->debut)->toStartWith('08:00')
        ->and($r->salle_id)->toBe($this->salle->id);

    $b->forceFill(['statut' => 'tenue'])->save();
    expect(fn () => $this->service->reporter($b->refresh(), 'Trop tard', ['date_seance' => '2026-11-25'], $this->agent))->toThrow(InvalidArgumentException::class, 'prévue');
});

it('calcule le volume horaire et l\'avancement de la période, repris dans le Gantt', function (): void {
    $m1 = $this->service->creer($this->periode1, $this->tc, $this->agent);
    $this->service->programmer($m1, ['maquette_ecue_id' => $this->ecue->id, 'date_seance' => '2026-11-02', 'plage_horaire_id' => $this->matin->id, 'modalite' => 'presentiel'], $this->agent, '2026-12-19');
    $this->service->programmer($m1, ['maquette_ecue_id' => $this->ecue->id, 'date_seance' => '2026-11-04', 'plage_horaire_id' => $this->matin->id, 'modalite' => 'presentiel'], $this->agent, '2026-11-18');
    $m1->seances()->orderBy('date_seance')->limit(4)->get()->each(fn (Seance $s) => $s->forceFill(['statut' => 'tenue'])->save());
    $this->service->publier($m1, $this->agent);

    $v = collect($this->service->volumes($m1))->firstWhere(fn (array $l): bool => $l['ecue']->code === 'MPMPP711');
    $ligne = collect(app(ControlesCalendrier::class)->gantt($this->c->refresh()))->firstWhere('libelle', 'Première période de cours');
    expect([$v['maquette'], $v['programme'], $v['tenu'], $v['reste']])->toBe([35.0, 30.0, 12.0, 5.0])
        ->and($ligne['avancement'])->toBe(40);
});

it('montre à l\'auditeur les séances publiées de son parcours, semaine par semaine', function (): void {
    $m1 = $this->service->creer($this->periode1, $this->tc, $this->agent);
    ($this->seance)($m1, $this->ecue, '2026-11-16', ['enseignant_id' => $this->prof->id, 'salle_id' => $this->salle->id]);
    $m2 = $this->service->creer($this->periode1, $this->ffcp, $this->agent);
    ($this->seance)($m2, $this->communFfcp, '2026-11-17');
    $this->service->publier($m2, $this->agent);

    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $auditeur = Auditeur::factory()->campagneCommune()->create();
    $auditeur->candidature->forceFill(['user_id' => User::factory()->create()->id])->save();
    InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id, 'niveau' => 'M1', 'annee_academique' => '2026-2027'])
        ->forceFill(['parcours_id' => $this->tc->id])->save();
    $jeton = $auditeur->candidature->user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;

    $this->withToken($jeton)->getJson('/v1/applications/me/emploi-du-temps?semaine=2026-11-18')->assertOk()
        ->assertJsonPath('data.semaine', '2026-11-16')->assertJsonPath('data.seances', [])->assertJsonPath('data.premiere', null);
    $this->service->publier($m1, $this->agent);
    $r = $this->withToken($jeton)->getJson('/v1/applications/me/emploi-du-temps?semaine=2026-11-18')->assertOk()
        ->assertJsonPath('data.precedente', '2026-11-09')->assertJsonPath('data.suivante', '2026-11-23');
    expect($r->json('data.seances'))->toHaveCount(1)
        ->and($r->json('data.seances.0'))->toMatchArray(['date' => '2026-11-16', 'debut' => '08:00', 'fin' => '11:00', 'ecue' => 'MPMPP711', 'salle' => 'Amphi A1', 'enseignant' => $this->prof->name]);
    $this->withToken($jeton)->getJson('/v1/applications/me/emploi-du-temps?semaine=semaine')->assertUnprocessable();
});

it('ouvre les emplois du temps à la scolarité, pas à la bibliothèque', function (): void {
    $m1 = $this->service->creer($this->periode1, $this->tc, $this->agent);
    ($this->seance)($m1, $this->ecue, '2026-11-16');
    $this->actingAs($this->agent)->get(EmploiDuTempsResource::getUrl('index'))->assertOk()->assertSee('TC');
    $this->actingAs($this->agent)->get(EmploiDuTempsResource::getUrl('view', ['record' => $m1]))->assertOk()->assertSee('Volume horaire par ECUE')->assertSee('MPMPP711');
    $this->actingAs($this->agent)->get(SalleResource::getUrl('index'))->assertOk()->assertSee('Amphi A1');
    $this->actingAs(($this->compte)('librarian'))->get(EmploiDuTempsResource::getUrl('index'))->assertForbidden();
    expect(fn () => $this->service->creer($this->periode1, $this->tc, $this->agent))->toThrow(InvalidArgumentException::class, 'existe déjà');
});
