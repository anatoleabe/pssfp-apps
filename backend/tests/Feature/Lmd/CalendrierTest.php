<?php

declare(strict_types=1);

use App\Filament\Resources\CalendrierAcademiqueResource;
use App\Filament\Resources\JourNonOuvreResource;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CalendrierAcademique;
use App\Models\EvenementCalendrier;
use App\Models\InscriptionAnnuelle;
use App\Models\ModificationEvenement;
use App\Models\User;
use App\Services\Lmd\Calendriers;
use App\Services\Lmd\ControlesCalendrier;
use App\Services\Lmd\ImportCalendrier;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

beforeEach(function (): void {
    Storage::fake(CandidatureObjectStorage::DISK);
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->chef = ($this->compte)('chef_structure');
    $this->service = app(Calendriers::class);
    $this->controles = app(ControlesCalendrier::class);
    $this->piece = fn () => UploadedFile::fake()->createWithContent('calendrier-signe.pdf', "%PDF-1.4\n%signe\n");

    // Petit calendrier M2 : examens, jury, publication, rattrapage.
    $this->c = $this->service->creer('2026-2027', '2026-10-01', '2027-08-31', $this->agent);
    $ajouter = fn (string $type, string $libelle, string $debut, ?string $fin = null, ?string $session = null) => $this->service->enregistrer($this->c,
        ['type' => $type, 'libelle' => $libelle, 'niveau' => 'M2', 'session' => $session, 'debut_prevu' => $debut, 'fin_prevue' => $fin ?? $debut], $this->agent);
    $this->examens = $ajouter('examens', 'Examens M2', '2027-02-16', '2027-02-28', 'normale');
    $this->publication = $ajouter('publication', 'Publication M2', '2027-03-20', null, 'normale');
    $this->rattrapage = $ajouter('rattrapage', 'Rattrapage M2', '2027-04-13', '2027-04-17', 'rattrapage');
    $this->ajouter = $ajouter;
});

it('refuse une date hors de l\'année académique, même en base', function (): void {
    expect(fn () => ($this->ajouter)('examens', 'Examens 2025', '2025-02-16'))->toThrow(InvalidArgumentException::class, 'hors de l\'année académique')
        ->and(fn () => DB::transaction(fn () => EvenementCalendrier::query()->whereKey($this->examens->id)->update(['debut_prevu' => '2025-02-16'])))
        ->toThrow(QueryException::class, 'hors de l');
});

it('refuse la soumission avec ses anomalies bloquantes, puis l\'accepte après correction', function (): void {
    $this->controles->genererDependances($this->c);
    $anomalies = collect($this->controles->anomaliesBloquantes($this->c))->pluck('origine')->all();
    expect($anomalies)->toBe(['publication_sans_jury'])
        ->and(fn () => $this->service->soumettre($this->c, $this->agent))->toThrow(InvalidArgumentException::class, '1 anomalie(s) bloquante(s)');

    ($this->ajouter)('deliberation', 'Jury M2', '2027-03-10', null, 'normale');
    $this->service->soumettre($this->c->refresh(), $this->agent);
    expect($this->c->refresh()->statut)->toBe(CalendrierAcademique::SOUMIS)
        ->and(fn () => ($this->ajouter)('autre', 'Ajout', '2027-05-01'))->toThrow(InvalidArgumentException::class, 'renvoyé en brouillon');
});

it('refuse la signature sans pièce, puis signe : référence figée, version précédente remplacée', function (): void {
    ($this->ajouter)('deliberation', 'Jury M2', '2027-03-10', null, 'normale');
    $this->service->soumettre($this->c, $this->agent);

    expect(fn () => $this->service->signer($this->c->refresh(), null, 'Le Chef de la structure', '2026-11-07', $this->agent))->toThrow(HttpException::class)
        ->and(fn () => $this->service->signer($this->c->refresh(), null, 'Le Chef de la structure', '2026-11-07', $this->chef))->toThrow(InvalidArgumentException::class, 'calendrier signé numérisé');
    $this->service->signer($this->c->refresh(), ($this->piece)(), 'Le Chef de la structure', '2026-11-07', $this->chef);
    $c = $this->c->refresh();
    expect($c->statut)->toBe(CalendrierAcademique::SIGNE)
        ->and($c->reference_figee_at)->not->toBeNull()
        ->and($this->examens->refresh()->debut_signe->toDateString())->toBe('2027-02-16')
        ->and(Storage::disk(CandidatureObjectStorage::DISK)->exists($c->piece_signee_path))->toBeTrue();

    $v2 = $this->service->nouvelleVersion($c, $this->agent);
    expect($v2->version)->toBe(2)->and($v2->evenements()->count())->toBe(4);
    $this->service->soumettre($v2, $this->agent);
    $this->service->signer($v2->refresh(), ($this->piece)(), 'Le Chef de la structure', '2026-12-01', $this->chef);
    expect($c->refresh()->statut)->toBe(CalendrierAcademique::REMPLACE)
        ->and(fn () => $this->service->enregistrer($c, ['type' => 'autre', 'libelle' => 'x', 'debut_prevu' => '2027-05-01'], $this->responsable))
        ->toThrow(InvalidArgumentException::class, 'Version remplacée');
});

it('réajuste un calendrier signé : motif exigé, journal en ajout seul, glissement calculé', function (): void {
    ($this->ajouter)('deliberation', 'Jury M2', '2027-03-10', null, 'normale');
    $this->service->soumettre($this->c, $this->agent);
    $this->service->signer($this->c->refresh(), ($this->piece)(), 'Le Chef de la structure', '2026-11-07', $this->chef);
    $data = ['type' => 'publication', 'libelle' => 'Publication M2', 'niveau' => 'M2', 'session' => 'normale', 'debut_prevu' => '2027-03-23', 'fin_prevue' => '2027-03-23'];

    expect(fn () => $this->service->enregistrer($this->c->refresh(), $data, $this->agent, $this->publication->refresh(), 'Salle indisponible'))->toThrow(HttpException::class)
        ->and(fn () => $this->service->enregistrer($this->c->refresh(), $data, $this->responsable, $this->publication->refresh()))->toThrow(InvalidArgumentException::class, 'exige un motif');
    $this->service->enregistrer($this->c->refresh(), $data, $this->responsable, $this->publication->refresh(), 'Salle des délibérations indisponible');

    $p = $this->publication->refresh();
    $j = ModificationEvenement::query()->where('evenement_id', $p->id)->sole();
    expect($p->glissement())->toBe(3)
        ->and($j->ancien_debut->toDateString())->toBe('2027-03-20')
        ->and($j->nouveau_debut->toDateString())->toBe('2027-03-23')
        ->and($j->auteur_id)->toBe($this->responsable->id)
        ->and(fn () => DB::transaction(fn () => DB::table('modifications_evenement')->where('id', $j->id)->delete()))->toThrow(QueryException::class, 'ajout seul');

    $this->service->executer($this->examens->refresh(), ['statut' => 'realise', 'debut_reel' => '2027-02-16', 'fin_reelle' => '2027-03-01'], $this->agent);
    expect($this->examens->refresh()->avancement_pct)->toBe(100)
        ->and(ModificationEvenement::query()->where('evenement_id', $this->examens->id)->value('nouveau_statut'))->toBe('realise')
        ->and(fn () => $this->service->executer($this->rattrapage->refresh(), ['statut' => 'reporte'], $this->agent))->toThrow(InvalidArgumentException::class, 'motif');
});

it('reprend le calendrier 2026-2027 signé et en signale les anomalies du dossier source', function (): void {
    $this->c->forceFill(['annee_academique' => '2025-2026'])->save();
    $b = app(ImportCalendrier::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/calendrier-2026-2027.json')), true), true);
    $c = CalendrierAcademique::query()->where('annee_academique', '2026-2027')->sole();

    expect($b['evenements'])->toBe(41)->and($b['phases'])->toBe(6)->and($b['dependances'])->toBe(18)
        ->and($c->statut)->toBe(CalendrierAcademique::SIGNE)
        ->and($c->evenements()->whereNull('debut_signe')->count())->toBe(0)
        ->and($b['anomalies'])->toHaveCount(7)
        ->and(collect($b['anomalies'])->filter(fn (string $a): bool => str_contains($a, 'Prévoir le jury'))->count())->toBe(4)
        ->and(collect($b['anomalies'])->contains(fn (string $a): bool => str_starts_with($a, 'Publication des notes du second semestre M1 : PROMO 14 → Examens de rattrapage')))->toBeTrue()
        ->and(fn () => app(ImportCalendrier::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/calendrier-2026-2027.json')), true), true))
        ->toThrow(InvalidArgumentException::class, 'existe déjà');
    $gantt = $this->controles->gantt($c);
    expect($gantt[0]['phase'])->toBeTrue()->and($gantt[0]['libelle'])->toBe('Admission et rentrée')->and(count($gantt))->toBe(47);
});

it('ne montre à l\'auditeur que le calendrier publié, limité à son niveau et aux événements communs', function (): void {
    ($this->ajouter)('deliberation', 'Jury M2', '2027-03-10', null, 'normale');
    $this->service->enregistrer($this->c, ['type' => 'examens', 'libelle' => 'Examens M1', 'niveau' => 'M1', 'debut_prevu' => '2027-02-16'], $this->agent);
    $this->service->enregistrer($this->c, ['type' => 'vacances', 'libelle' => 'Interruption', 'debut_prevu' => '2026-12-20', 'fin_prevue' => '2027-01-04'], $this->agent);
    $bareme = BaremeScolarite::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M2', 'montant_inscription' => 185000,
        'montant_tranche1' => 500000, 'montant_tranche2' => 500000, 'tranche1_echeance' => now()->addMonth()->toDateString()]);
    $auditeur = Auditeur::factory()->campagneCommune()->create();
    $auditeur->candidature->forceFill(['user_id' => User::factory()->create()->id])->save();
    InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id, 'niveau' => 'M2', 'annee_academique' => '2026-2027']);
    $jeton = $auditeur->candidature->user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;

    $this->withToken($jeton)->getJson('/v1/applications/me/calendrier')->assertOk()->assertJsonPath('data', null);
    $this->service->soumettre($this->c, $this->agent);
    $this->service->signer($this->c->refresh(), ($this->piece)(), 'Le Chef de la structure', '2026-11-07', $this->chef);
    $this->withToken($jeton)->getJson('/v1/applications/me/calendrier')->assertOk()->assertJsonPath('data', null);
    $this->service->publier($this->c->refresh(), $this->responsable);

    $r = $this->withToken($jeton)->getJson('/v1/applications/me/calendrier')->assertOk()->assertJsonPath('data.annee_academique', '2026-2027');
    expect(collect($r->json('data.evenements'))->pluck('libelle')->all())
        ->toBe(['Interruption', 'Examens M2', 'Jury M2', 'Publication M2', 'Rattrapage M2']);
});

it('ouvre le calendrier à la scolarité et au chef de la structure, pas à la bibliothèque', function (): void {
    $this->controles->genererDependances($this->c);
    $this->actingAs($this->agent)->get(CalendrierAcademiqueResource::getUrl('index'))->assertOk();
    $this->actingAs($this->agent)->get(CalendrierAcademiqueResource::getUrl('view', ['record' => $this->c]))->assertOk()
        ->assertSee('Diagramme de Gantt')->assertSee('Publication M2')->assertSee('Prévoir le jury');
    $this->actingAs($this->chef)->get(CalendrierAcademiqueResource::getUrl('view', ['record' => $this->c]))->assertOk();
    $this->actingAs($this->agent)->get(JourNonOuvreResource::getUrl('index'))->assertOk();
    $this->actingAs(($this->compte)('librarian'))->get(CalendrierAcademiqueResource::getUrl('index'))->assertForbidden();
});
