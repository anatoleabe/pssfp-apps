<?php

declare(strict_types=1);

use App\Filament\Resources\RequeteResource;
use App\Filament\Resources\SeanceRequetesResource;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\Deliberation;
use App\Models\EtapeSaisie;
use App\Models\HistoriqueNote;
use App\Models\InscriptionAnnuelle;
use App\Models\Jury;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\MembreJury;
use App\Models\NoteEcue;
use App\Models\Requete;
use App\Models\User;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\Requetes;
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
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    Maquette::query()->update(['statut' => Maquette::VALIDEE, 'validee_at' => now()]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->agentA = ($this->compte)('scolarite_agent');
    $this->agentB = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->chef = ($this->compte)('chef_commission_requetes');
    $this->service = app(Requetes::class);

    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $auditeur = Auditeur::factory()->campagneCommune()->create();
    $auditeur->candidature->forceFill(['user_id' => User::factory()->create()->id])->save();
    $this->inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id]);
    app(InscriptionsPedagogiques::class)->inscrire($this->inscription);
    $this->auditeur = $auditeur->refresh();
    $this->ecue = MaquetteEcue::query()->where('code', 'MPMPP711')->firstOrFail();
    $ip = $this->inscription->inscriptionsPedagogiques()->where('maquette_ue_id', $this->ecue->maquette_ue_id)->firstOrFail();
    (new NoteEcue(['inscription_pedagogique_id' => $ip->id, 'maquette_ecue_id' => $this->ecue->id, 'session' => 'normale']))
        ->forceFill(['cc' => '12.00', 'assiduite' => '15.00', 'examen' => '10.00', 'presence_examen' => 'present'])->save();
    $this->verrouiller = function (string $etape): void {
        EtapeSaisie::query()->firstOrCreate(['maquette_ecue_id' => $this->ecue->id, 'session' => 'normale', 'etape' => $etape])
            ->forceFill(['statut' => EtapeSaisie::VERROUILLEE, 'verrouillee_at' => now()])->save();
    };
    $this->requeteExamen = fn (): Requete => $this->service->deposer($this->auditeur, ['type' => 'note', 'maquette_ecue_id' => $this->ecue->id, 'session' => 'normale',
        'note_visee' => 'examen', 'motif' => 'erreur_report', 'description' => 'Ma copie porte 14 et non 10.'], [UploadedFile::fake()->create('copie.pdf', 120, 'application/pdf')]);
    $this->pvNotes = fn (): UploadedFile => UploadedFile::fake()->create('pv.pdf', 80, 'application/pdf');
});

it('refuse une requête sur une note non verrouillée, en double, ou sans pièce pour une erreur de report', function (): void {
    expect(fn () => ($this->requeteExamen)())->toThrow(InvalidArgumentException::class, 'hors fenêtre');
    ($this->verrouiller)('examen');
    expect(fn () => $this->service->deposer($this->auditeur, ['type' => 'note', 'maquette_ecue_id' => $this->ecue->id, 'session' => 'normale',
        'note_visee' => 'examen', 'motif' => 'erreur_report', 'description' => 'Ma copie porte 14 et non 10.'], []))
        ->toThrow(InvalidArgumentException::class, 'pièce');

    $r = ($this->requeteExamen)();
    expect($r->numero)->toMatch('/^RQ-\d{4}-000001$/')->and($r->sha256)->toHaveLength(64)
        ->and(fn () => ($this->requeteExamen)())->toThrow(InvalidArgumentException::class, 'déjà en cours');
});

it('refuse la requête après la date limite d\'une délibération publiée', function (): void {
    ($this->verrouiller)('examen');
    $d = Deliberation::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'session' => 'normale']);
    $d->forceFill(['statut' => 'arretee', 'numero_pv' => 'PVD-X', 'pv_sha256' => str_repeat('a', 64), 'arretee_at' => now(),
        'publiee_at' => now()->subDays(20), 'requetes_jusqu_au' => now()->subDays(10)->toDateString()])->save();

    expect(fn () => ($this->requeteExamen)())->toThrow(InvalidArgumentException::class, 'hors fenêtre');
});

it('cote automatiquement à l\'agent le moins chargé, et réserve l\'instruction à l\'agent coté avec la photocopie du PV', function (): void {
    ($this->verrouiller)('examen');
    ($this->verrouiller)('cc');
    $r1 = ($this->requeteExamen)();
    $r2 = $this->service->deposer($this->auditeur, ['type' => 'note', 'maquette_ecue_id' => $this->ecue->id, 'session' => 'normale', 'note_visee' => 'cc',
        'motif' => 'autre', 'description' => 'Note de CC à vérifier.'], []);

    expect($r1->statut)->toBe('cotee')->and([$r1->agent_id, $r2->agent_id])->toEqualCanonicalizing([$this->agentA->id, $this->agentB->id]);
    $autre = $r1->agent_id === $this->agentA->id ? $this->agentB : $this->agentA;
    $cote = User::query()->find($r1->agent_id);

    expect(fn () => $this->service->marquerInstruite($r1, $autre))->toThrow(InvalidArgumentException::class, 'Seul l\'agent');
    expect(fn () => $this->service->marquerInstruite($r1, $cote))->toThrow(InvalidArgumentException::class, 'photocopie du PV');
    $this->service->ajouterElement($r1, 'photocopie_pv_notes', ($this->pvNotes)(), 'Report erroné constaté : 14 sur la copie.', $cote);
    $this->service->marquerInstruite($r1->refresh(), $cote);
    expect($r1->refresh()->statut)->toBe('instruite');
});

it('ne change la note qu\'à la validation définitive, par une autre personne que l\'instructeur, et signale la délibération à rectifier', function (): void {
    ($this->verrouiller)('examen');
    $r = ($this->requeteExamen)();
    $cote = User::query()->find($r->agent_id);
    $this->service->ajouterElement($r, 'photocopie_pv_notes', ($this->pvNotes)(), '', $cote);
    $this->service->marquerInstruite($r->refresh(), $cote);
    $jury = Jury::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'nature' => 'requetes', 'objet' => 'Requêtes',
        'acte_reference' => 'NS R1', 'acte_date' => '2026-11-01', 'acte_signataire' => 'Le Doyen']);
    MembreJury::query()->create(['jury_id' => $jury->id, 'role' => 'president', 'nom' => 'P']);
    $seance = $this->service->creerSeance($jury, now()->toDateString(), $this->responsable);
    $d = Deliberation::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'session' => 'normale']);
    $d->forceFill(['statut' => 'arretee', 'numero_pv' => 'PVD-Y', 'pv_sha256' => str_repeat('b', 64), 'arretee_at' => now()])->save();

    $this->service->inscrire($r->refresh(), $seance, $this->responsable);
    expect(fn () => $this->service->cloreSeance($seance, $this->responsable))->toThrow(InvalidArgumentException::class, 'mention');
    $this->service->statuer($r->refresh(), 'fondee', '14', 'Erreur de report confirmée sur la copie', $this->responsable);
    expect($this->service->cloreSeance($seance->refresh(), $this->responsable))->toMatch('/^PVR-\d{4}-0001$/');
    expect(fn () => $this->service->statuer($r->refresh(), 'non_fondee', null, 'Changement après clôture refusé', $this->responsable))->toThrow(InvalidArgumentException::class);
    expect((float) NoteEcue::query()->where('maquette_ecue_id', $this->ecue->id)->value('examen'))->toBe(10.0);

    expect(fn () => $this->service->validerSeance($seance->refresh(), $this->responsable))->toThrow(HttpException::class);
    expect($this->service->validerSeance($seance->refresh(), $this->chef))->toBe(1);

    expect((float) NoteEcue::query()->where('maquette_ecue_id', $this->ecue->id)->value('examen'))->toBe(14.0)
        ->and(HistoriqueNote::query()->latest('id')->value('motif'))->toBe('Requête '.$r->numero)
        ->and($r->refresh()->statut)->toBe('appliquee')
        ->and($d->refresh()->a_rectifier_at)->not->toBeNull();
});

it('garde l\'historique des requêtes en ajout seul', function (): void {
    ($this->verrouiller)('examen');
    $r = ($this->requeteExamen)();
    $id = $r->evenements()->value('id');

    expect(fn () => DB::transaction(fn () => DB::table('requete_evenements')->where('id', $id)->update(['libelle' => 'x'])))->toThrow(QueryException::class, 'ajout seul');
    expect(fn () => DB::transaction(fn () => DB::table('requete_evenements')->where('id', $id)->delete()))->toThrow(QueryException::class, 'ajout seul');
});

it('sert à l\'auditeur ses requêtes et ses étapes, jamais le détail interne, et refuse le dépôt en consultation', function (): void {
    ($this->verrouiller)('examen');
    $user = $this->auditeur->candidature->user;
    $jeton = $user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;

    $this->withToken($jeton)->getJson('/v1/applications/me/requetes')->assertOk()
        ->assertJsonPath('data.cibles.0.ecue', 'MPMPP711')
        ->assertJsonPath('data.cibles.0.notes', ['examen']);
    $this->withToken($jeton)->post('/v1/applications/me/requetes', ['type' => 'note', 'ecue' => 'MPMPP711', 'session' => 'normale', 'note_visee' => 'examen',
        'motif' => 'erreur_report', 'description' => 'Ma copie porte 14 et non 10.', 'pieces' => [UploadedFile::fake()->create('copie.pdf', 100, 'application/pdf')]],
        ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.numero', fn ($n) => str_starts_with($n, 'RQ-'));

    $r = $this->withToken($jeton)->getJson('/v1/applications/me/requetes')->assertOk()
        ->assertJsonPath('data.requetes.0.statut', 'cotee')
        ->assertJsonPath('data.requetes.0.etapes.1.libelle', 'Requête confiée à un agent de la scolarité');
    expect($r->getContent())->not->toContain('Cotation automatique')->not->toContain('"agent');

    app('auth')->forgetGuards();
    $consultation = $user->createToken('consultation-espace', ['profile:read', 'application:read', 'consultation'])->plainTextToken;
    $this->withToken($consultation)->post('/v1/applications/me/requetes', ['type' => 'document', 'type_document' => 'lettre_stage', 'description' => 'Lettre de stage pour la DGTCFM.'],
        ['Accept' => 'application/json'])->assertForbidden();
});

it('traite une demande de document jusqu\'au document disponible dans l\'espace', function (): void {
    $r = $this->service->deposer($this->auditeur, ['type' => 'document', 'type_document' => 'lettre_stage', 'description' => 'Lettre de stage pour la DGTCFM.'], []);
    expect($r->statut)->toBe('en_traitement');
    $agent = User::query()->find($r->agent_id);

    $this->service->deposerDocument($r, UploadedFile::fake()->createWithContent('lettre.pdf', '%PDF-1.4 essai'), $agent);

    expect($r->refresh()->statut)->toBe('document_disponible');
    $jeton = $this->auditeur->candidature->user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;
    expect($this->withToken($jeton)->get('/v1/applications/me/requetes/'.$r->uuid.'/document')->assertOk()->getContent())->toBe('%PDF-1.4 essai');
});

it('ouvre les requêtes à la scolarité et le jury des requêtes au chef de la commission', function (): void {
    $this->actingAs($this->agentA)->get(RequeteResource::getUrl('index'))->assertOk();
    $this->actingAs($this->chef)->get(SeanceRequetesResource::getUrl('index'))->assertOk();
    $this->actingAs(($this->compte)('librarian'))->get(RequeteResource::getUrl('index'))->assertForbidden();
});
