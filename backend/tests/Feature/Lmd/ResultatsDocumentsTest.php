<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\DocumentDelivre;
use App\Models\EtapeSaisie;
use App\Models\InscriptionAnnuelle;
use App\Models\Jury;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\MembreJury;
use App\Models\NoteEcue;
use App\Models\ParticipationDeliberation;
use App\Models\User;
use App\Models\Versement;
use App\Services\Lmd\Deliberations;
use App\Services\Lmd\DocumentsOfficiels;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses()->group('lmd', 'applications');

beforeEach(function (): void {
    Storage::fake(CandidatureObjectStorage::DISK);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    Maquette::query()->update(['statut' => Maquette::VALIDEE, 'validee_at' => now()]);
    $this->responsable = tap(User::factory()->create(['email' => 'resp-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('scolarite_responsable'));
    $this->service = app(Deliberations::class);
    $bareme = BaremeScolarite::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'montant_inscription' => 185000,
        'montant_tranche1' => 500000, 'montant_tranche2' => 500000, 'tranche1_echeance' => now()->addMonth()->toDateString()]);

    $creer = function (float $note) use ($bareme): InscriptionAnnuelle {
        $auditeur = Auditeur::factory()->campagneCommune()->create();
        $auditeur->candidature->forceFill(['user_id' => User::factory()->create()->id])->save();
        $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id]);
        app(InscriptionsPedagogiques::class)->inscrire($i);
        foreach ($i->inscriptionsPedagogiques()->with('maquetteUe.ecues')->get() as $ip) {
            foreach ($ip->maquetteUe->ecues as $e) {
                $v = number_format($note, 2, '.', '');
                (new NoteEcue(['inscription_pedagogique_id' => $ip->id, 'maquette_ecue_id' => $e->id, 'session' => 'normale']))
                    ->forceFill(['cc' => $v, 'assiduite' => $v, 'examen' => $v, 'presence_examen' => 'present'])->save();
            }
        }

        return $i;
    };
    $this->admis = $creer(14);
    $this->ajourne = $creer(9);
    foreach (MaquetteEcue::query()->pluck('id') as $id) {
        foreach (['cc', 'assiduite', 'examen'] as $etape) {
            EtapeSaisie::query()->create(['maquette_ecue_id' => $id, 'session' => 'normale', 'etape' => $etape])
                ->forceFill(['statut' => EtapeSaisie::VERROUILLEE, 'verrouillee_at' => now()])->save();
        }
    }
    $jury = Jury::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'nature' => 'deliberation', 'objet' => 'M1',
        'acte_reference' => 'NS 1', 'acte_date' => '2026-11-01', 'acte_signataire' => 'Le Doyen']);
    $p = MembreJury::query()->create(['jury_id' => $jury->id, 'role' => 'president', 'nom' => 'P', 'user_id' => $this->responsable->id]);
    $this->rapporteur = tap(User::factory()->create(['email' => 'rap-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('membre_jury'));
    $r = MembreJury::query()->create(['jury_id' => $jury->id, 'role' => 'rapporteur', 'nom' => 'R', 'user_id' => $this->rapporteur->id]);
    $this->d = $this->service->creer(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'session' => 'normale', 'jury_id' => $jury->id], $this->responsable);
    $s = $this->d->scenarios()->first();
    $this->service->calculer($s, $this->responsable);
    $this->service->ouvrirSeance($this->d, [$p->id => ['present' => true], $r->id => ['present' => true]], $this->responsable);
    $this->service->retenir($s, $this->responsable);
    $this->service->arreter($this->d->refresh(), $this->responsable);
    $this->publier = function (): void {
        $this->service->signer($this->d->refresh(), $this->responsable);
        $this->service->signer($this->d->refresh(), $this->rapporteur);
        $this->service->publier($this->d->refresh(), $this->responsable);
    };
    $this->jeton = fn (InscriptionAnnuelle $i): string => $i->auditeur->candidature->user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;
});

it('ne montre rien avant la publication, puis les résultats de l\'auditeur seul', function (): void {
    $this->withToken(($this->jeton)($this->admis))->getJson('/v1/applications/me/resultats')->assertOk()->assertJsonCount(0, 'data.resultats');
    expect(fn () => app(DocumentsOfficiels::class)->obtenir(DocumentDelivre::RELEVE, $this->admis->auditeur, $this->d))
        ->toThrow(InvalidArgumentException::class, 'pas encore publiés');

    ($this->publier)();

    $r = $this->withToken(($this->jeton)($this->admis))->getJson('/v1/applications/me/resultats')->assertOk()
        ->assertJsonCount(1, 'data.resultats')
        ->assertJsonPath('data.resultats.0.decision', 'AD')
        ->assertJsonPath('data.resultats.0.credits', 60)
        ->assertJsonPath('data.resultats.0.ues.0.code', 'MPMPP71')
        ->assertJsonPath('data.resultats.0.ues.0.mode', 'acquise')
        ->assertJsonPath('data.resultats.0.documents.attestation_reussite', null);
    expect($r->getContent())->not->toContain((string) $this->ajourne->auditeur->candidature->matricule);

    app('auth')->forgetGuards();
    $this->withToken(($this->jeton)($this->ajourne))->getJson('/v1/applications/me/resultats')->assertOk()
        ->assertJsonPath('data.resultats.0.decision', 'AJ')
        ->assertJsonPath('data.resultats.0.documents.attestation_reussite', 'L\'attestation de réussite n\'est délivrée qu\'aux admis et aux autorisés à soutenir.');
});

it('délivre un relevé numéroté, vérifiable, et sert le même fichier ensuite', function (): void {
    ($this->publier)();
    $url = '/v1/applications/me/documents-officiels/releve_notes?deliberation='.$this->d->uuid;

    $premier = $this->withToken(($this->jeton)($this->admis))->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
    $second = $this->withToken(($this->jeton)($this->admis))->get($url)->assertOk()->getContent();

    $doc = DocumentDelivre::query()->sole();
    expect($premier)->toBe($second)->toStartWith('%PDF')
        ->and($doc->numero)->toBe('RN-2026-000001')
        ->and($doc->sha256)->toBe(hash('sha256', $premier));

    $this->getJson('/v1/documents/verification/'.$doc->code_verification)->assertOk()
        ->assertJsonPath('data.statut', 'authentique')
        ->assertJsonPath('data.numero', 'RN-2026-000001')
        ->assertJsonMissingPath('data.matricule');
    app(DocumentsOfficiels::class)->revoquer($doc, 'Erreur matérielle de saisie', $this->responsable);
    $this->getJson('/v1/documents/verification/'.$doc->code_verification)->assertOk()->assertJsonPath('data.statut', 'revoque');
    $this->getJson('/v1/documents/verification/INCONNU12345')->assertNotFound();
});

it('refuse l\'attestation à un auditeur en retard de paiement, puis la délivre après une levée tracée', function (): void {
    ($this->publier)();
    $this->admis->bareme->update(['tranche1_echeance' => now()->subMonth()->toDateString()]);
    Versement::factory()->create(['inscription_annuelle_id' => $this->admis->id, 'montant' => 185000]);
    $url = '/v1/applications/me/documents-officiels/attestation_reussite?deliberation='.$this->d->uuid;

    $this->withToken(($this->jeton)($this->admis))->get($url)->assertStatus(409)->assertJsonPath('code', 'document_indisponible');
    $this->withToken(($this->jeton)($this->admis))->get('/v1/applications/me/documents-officiels/releve_notes?deliberation='.$this->d->uuid)->assertOk();

    app(DocumentsOfficiels::class)->leverRestriction($this->admis->refresh(), 'Échéancier signé le 07/11/2026', $this->responsable);
    $this->withToken(($this->jeton)($this->admis))->get($url)->assertOk();
    expect(DocumentDelivre::query()->where('type', 'attestation_reussite')->value('numero'))->toBe('AR-2026-000001');
});

it('révoque les relevés de la délibération rectifiée', function (): void {
    ($this->publier)();
    $doc = app(DocumentsOfficiels::class)->obtenir(DocumentDelivre::RELEVE, $this->admis->auditeur, $this->d);
    $rect = $this->service->rectifier($this->d->refresh(), 'Requête fondée sur une note', 'NS 2', $this->responsable);
    $s = $rect->scenarios()->first();
    $this->service->calculer($s, $this->responsable);
    $membres = $rect->jury->membres()->pluck('id');
    $this->service->ouvrirSeance($rect, $membres->mapWithKeys(fn ($id) => [$id => ['present' => true]])->all(), $this->responsable);
    $this->service->retenir($s->refresh(), $this->responsable);
    $this->service->arreter($rect->refresh(), $this->responsable);

    expect($doc->refresh()->estRevoque())->toBeTrue()->and($doc->revoque_motif)->toContain('PVD-2026-2027-0002');
});

it('délivre le certificat de scolarité de l\'année en cours', function (): void {
    $this->withToken(($this->jeton)($this->admis))->get('/v1/applications/me/documents-officiels/certificat_scolarite')->assertOk();
    expect(DocumentDelivre::query()->where('type', 'certificat_scolarite')->value('numero'))->toBe('CS-2026-000001');
});

it('constate sur le PV papier la signature d\'un membre sans compte, de façon tracée', function (): void {
    $sansCompte = MembreJury::query()->create(['jury_id' => $this->d->jury_id, 'role' => 'membre', 'nom' => 'Personnalité extérieure']);
    ParticipationDeliberation::query()->create(['deliberation_id' => $this->d->id, 'membre_jury_id' => $sansCompte->id, 'present' => true]);
    $this->service->signer($this->d->refresh(), $this->responsable);
    $this->service->signer($this->d->refresh(), $this->rapporteur);
    expect(fn () => $this->service->publier($this->d->refresh(), $this->responsable))->toThrow(InvalidArgumentException::class, 'Personnalité extérieure');

    $this->service->constaterSignature($this->d->refresh(), $sansCompte, $this->responsable);

    $this->service->publier($this->d->refresh(), $this->responsable);
    expect(ParticipationDeliberation::query()->where('membre_jury_id', $sansCompte->id)->value('signature_constatee_par'))->toBe($this->responsable->id);
});
