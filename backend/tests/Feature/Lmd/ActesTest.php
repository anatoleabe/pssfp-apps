<?php

declare(strict_types=1);

use App\Filament\Resources\ActeResource;
use App\Filament\Resources\ModeleActeResource;
use App\Models\Acte;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CalendrierAcademique;
use App\Models\InscriptionAnnuelle;
use App\Models\ModeleActe;
use App\Models\User;
use App\Services\Lmd\Actes;
use App\Services\Lmd\Calendriers;
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
    $this->calendriers = app(Calendriers::class);
    $this->actes = app(Actes::class);
    $this->pdf = fn (string $nom = 'acte-signe.pdf') => UploadedFile::fake()->createWithContent($nom, "%PDF-1.4\n%".Str::random(8)."\n");

    // Petit calendrier : examens M1 et M2, jury, publication, rattrapage M2, puis signé.
    $this->c = $this->calendriers->creer('2026-2027', '2026-10-01', '2027-08-31', $this->agent);
    $ajouter = fn (string $type, string $libelle, string $niveau, string $debut, ?string $fin = null, ?string $session = null) => $this->calendriers->enregistrer($this->c,
        ['type' => $type, 'libelle' => $libelle, 'niveau' => $niveau, 'session' => $session, 'promotion' => $niveau === 'M1' ? 14 : 13, 'debut_prevu' => $debut, 'fin_prevue' => $fin ?? $debut], $this->agent);
    $this->examensM2 = $ajouter('examens', 'Examens M2', 'M2', '2027-02-16', '2027-02-28', 'normale');
    $this->examensM1 = $ajouter('examens', 'Examens M1', 'M1', '2027-02-16', '2027-02-28', 'normale');
    $ajouter('deliberation', 'Jury M2', 'M2', '2027-03-10', null, 'normale');
    $ajouter('deliberation', 'Jury M1', 'M1', '2027-03-10', null, 'normale');
    $ajouter('publication', 'Publication M2', 'M2', '2027-03-20', null, 'normale');
    $ajouter('publication', 'Publication M1', 'M1', '2027-03-20', null, 'normale');
    $this->rattrapage = $ajouter('rattrapage', 'Rattrapage M2', 'M2', '2027-04-13', '2027-04-17', 'rattrapage');
    $this->signerCalendrier = function (): void {
        $this->calendriers->soumettre($this->c->refresh(), $this->agent);
        $this->calendriers->signer($this->c->refresh(), ($this->pdf)('calendrier.pdf'), 'Le Chef de la structure', '2026-11-07', $this->chef);
    };
    $this->acte = fn (string $code, $evenement): Acte => Acte::query()->where('evenement_id', $evenement->id)
        ->whereHas('modele', fn ($q) => $q->where('code', $code))->sole();
    $this->jusquASoumis = function (Acte $a): Acte {
        $this->actes->rediger($a, $this->actes->texteInitial($a), $this->agent);
        $this->actes->soumettre($a->refresh(), $this->agent);

        return $a->refresh();
    };
});

it('génère les actes du calendrier 2026-2027 repris, avec leurs échéances, sans doublon, et signale ceux dus pendant l\'interruption', function (): void {
    $this->c->forceFill(['annee_academique' => '2025-2026'])->save();
    $b = app(ImportCalendrier::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/calendrier-2026-2027.json')), true), true);
    $c = CalendrierAcademique::query()->where('annee_academique', '2026-2027')->sole();
    $parObjet = fn (string $debut): Acte => Acte::query()->where('objet', 'like', $debut.'%')->whereHas('evenement', fn ($q) => $q->where('calendrier_id', $c->id))->firstOrFail();

    expect($b['actes'])->toBe(24)
        ->and($parObjet('Communiqué relatif aux examens — Examens de la session normale du 1er semestre M1')->echeance->toDateString())->toBe('2027-01-02')
        ->and($parObjet('Note de service portant désignation du jury — Examens de la session normale du 1er semestre M1')->echeance->toDateString())->toBe('2027-02-06')
        ->and($this->actes->generer($c))->toBe(0)
        ->and($b['alertes'])->toHaveCount(3)
        ->and(collect($b['alertes'])->every(fn (string $a): bool => str_contains($a, 'avant le 19/12/2026')))->toBeTrue()
        ->and(collect($b['alertes'])->filter(fn (string $a): bool => str_contains($a, 'Communiqué relatif aux examens'))->count())->toBe(2);
});

it('génère les actes à la signature ; l\'échéance des actes non signés suit le réajustement, l\'annulation les annule', function (): void {
    ($this->signerCalendrier)();
    // 2 examens × (note de service + communiqué), 2 jurys (PV de synthèse), 2 publications, rattrapage × 2.
    expect(Acte::query()->count())->toBe(10);

    $communique = ($this->jusquASoumis)(($this->acte)('COMMUNIQUE_EXAMENS', $this->examensM2));
    $this->actes->signer($communique, '26/001/PSSFP/CS', '2026-11-07', 'Le Chef de la structure', ($this->pdf)(), $this->responsable);
    $data = ['type' => 'examens', 'libelle' => 'Examens M2', 'niveau' => 'M2', 'session' => 'normale', 'promotion' => 13, 'debut_prevu' => '2027-02-23', 'fin_prevue' => '2027-03-05'];
    $this->calendriers->enregistrer($this->c->refresh(), $data, $this->responsable, $this->examensM2->refresh(), 'Décalage d\'une semaine');

    expect(($this->acte)('NS_JURY_EXAMENS', $this->examensM2)->echeance->toDateString())->toBe('2027-02-13')
        ->and($communique->refresh()->echeance->toDateString())->toBe('2027-01-02');

    $this->calendriers->executer($this->rattrapage->refresh(), ['statut' => 'annule', 'motif' => 'Rattrapage fusionné avec la session de juin'], $this->agent);
    expect(Acte::query()->where('evenement_id', $this->rattrapage->id)->pluck('statut')->unique()->all())->toBe([Acte::ANNULE])
        ->and(Acte::query()->where('evenement_id', $this->rattrapage->id)->value('motif'))->toBe('Événement annulé');

    // Nouvelle version : les actes passent sur les événements copiés, sans doublon.
    $v2 = $this->calendriers->nouvelleVersion($this->c->refresh(), $this->agent);
    $this->calendriers->soumettre($v2, $this->agent);
    $this->calendriers->signer($v2->refresh(), ($this->pdf)('calendrier-v2.pdf'), 'Le Chef de la structure', '2026-12-01', $this->chef);
    $examensV2 = $v2->evenements()->where('libelle', 'Examens M2')->sole();
    expect($communique->refresh()->evenement_id)->toBe($examensV2->id)
        ->and($communique->statut)->toBe(Acte::SIGNE)
        ->and(Acte::query()->where('evenement_id', $examensV2->id)->count())->toBe(2)
        ->and(Acte::query()->whereIn('statut', Acte::EN_COURS)->whereHas('evenement', fn ($q) => $q->where('calendrier_id', $this->c->id))->count())->toBe(0);
});

it('refuse une signature incomplète, en double ou par l\'agent ; l\'enregistre pour le responsable', function (): void {
    ($this->signerCalendrier)();
    $a = ($this->jusquASoumis)(($this->acte)('NS_JURY_EXAMENS', $this->examensM1));
    $b = ($this->jusquASoumis)(($this->acte)('NS_JURY_EXAMENS', $this->examensM2));

    expect(fn () => $this->actes->signer($a, '26/010/PSSFP/CS', '2026-11-07', 'Le Chef de la structure', ($this->pdf)(), $this->agent))->toThrow(HttpException::class)
        ->and(fn () => $this->actes->signer($a, '', '2026-11-07', 'Le Chef de la structure', ($this->pdf)(), $this->responsable))->toThrow(InvalidArgumentException::class, 'obligatoires')
        ->and(fn () => $this->actes->signer($a, '26/010/PSSFP/CS', '2026-11-07', 'Le Chef de la structure', null, $this->responsable))->toThrow(InvalidArgumentException::class, 'PDF');

    $this->actes->signer($a, '26/010/PSSFP/CS', '2026-11-07', 'Le Chef de la structure', ($this->pdf)(), $this->responsable);
    expect($a->refresh()->statut)->toBe(Acte::SIGNE)
        ->and($this->actes->piece($a))->toStartWith('%PDF')
        ->and(fn () => $this->actes->signer($b, '26/010/PSSFP/CS', '2026-11-07', 'Le Chef de la structure', ($this->pdf)(), $this->chef))->toThrow(InvalidArgumentException::class, 'déjà attribué')
        ->and(fn () => DB::transaction(fn () => DB::table('actes')->where('id', $b->id)->update(['statut' => 'signe'])))->toThrow(QueryException::class, 'actes_check')
        ->and(fn () => $this->actes->annuler($a->refresh(), 'Erreur de désignation', $this->agent))->toThrow(InvalidArgumentException::class, 'non signé');

    $this->actes->renvoyer($b, 'Ajouter le second vice-président', $this->chef);
    expect($b->refresh()->statut)->toBe(Acte::BROUILLON)->and($b->motif)->toBe('Ajouter le second vice-président')
        ->and($this->actes->projetPdf($b))->toStartWith('%PDF');
});

it('classe les actes du tableau de suivi : en retard, à traiter dans les 15 jours, sans alerte une fois signés', function (): void {
    ($this->signerCalendrier)();
    $ns = ($this->acte)('NS_JURY_EXAMENS', $this->examensM2);     // échéance 06/02/2027
    $communique = ($this->acte)('COMMUNIQUE_EXAMENS', $this->examensM2); // échéance 02/01/2027

    $this->travelTo('2027-01-27 09:00');
    expect($ns->refresh()->alerte())->toBe('a_traiter')
        ->and($communique->refresh()->alerte())->toBe('en_retard')
        ->and(($this->acte)('PV_SYNTHESE', $this->examensM2->calendrier->evenements()->where('libelle', 'Jury M2')->sole())->alerte())->toBe('ok');

    $this->travelTo('2026-11-07 09:00');
    $this->actes->signer(($this->jusquASoumis)($communique), '26/002/PSSFP/CS', '2026-11-07', 'Le Chef de la structure', ($this->pdf)(), $this->responsable);
    $this->travelTo('2027-01-27 09:00');
    expect($communique->refresh()->alerte())->toBe('ok');
});

it('montre à l\'auditeur M1 le communiqué diffusé de son niveau seulement', function (): void {
    ($this->signerCalendrier)();
    $this->calendriers->publier($this->c->refresh(), $this->responsable);
    $diffuser = function (Acte $a, string $numero): Acte {
        $this->actes->signer(($this->jusquASoumis)($a), $numero, '2026-11-07', 'Le Chef de la structure', ($this->pdf)(), $this->responsable);
        $this->actes->diffuser($a->refresh(), $this->responsable);

        return $a->refresh();
    };
    $communiqueM1 = $diffuser(($this->acte)('COMMUNIQUE_EXAMENS', $this->examensM1), '26/101/PSSFP/CS');
    $communiqueM2 = $diffuser(($this->acte)('COMMUNIQUE_EXAMENS', $this->examensM2), '26/102/PSSFP/CS');
    $noteService = $diffuser(($this->acte)('NS_JURY_EXAMENS', $this->examensM1), '26/103/PSSFP/CS');

    $bareme = BaremeScolarite::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'montant_inscription' => 185000,
        'montant_tranche1' => 500000, 'montant_tranche2' => 500000, 'tranche1_echeance' => now()->addMonth()->toDateString()]);
    $auditeur = Auditeur::factory()->campagneCommune()->create();
    $auditeur->candidature->forceFill(['user_id' => User::factory()->create()->id])->save();
    InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id, 'niveau' => 'M1', 'annee_academique' => '2026-2027']);
    $jeton = $auditeur->candidature->user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;

    $r = $this->withToken($jeton)->getJson('/v1/applications/me/calendrier')->assertOk();
    expect(collect($r->json('data.communiques'))->pluck('uuid')->all())->toBe([$communiqueM1->uuid])
        ->and($r->json('data.communiques.0.numero'))->toBe('26/101/PSSFP/CS');

    $this->withToken($jeton)->get('/v1/applications/me/calendrier/communiques/'.$communiqueM1->uuid)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->withToken($jeton)->getJson('/v1/applications/me/calendrier/communiques/'.$communiqueM2->uuid)->assertNotFound();
    $this->withToken($jeton)->getJson('/v1/applications/me/calendrier/communiques/'.$noteService->uuid)->assertNotFound();
    $nonDiffuse = ($this->acte)('COMMUNIQUE_RATTRAPAGE', $this->rattrapage);
    $this->withToken($jeton)->getJson('/v1/applications/me/calendrier/communiques/'.$nonDiffuse->uuid)->assertNotFound();
});

it('remplit le gabarit et reporte un nouveau délai de modèle sur les actes non signés', function (): void {
    ($this->signerCalendrier)();
    $a = ($this->acte)('COMMUNIQUE_EXAMENS', $this->examensM1);
    expect($this->actes->texteInitial($a))->toContain('14e promotion (M1)')->toContain('du 16/02/2027 au 28/02/2027');

    $m = ModeleActe::query()->where('code', 'COMMUNIQUE_EXAMENS')->sole();
    $m->forceFill(['delai_jours' => -30])->save();
    $this->actes->suivreModele($m);
    expect($a->refresh()->echeance->toDateString())->toBe('2027-01-17');
});

it('ouvre les actes à la scolarité et au chef de la structure, pas à la bibliothèque', function (): void {
    ($this->signerCalendrier)();
    $a = ($this->acte)('COMMUNIQUE_EXAMENS', $this->examensM1);
    $this->actingAs($this->agent)->get(ActeResource::getUrl('index'))->assertOk()->assertSee('Communiqué relatif aux examens');
    $this->actingAs($this->agent)->get(ActeResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee('Examens M1');
    $this->actingAs($this->agent)->get(ModeleActeResource::getUrl('index'))->assertOk()->assertSee('NS_JURY_EXAMENS');
    $this->actingAs($this->chef)->get(ActeResource::getUrl('view', ['record' => $a]))->assertOk();
    $this->actingAs(($this->compte)('librarian'))->get(ActeResource::getUrl('index'))->assertForbidden();
});
