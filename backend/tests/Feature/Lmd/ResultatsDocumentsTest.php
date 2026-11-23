<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentDelivreResource;
use App\Filament\Resources\DocumentDelivreResource\Pages\ListDocumentsDelivres;
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
use App\Services\Lmd\NumerotationSemestres;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    $doc = DocumentDelivre::query()->where('type', DocumentDelivre::RELEVE)->sole();
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

it('refuse l\'attestation et le relevé à un auditeur en retard de paiement, puis les délivre après une levée tracée', function (): void {
    ($this->publier)();
    $this->admis->bareme->update(['tranche1_echeance' => now()->subMonth()->toDateString()]);
    Versement::factory()->create(['inscription_annuelle_id' => $this->admis->id, 'montant' => 185000]);
    $url = '/v1/applications/me/documents-officiels/attestation_reussite?deliberation='.$this->d->uuid;

    $this->withToken(($this->jeton)($this->admis))->get($url)->assertStatus(409)->assertJsonPath('code', 'document_indisponible');
    // Décision D5 (lot 19) : le relevé de notes est retenu lui aussi ; les résultats restent visibles, avec le motif.
    $releve = '/v1/applications/me/documents-officiels/releve_notes?deliberation='.$this->d->uuid;
    $this->withToken(($this->jeton)($this->admis))->get($releve)->assertStatus(409)->assertJsonPath('code', 'document_indisponible');
    $this->withToken(($this->jeton)($this->admis))->getJson('/v1/applications/me/resultats')->assertOk()
        ->assertJsonPath('data.resultats.0.decision', 'AD')
        ->assertJsonPath('data.resultats.0.documents.releve_notes', 'Une échéance de vos frais de scolarité est en retard : rapprochez-vous de la scolarité.');
    $documents = app(DocumentsOfficiels::class);
    // Les deux auditeurs partagent le barème : tous deux sont en retard, donc non autorisés à composer.
    expect(array_keys($documents->nonEnRegle([$this->admis->id, $this->ajourne->id])))->toEqualCanonicalizing([$this->admis->id, $this->ajourne->id]);

    $documents->leverRestriction($this->admis->refresh(), 'Échéancier signé le 07/11/2026', $this->responsable);
    $this->withToken(($this->jeton)($this->admis))->get($url)->assertOk();
    $this->withToken(($this->jeton)($this->admis))->get($releve)->assertOk();
    expect($documents->nonEnRegle([$this->admis->id, $this->ajourne->id]))->toBe([$this->ajourne->id => true]);
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

it('ne sert le certificat de scolarité à l\'auditeur qu\'une fois délivré par la scolarité', function (): void {
    $jeton = ($this->jeton)($this->admis);
    // Lot 25 : l'auditeur ne le produit plus lui-même.
    $this->withToken($jeton)->get('/v1/applications/me/documents-officiels/certificat_scolarite')->assertStatus(409);
    $this->withToken($jeton)->getJson('/v1/applications/me/resultats')->assertOk()->assertJsonPath('data.certificat_scolarite', fn (?string $m): bool => str_contains((string) $m, 'délivré par la scolarité'));
    expect(DocumentDelivre::query()->where('type', 'certificat_scolarite')->exists())->toBeFalse();

    $documents = app(DocumentsOfficiels::class);
    $financier = tap(User::factory()->create(['email' => 'fin-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('service_financier'));
    expect(fn () => $documents->delivrerCertificat($this->admis->auditeur, $financier))->toThrow(HttpException::class);
    $document = $documents->delivrerCertificat($this->admis->auditeur, $this->responsable);

    app('auth')->forgetGuards();
    $this->withToken($jeton)->get('/v1/applications/me/documents-officiels/certificat_scolarite')->assertOk();
    $this->withToken($jeton)->getJson('/v1/applications/me/resultats')->assertOk()->assertJsonPath('data.certificat_scolarite', null);
    expect($document->numero)->toBe('CS-2026-000001')
        ->and($documents->delivrerCertificat($this->admis->auditeur, $this->responsable)->id)->toBe($document->id);
});

it('constate sur le PV papier la signature d\'un membre sans compte, de façon tracée', function (): void {
    $sansCompte = MembreJury::query()->create(['jury_id' => $this->d->jury_id, 'role' => 'membre', 'nom' => 'Personnalité extérieure']);
    ParticipationDeliberation::query()->create(['deliberation_id' => $this->d->id, 'membre_jury_id' => $sansCompte->id, 'present' => true]);
    $this->service->signer($this->d->refresh(), $this->responsable);
    $this->service->signer($this->d->refresh(), $this->rapporteur);
    expect(fn () => $this->service->publier($this->d->refresh(), $this->responsable))->toThrow(InvalidArgumentException::class, 'Personnalité extérieure');

    $this->service->constaterSignature($this->d->refresh(), $sansCompte, $this->responsable);

    // Lot 16a : une signature constatée sur papier exige le scan du PV signé.
    $scan = tempnam(sys_get_temp_dir(), 'pv');
    file_put_contents($scan, "%PDF-1.4\nPV signé");
    $this->service->publier($this->d->refresh(), $this->responsable, $scan);
    expect(ParticipationDeliberation::query()->where('membre_jury_id', $sansCompte->id)->value('signature_constatee_par'))->toBe($this->responsable->id);
});

it('donne dans les résultats le semestre à afficher selon la promotion, le semestre enregistré restant le même', function (): void {
    ($this->publier)();

    $this->withToken(($this->jeton)($this->admis))->getJson('/v1/applications/me/resultats')->assertOk()
        ->assertJsonPath('data.resultats.0.ues.0.semestre', 7)
        ->assertJsonPath('data.resultats.0.ues.0.semestre_affiche', 1);

    $this->admis->auditeur->candidature->campagne->update(['numerotation_semestres' => NumerotationSemestres::LMD]);
    app('auth')->forgetGuards();
    $this->withToken(($this->jeton)($this->admis))->getJson('/v1/applications/me/resultats')->assertOk()
        ->assertJsonPath('data.resultats.0.ues.0.semestre', 7)
        ->assertJsonPath('data.resultats.0.ues.0.semestre_affiche', 7);
});

it('numérote les semestres d\'un nouveau relevé selon la promotion, sans jamais refaire un relevé délivré', function (): void {
    ($this->publier)();
    $donnees = [];
    $capture = true;
    View::composer('pdf.lmd.releve-notes', function ($view) use (&$donnees, &$capture): void {
        if ($capture) {
            $donnees[] = $view->getData();
        }
    });
    $html = function (array $d) use (&$capture): string {
        $capture = false;
        $rendu = view('pdf.lmd.releve-notes', $d)->render();
        $capture = true;

        return $rendu;
    };
    $url = '/v1/applications/me/documents-officiels/releve_notes?deliberation='.$this->d->uuid;

    // Réglage par défaut : S1 et S2 pour le M1.
    $premier = $this->withToken(($this->jeton)($this->admis))->get($url)->assertOk()->getContent();
    expect($donnees)->toHaveCount(1)
        ->and($html($donnees[0]))->toContain('<td>S1</td>')->toContain('<td>S2</td>')->not->toContain('<td>S7</td>');

    // La promotion passe en LMD : le relevé délivré reste le même fichier, sans nouveau rendu.
    $this->admis->auditeur->candidature->campagne->update(['numerotation_semestres' => NumerotationSemestres::LMD]);
    app('auth')->forgetGuards();
    $second = $this->withToken(($this->jeton)($this->admis))->get($url)->assertOk()->getContent();
    $doc = DocumentDelivre::query()->where('type', DocumentDelivre::RELEVE)->sole();
    expect($second)->toBe($premier)
        ->and($donnees)->toHaveCount(1)
        ->and($doc->sha256)->toBe(hash('sha256', $premier))
        ->and(Storage::disk(CandidatureObjectStorage::DISK)->get($doc->chemin))->toBe($premier);

    // Un nouveau relevé de la promotion suit le réglage : S7 et S8.
    app('auth')->forgetGuards();
    $this->withToken(($this->jeton)($this->ajourne))->get($url)->assertOk();
    expect($donnees)->toHaveCount(2)
        ->and($html($donnees[1]))->toContain('<td>S7</td>')->toContain('<td>S8</td>')->not->toContain('<td>S1</td>');
});

it('dit si le PDF présenté est bien celui délivré, sans jamais renvoyer l\'empreinte conservée', function (): void {
    ($this->publier)();
    $url = '/v1/applications/me/documents-officiels/releve_notes?deliberation='.$this->d->uuid;
    $pdf = $this->withToken(($this->jeton)($this->admis))->get($url)->assertOk()->getContent();
    $doc = DocumentDelivre::query()->where('type', DocumentDelivre::RELEVE)->sole();
    app('auth')->forgetGuards();
    $verif = '/v1/documents/verification/'.$doc->code_verification;

    $this->getJson($verif)->assertOk()->assertJsonMissingPath('data.conforme');
    $this->getJson($verif.'?empreinte='.hash('sha256', $pdf))->assertOk()->assertJsonPath('data.conforme', true)->assertJsonMissing(['sha256' => $doc->sha256]);
    $this->getJson($verif.'?empreinte='.hash('sha256', $pdf.' falsifié'))->assertOk()->assertJsonPath('data.conforme', false)->assertJsonPath('data.statut', 'authentique');
    $this->getJson($verif.'?empreinte=pas-une-empreinte')->assertOk()->assertJsonMissingPath('data.conforme');
});

it('tient le registre des documents délivrés : liste, téléchargement tracé et révocation, réservés à la scolarité', function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    ($this->publier)();
    $releve = app(DocumentsOfficiels::class)->obtenir(DocumentDelivre::RELEVE, $this->admis->auditeur, $this->d->refresh());
    $pv = DocumentDelivre::query()->where('type', DocumentDelivre::PV)->sole();

    $this->actingAs($this->rapporteur)->get(DocumentDelivreResource::getUrl())->assertForbidden();
    $this->actingAs($this->responsable)->get(DocumentDelivreResource::getUrl())->assertOk();

    Livewire::test(ListDocumentsDelivres::class)
        ->assertCanSeeTableRecords([$releve, $pv])
        ->assertTableActionVisible('telecharger', $releve)
        ->assertTableActionHidden('revoquer', $pv)
        ->callTableAction('telecharger', $releve)->assertFileDownloaded(mb_strtolower($releve->numero).'.pdf')
        ->callTableAction('revoquer', $releve, ['motif' => 'Erreur sur le nom du titulaire'])->assertHasNoTableActionErrors()
        ->filterTable('revoque', true)->assertCanSeeTableRecords([$releve])->assertCanNotSeeTableRecords([$pv]);

    expect($releve->refresh()->estRevoque())->toBeTrue()
        ->and(Activity::query()->where('event', 'document_consulte')->sole()->causer_id)->toBe($this->responsable->id);
});
