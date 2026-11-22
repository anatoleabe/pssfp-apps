<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\Deliberation;
use App\Models\DocumentDelivre;
use App\Models\EtapeSaisie;
use App\Models\InscriptionAnnuelle;
use App\Models\Jury;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\MembreJury;
use App\Models\NoteEcue;
use App\Models\User;
use App\Services\Lmd\Deliberations;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\PvDeliberation;
use App\Services\Lmd\SaisieNotes;
use App\Services\Supervision\Controles\NotApresArret;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses()->group('lmd');

beforeEach(function (): void {
    Storage::fake(CandidatureObjectStorage::DISK);
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    Maquette::query()->update(['statut' => Maquette::VALIDEE, 'validee_at' => now()]);
    $compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->responsable = $compte('scolarite_responsable');
    $this->agent = $compte('scolarite_agent');
    $this->president = $compte('membre_jury');
    $this->rapporteur = $compte('membre_jury');
    $this->deliberations = app(Deliberations::class);
    $this->saisie = app(SaisieNotes::class);

    $this->jury = Jury::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'nature' => 'deliberation',
        'objet' => 'Délibération M1', 'acte_reference' => 'NS 26/001', 'acte_date' => '2026-11-01', 'acte_signataire' => 'Le Doyen']);
    foreach ([['president', $this->president], ['rapporteur', $this->rapporteur]] as [$role, $u]) {
        MembreJury::query()->create(['jury_id' => $this->jury->id, 'role' => $role, 'nom' => 'Membre '.$role, 'user_id' => $u->id]);
    }

    // Un auditeur de M1 noté 14 partout, toutes les étapes de session normale verrouillées.
    $bareme = BaremeScolarite::query()->firstOrCreate(['annee_academique' => '2026-2027', 'niveau' => 'M1'],
        ['montant_inscription' => 185000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000]);
    $auditeur = Auditeur::factory()->campagneCommune()->create();
    $this->inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id]);
    app(InscriptionsPedagogiques::class)->inscrire($this->inscription);
    foreach ($this->inscription->inscriptionsPedagogiques()->with('maquetteUe.ecues')->get() as $ip) {
        foreach ($ip->maquetteUe->ecues as $ecue) {
            (new NoteEcue(['inscription_pedagogique_id' => $ip->id, 'maquette_ecue_id' => $ecue->id, 'session' => 'normale']))
                ->forceFill(['cc' => '14.00', 'assiduite' => '14.00', 'examen' => '14.00', 'presence_examen' => 'present'])->save();
        }
    }
    foreach (MaquetteEcue::query()->pluck('id') as $id) {
        foreach (['cc', 'assiduite', 'examen'] as $etape) {
            EtapeSaisie::query()->updateOrCreate(['maquette_ecue_id' => $id, 'session' => 'normale', 'etape' => $etape], [])
                ->forceFill(['statut' => EtapeSaisie::VERROUILLEE, 'verrouillee_at' => now()])->save();
        }
    }
    $ip = $this->inscription->inscriptionsPedagogiques()->with('maquetteUe.ecues')->first();
    $this->ecue = $ip->maquetteUe->ecues->first();
    $this->ip = $ip;

    /** Délibération M1 menée jusqu'à l'arrêt. */
    $this->arreter = function (string $session = 'normale', ?Deliberation $d = null): Deliberation {
        $d ??= $this->deliberations->creer(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'session' => $session, 'jury_id' => $this->jury->id], $this->agent);
        $s = $d->scenarios()->first();
        $this->deliberations->calculer($s, $this->agent);
        $presences = $this->jury->membres()->get()->mapWithKeys(fn (MembreJury $m): array => [$m->id => ['present' => true, 'motif_absence' => null]])->all();
        $this->deliberations->ouvrirSeance($d, $presences, $this->agent);
        $this->deliberations->retenir($s, $this->agent);
        $this->deliberations->arreter($d->refresh(), $this->responsable);

        return $d->refresh();
    };
    $this->etape = fn (string $session = 'normale', string $etape = 'cc'): EtapeSaisie => $this->saisie->etape($this->ecue, $session, $etape);
});

it('rouvre une étape avant l\'arrêt, plus après : le message désigne le PV et la rectification', function (): void {
    $this->saisie->rouvrir(($this->etape)(), $this->responsable, 'Erreur de report signalée par l\'enseignant');
    ($this->etape)()->forceFill(['statut' => EtapeSaisie::VERROUILLEE])->save();

    $d = ($this->arreter)();

    expect(fn () => $this->saisie->rouvrir(($this->etape)(), $this->responsable, 'Erreur de report signalée par l\'enseignant'))
        ->toThrow(InvalidArgumentException::class, $d->numero_pv);
    expect(($this->etape)()->statut)->toBe(EtapeSaisie::VERROUILLEE);
});

it('refuse aussi la saisie dans une étape restée ouverte sous une délibération arrêtée', function (): void {
    ($this->arreter)();
    ($this->etape)()->forceFill(['statut' => EtapeSaisie::OUVERTE])->save();

    expect(fn () => $this->saisie->saisir(($this->etape)(), [$this->ip->id => ['note' => '18', 'motif' => 'Correction après coup']], $this->responsable))
        ->toThrow(InvalidArgumentException::class, 'rectification');
    expect((string) NoteEcue::query()->where('inscription_pedagogique_id', $this->ip->id)->where('maquette_ecue_id', $this->ecue->id)->value('cc'))->toBe('14.00');
});

it('lève le gel pendant une rectification en préparation et le rétablit à son arrêt', function (): void {
    $d = ($this->arreter)();
    $r = $this->deliberations->rectifier($d, 'Erreur matérielle sur une note de contrôle continu', 'NS 26/045', $this->responsable);

    $this->saisie->rouvrir(($this->etape)(), $this->responsable, 'Rectification ouverte par la NS 26/045');
    $this->saisie->saisir(($this->etape)(), [$this->ip->id => ['note' => '15', 'motif' => 'Rectification NS 26/045']], $this->responsable);
    $this->saisie->verrouiller(($this->etape)(), $this->responsable);

    ($this->arreter)('normale', $r);

    expect(fn () => $this->saisie->rouvrir(($this->etape)(), $this->responsable, 'Nouvelle correction sans acte'))
        ->toThrow(InvalidArgumentException::class, 'PVD-2026-2027-0002');
});

it('ne gèle pas le rattrapage par une délibération de session normale, mais gèle tout par celle du rattrapage', function (): void {
    ($this->arreter)();
    $rattrapage = ($this->etape)('rattrapage', 'examen');

    $this->saisie->ouvrir($rattrapage, $this->responsable);
    $this->saisie->verrouiller($rattrapage->refresh(), $this->responsable);
    $this->saisie->rouvrir($rattrapage->refresh(), $this->responsable, 'Copie retrouvée après le verrouillage');
    foreach (MaquetteEcue::query()->pluck('id') as $id) {
        EtapeSaisie::query()->updateOrCreate(['maquette_ecue_id' => $id, 'session' => 'rattrapage', 'etape' => 'examen'], [])
            ->forceFill(['statut' => EtapeSaisie::VERROUILLEE, 'verrouillee_at' => now()])->save();
    }

    ($this->arreter)('rattrapage');

    expect(fn () => $this->saisie->rouvrir($rattrapage->refresh(), $this->responsable, 'Copie retrouvée après le verrouillage'))
        ->toThrow(InvalidArgumentException::class, 'PVD-2026-2027-0002');
    expect(fn () => $this->saisie->rouvrir(($this->etape)(), $this->responsable, 'Erreur de report signalée par l\'enseignant'))
        ->toThrow(InvalidArgumentException::class);
});

it('archive le PV à l\'arrêt et sert le même fichier à chaque téléchargement, même après signature', function (): void {
    $d = $this->deliberations->creer(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'session' => 'normale', 'jury_id' => $this->jury->id], $this->agent);
    expect(app(PvDeliberation::class)->pv($d))->toStartWith('%PDF')->and(DocumentDelivre::query()->count())->toBe(0);

    $d = ($this->arreter)('normale', $d);
    $doc = DocumentDelivre::query()->where('type', DocumentDelivre::PV)->where('deliberation_id', $d->id)->sole();
    $premier = app(PvDeliberation::class)->pv($d);

    expect($doc->numero)->toBe($d->numero_pv)
        ->and($doc->auditeur_id)->toBeNull()
        ->and($doc->sha256)->toBe(hash('sha256', $premier))
        ->and($doc->code_verification)->toMatch('/^[A-Z0-9]{12}$/');

    $this->deliberations->signer($d, $this->president);
    expect(app(PvDeliberation::class)->pv($d->refresh()))->toBe($premier)
        ->and(DocumentDelivre::query()->where('type', DocumentDelivre::PV)->count())->toBe(1);
});

it('refuse de servir un PV archivé dont le fichier a été altéré', function (): void {
    $d = ($this->arreter)();
    $doc = DocumentDelivre::query()->where('type', DocumentDelivre::PV)->sole();
    Storage::disk(CandidatureObjectStorage::DISK)->put($doc->chemin, '%PDF falsifié');

    expect(fn () => app(PvDeliberation::class)->pv($d))->toThrow(InvalidArgumentException::class, 'indisponible');
});

it('fait vérifier le PV en ligne sans rien révéler des résultats, puis le révoque à la rectification', function (): void {
    $d = ($this->arreter)();
    $doc = DocumentDelivre::query()->where('type', DocumentDelivre::PV)->sole();

    $this->getJson('/v1/documents/verification/'.$doc->code_verification)->assertOk()
        ->assertJsonPath('data.statut', 'authentique')
        ->assertJsonPath('data.numero', $d->numero_pv)
        ->assertJsonPath('data.libelle', 'Procès-verbal de délibération')
        ->assertJsonMissing(['titulaire' => trim(mb_strtoupper((string) $this->inscription->auditeur->candidature->nom).' '.$this->inscription->auditeur->candidature->prenom)]);

    $r = $this->deliberations->rectifier($d, 'Erreur matérielle sur une note de contrôle continu', 'NS 26/045', $this->responsable);
    ($this->arreter)('normale', $r);

    $this->getJson('/v1/documents/verification/'.$doc->code_verification)->assertOk()->assertJsonPath('data.statut', 'revoque');
    expect(DocumentDelivre::query()->where('type', DocumentDelivre::PV)->whereNull('revoque_at')->sole()->numero)->toBe('PVD-2026-2027-0002')
        ->and(app(PvDeliberation::class)->pv($d->refresh()))->toStartWith('%PDF');
});

it('exige le scan du PV signé à la publication quand une signature a été constatée sur papier', function (): void {
    $d = ($this->arreter)();
    $this->deliberations->signer($d, $this->president);
    $rapporteur = $this->jury->membres()->where('role', 'rapporteur')->sole();
    $this->deliberations->constaterSignature($d, $rapporteur, $this->responsable);

    expect(fn () => $this->deliberations->publier($d, $this->responsable))->toThrow(InvalidArgumentException::class, 'scan');

    $scan = UploadedFile::fake()->createWithContent('pv-signe.pdf', "%PDF-1.4\nPV signé");
    $this->deliberations->publier($d, $this->responsable, $scan->getRealPath());

    $d->refresh();
    expect($d->publiee_at)->not->toBeNull()
        ->and($d->pv_signe_sha256)->toBe(hash('sha256', "%PDF-1.4\nPV signé"))
        ->and(Storage::disk(CandidatureObjectStorage::DISK)->exists($d->pv_signe_chemin))->toBeTrue();
});

it('publie sans scan quand toutes les signatures sont électroniques, et refuse un scan qui n\'est pas un PDF', function (): void {
    $d = ($this->arreter)();
    $this->deliberations->signer($d, $this->president);
    $this->deliberations->signer($d, $this->rapporteur);

    $image = UploadedFile::fake()->createWithContent('pv.pdf', 'GIF89a');
    expect(fn () => $this->deliberations->publier($d, $this->responsable, $image->getRealPath()))->toThrow(InvalidArgumentException::class, 'PDF');

    $this->deliberations->publier($d, $this->responsable);
    expect($d->refresh()->publiee_at)->not->toBeNull()->and($d->pv_signe_chemin)->toBeNull();
});

it('signale une note modifiée après l\'arrêt, et se tait pour une requête appliquée ou une rectification ouverte', function (): void {
    $d = ($this->arreter)();
    expect(app(NotApresArret::class)->cas())->toBe([]);

    $this->travelTo(now()->addHour());
    NoteEcue::query()->where('inscription_pedagogique_id', $this->ip->id)->where('maquette_ecue_id', $this->ecue->id)->first()
        ->forceFill(['cc' => '19.00'])->save();

    $cas = app(NotApresArret::class)->cas();
    expect($cas)->toHaveKey('deliberation:'.$d->id)
        ->and($cas['deliberation:'.$d->id]['resume'])->toContain($d->numero_pv)->toContain('1 note');

    $d->forceFill(['a_rectifier_at' => now(), 'a_rectifier_motif' => 'Requête REQ-1 appliquée après l\'arrêt'])->save();
    expect(app(NotApresArret::class)->cas())->toBe([]);

    $d->forceFill(['a_rectifier_at' => null, 'a_rectifier_motif' => null])->save();
    $this->deliberations->rectifier($d, 'Erreur matérielle sur une note de contrôle continu', 'NS 26/045', $this->responsable);
    expect(app(NotApresArret::class)->cas())->toBe([]);
});

it('archive par la commande les PV arrêtés avant ce lot, sans doublon, et révoque celui d\'une délibération rectifiée', function (): void {
    $d = ($this->arreter)();
    $r = $this->deliberations->rectifier($d, 'Erreur matérielle sur une note de contrôle continu', 'NS 26/045', $this->responsable);
    ($this->arreter)('normale', $r);
    DocumentDelivre::query()->where('type', DocumentDelivre::PV)->delete();

    $this->artisan('lmd:archiver-pv')->expectsOutputToContain('2 PV à archiver')->assertSuccessful();
    expect(DocumentDelivre::query()->count())->toBe(0);

    $this->artisan('lmd:archiver-pv', ['--appliquer' => true])->assertSuccessful();
    $this->artisan('lmd:archiver-pv', ['--appliquer' => true])->expectsOutputToContain('0 PV à archiver')->assertSuccessful();

    expect(DocumentDelivre::query()->where('type', DocumentDelivre::PV)->count())->toBe(2)
        ->and(DocumentDelivre::query()->where('deliberation_id', $d->id)->sole()->revoque_at)->not->toBeNull()
        ->and(DocumentDelivre::query()->where('deliberation_id', $r->id)->sole()->revoque_at)->toBeNull();
});
