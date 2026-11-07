<?php

declare(strict_types=1);

use App\Filament\Resources\DossierMemoireResource;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\Deliberation;
use App\Models\DocumentDelivre;
use App\Models\DossierMemoire;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Services\Lmd\DocumentsOfficiels;
use App\Services\Lmd\Memoires;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd', 'applications');

beforeEach(function (): void {
    Storage::fake(CandidatureObjectStorage::DISK);
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->responsable = tap(User::factory()->create(['email' => 'resp-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('scolarite_responsable'));
    $this->agent = tap(User::factory()->create(['email' => 'agent-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('scolarite_agent'));
    $bareme = BaremeScolarite::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M2', 'montant_inscription' => 185000,
        'montant_tranche1' => 500000, 'montant_tranche2' => 500000, 'tranche1_echeance' => now()->addMonth()->toDateString()]);
    $auditeur = Auditeur::factory()->campagneCommune()->create();
    $auditeur->candidature->forceFill(['user_id' => User::factory()->create()->id])->save();
    $this->inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id, 'niveau' => 'M2']);
    $this->service = app(Memoires::class);
    $this->dossier = $this->service->ouvrir($this->inscription);

    // Dossier complet : toutes les conditions de l'attestation remplies.
    $this->completer = function (string $taux = '12,4'): void {
        $this->dossier->forceFill(['theme' => 'La budgétisation par programmes dans les collectivités', 'encadreur_academique' => 'Pr Encadreur'])->save();
        foreach (['cadrage', 'depot_projet', 'presoutenance1', 'presoutenance2'] as $e) {
            $this->service->evaluer($this->dossier, $e, 'valide', str_starts_with($e, 'presoutenance') ? '14' : null, null, $this->agent);
        }
        $this->service->ajouterAvis($this->dossier, 'academique', true, null, $this->agent);
        $this->service->ajouterRapport($this->dossier, 'Compilatio', $taux, now()->toDateString(), $this->agent);
    };
});

function conditionsManquantes(DossierMemoire $d): array
{
    return collect(app(Memoires::class)->conditions($d->refresh()))->reject(fn (array $c): bool => $c['ok'])->pluck('cle')->values()->all();
}

it('ouvre un dossier avec ses sept étapes pondérées, une seule fois', function (): void {
    expect($this->dossier->etapes()->pluck('poids', 'etape')->all())->toBe(['debut' => 0, 'cadrage' => 5, 'depot_projet' => 15,
        'presoutenance1' => 30, 'presoutenance2' => 30, 'depot_final' => 10, 'soutenance' => 10])
        ->and($this->service->ouvrir($this->inscription)->id)->toBe($this->dossier->id)
        ->and(DossierMemoire::query()->count())->toBe(1);
});

it('liste les conditions manquantes d\'un dossier vide et refuse l\'attestation avec elles', function (): void {
    expect(conditionsManquantes($this->dossier))->toBe(['theme', 'encadreur', 'etapes', 'avis', 'similitude'])
        ->and(fn () => $this->service->delivrerAttestation($this->dossier, $this->responsable))
        ->toThrow(InvalidArgumentException::class, 'Conditions manquantes : Thème enregistré');
});

it('bloque sur un avis défavorable, le plus récent faisant foi', function (): void {
    ($this->completer)();
    $this->service->ajouterAvis($this->dossier, 'academique', false, 'Chapitre 3 à reprendre', $this->agent);
    expect(conditionsManquantes($this->dossier))->toBe(['avis']);
    $this->service->ajouterAvis($this->dossier, 'academique', true, null, $this->agent);
    expect(conditionsManquantes($this->dossier))->toBe([]);
});

it('bloque à 27,5 % de similitude et passe à 12,4 %', function (): void {
    ($this->completer)('27,5');
    expect(conditionsManquantes($this->dossier))->toBe(['similitude']);
    $this->service->ajouterRapport($this->dossier, 'Compilatio', '12,4', now()->toDateString(), $this->agent);
    expect(conditionsManquantes($this->dossier))->toBe([]);
});

it('bloque sur une étape validée en retard jusqu\'à l\'autorisation du chef de la scolarité', function (): void {
    ($this->completer)();
    $this->dossier->etapes()->where('etape', 'cadrage')->update(['date_prevue' => now()->subDays(10)->toDateString(), 'realisee_at' => now()]);
    expect(conditionsManquantes($this->dossier))->toBe(['delais'])
        ->and(fn () => $this->service->autoriserRetard($this->dossier, 'cadrage', 'Mission de service', $this->agent))->toThrow(HttpException::class);
    $this->service->autoriserRetard($this->dossier, 'cadrage', 'Mission de service à l\'étranger', $this->responsable);
    expect(conditionsManquantes($this->dossier))->toBe([]);
});

it('refuse l\'attestation à l\'agent, la délivre au chef de la scolarité, une seule à la fois', function (): void {
    ($this->completer)();
    expect(fn () => $this->service->delivrerAttestation($this->dossier, $this->agent))->toThrow(HttpException::class);
    $doc = $this->service->delivrerAttestation($this->dossier, $this->responsable);
    expect($doc->type)->toBe(DocumentDelivre::AUTORISATION_DEPOT)
        ->and($doc->numero)->toBe('AD-2026-000001')
        ->and(Storage::disk(CandidatureObjectStorage::DISK)->exists($doc->chemin))->toBeTrue()
        ->and(fn () => $this->service->delivrerAttestation($this->dossier, $this->responsable))->toThrow(InvalidArgumentException::class, 'attestation valide existe déjà');
    $this->getJson('/v1/documents/verification/'.$doc->code_verification)->assertOk()
        ->assertJsonPath('data.libelle', 'Attestation d\'autorisation de dépôt du mémoire');
    app(DocumentsOfficiels::class)->revoquer($doc, 'Erreur sur le thème', $this->responsable);
    expect($this->service->delivrerAttestation($this->dossier, $this->responsable)->numero)->toBe('AD-2026-000002');
});

it('refuse le dépôt final sans attestation et l\'accepte avec', function (): void {
    $pdf = fn () => UploadedFile::fake()->createWithContent('memoire.pdf', "%PDF-1.4\n%memoire\n");
    expect(fn () => $this->service->deposer($this->dossier, 'depot_final', $pdf()))->toThrow(InvalidArgumentException::class, 'attestation');
    ($this->completer)();
    $this->service->delivrerAttestation($this->dossier, $this->responsable);
    $etape = $this->service->deposer($this->dossier, 'depot_final', $pdf());
    expect($etape->statut)->toBe('depose')
        ->and($etape->document_sha256)->toBe(hash('sha256', "%PDF-1.4\n%memoire\n"))
        ->and(fn () => $this->service->deposer($this->dossier, 'cadrage', UploadedFile::fake()->create('x.docx', 10)))->toThrow(InvalidArgumentException::class);
});

it('calcule l\'avancement : validé jusqu\'au dépôt final, 90 % et prêt à soutenir', function (): void {
    ($this->completer)();
    $this->service->evaluer($this->dossier, 'debut', 'valide', null, null, $this->agent);
    $this->service->delivrerAttestation($this->dossier, $this->responsable);
    $this->service->evaluer($this->dossier, 'depot_final', 'valide', null, null, $this->agent);
    expect($this->service->avancement($this->dossier->refresh())['pourcentage'])->toBe(90)
        ->and($this->dossier->statut)->toBe('pret');
    $this->service->evaluer($this->dossier, 'soutenance', 'valide', '15', null, $this->agent);
    expect($this->dossier->refresh()->statut)->toBe('soutenu');
});

it('exige une appréciation pour ajourner et une note entre 0 et 20', function (): void {
    expect(fn () => $this->service->evaluer($this->dossier, 'presoutenance1', 'ajourne', null, '', $this->agent))->toThrow(InvalidArgumentException::class, 'appréciation')
        ->and(fn () => $this->service->evaluer($this->dossier, 'presoutenance1', 'valide', '21', null, $this->agent))->toThrow(InvalidArgumentException::class, 'entre 0 et 20');
});

it('ouvre les dossiers des seuls autorisés à soutenir d\'une délibération arrêtée', function (): void {
    $d = (new Deliberation)->forceFill(['annee_academique' => '2026-2027', 'niveau' => 'M2', 'session' => 'normale', 'statut' => Deliberation::ARRETEE,
        'numero_pv' => 'PVD-2026-2027-0009', 'pv_sha256' => str_repeat('a', 64), 'arretee_at' => now()]);
    $d->save();
    $ajourne = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory()->campagneCommune()->create()->id, 'bareme_id' => $this->inscription->bareme_id, 'niveau' => 'M2']);
    $as = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory()->campagneCommune()->create()->id, 'bareme_id' => $this->inscription->bareme_id, 'niveau' => 'M2']);
    foreach ([[$as, 'AS'], [$ajourne, 'AJ'], [$this->inscription, 'AS']] as [$i, $decision]) {
        DB::table('resultats_annuels')->insert(['deliberation_id' => $d->id, 'inscription_annuelle_id' => $i->id, 'credits_acquis' => 44, 'credits_annee' => 60,
            'decision_calculee' => $decision, 'decision' => $decision]);
    }

    expect(fn () => $this->service->ouvrirPourDeliberation($d, ($this->compte)('librarian')))->toThrow(HttpException::class)
        ->and($this->service->ouvrirPourDeliberation($d, $this->agent))->toBe(1)
        ->and(DossierMemoire::query()->where('inscription_annuelle_id', $as->id)->value('deliberation_id'))->toBe($d->id)
        ->and(DossierMemoire::query()->where('inscription_annuelle_id', $ajourne->id)->exists())->toBeFalse();
});

it('sert à l\'auditeur son mémoire, accepte ses dépôts PDF, refuse le dépôt en consultation et sert l\'attestation', function (): void {
    $user = $this->inscription->auditeur->candidature->user;
    $jeton = $user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;

    $this->withToken($jeton)->getJson('/v1/applications/me/memoire')->assertOk()
        ->assertJsonPath('data.avancement.pourcentage', 0)
        ->assertJsonPath('data.etapes.1.etape', 'cadrage')
        ->assertJsonPath('data.etapes.1.deposable', true)
        ->assertJsonPath('data.etapes.5.deposable', false)
        ->assertJsonPath('data.conditions.0.ok', false)
        ->assertJsonPath('data.attestation', null);
    $this->withToken($jeton)->post('/v1/applications/me/memoire/depots/cadrage', ['fichier' => UploadedFile::fake()->createWithContent('cadrage.pdf', "%PDF-1.4\n%cadrage\n")],
        ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.statut', 'depose');
    $this->withToken($jeton)->post('/v1/applications/me/memoire/depots/depot_final', ['fichier' => UploadedFile::fake()->createWithContent('final.pdf', "%PDF-1.4\n%final\n")],
        ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('code', 'depot_refuse');
    $this->withToken($jeton)->get('/v1/applications/me/memoire/attestation')->assertNotFound();

    ($this->completer)();
    $doc = $this->service->delivrerAttestation($this->dossier, $this->responsable);
    expect($this->withToken($jeton)->get('/v1/applications/me/memoire/attestation')->assertOk()->headers->get('Content-Type'))->toBe('application/pdf');
    $this->withToken($jeton)->getJson('/v1/applications/me/memoire')->assertJsonPath('data.attestation.numero', $doc->numero)
        ->assertJsonPath('data.etapes.5.deposable', true);

    app('auth')->forgetGuards();
    $consultation = $user->createToken('consultation-espace', ['profile:read', 'application:read', 'consultation'])->plainTextToken;
    $this->withToken($consultation)->post('/v1/applications/me/memoire/depots/depot_final', ['fichier' => UploadedFile::fake()->createWithContent('final.pdf', "%PDF-1.4\n")],
        ['Accept' => 'application/json'])->assertForbidden();
});

it('ouvre les mémoires à la scolarité, pas à la bibliothèque, et affiche la fiche', function (): void {
    $this->actingAs($this->agent)->get(DossierMemoireResource::getUrl('index'))->assertOk()->assertSee('Mémoires');
    $this->actingAs($this->responsable)->get(DossierMemoireResource::getUrl('view', ['record' => $this->dossier]))->assertOk()
        ->assertSee('Attestation d\'autorisation de dépôt', false)->assertSee('Thème enregistré');
    $this->actingAs(($this->compte)('librarian'))->get(DossierMemoireResource::getUrl('index'))->assertForbidden();
});
