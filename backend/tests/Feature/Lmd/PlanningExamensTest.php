<?php

declare(strict_types=1);

use App\Filament\Resources\PlanningExamensResource;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\EpreuveExamen;
use App\Models\InscriptionAnnuelle;
use App\Models\JourNonOuvre;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\Parcours;
use App\Models\PlageHoraire;
use App\Models\PlanningExamens;
use App\Models\Salle;
use App\Models\User;
use App\Services\Lmd\Calendriers;
use App\Services\Lmd\CandidatsExamen;
use App\Services\Lmd\ConflitsPlanningExamens;
use App\Services\Lmd\DocumentsExamen;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\PlanningsExamens;
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
    $this->surveillant = ($this->compte)('enseignant');
    $this->service = app(PlanningsExamens::class);
    $this->candidats = app(CandidatsExamen::class);

    // Calendrier signé : examens M1, examens M2, rattrapage M1.
    $calendriers = app(Calendriers::class);
    $c = $calendriers->creer('2026-2027', '2026-10-01', '2027-08-31', $this->agent);
    $ajouter = fn (string $type, string $libelle, string $niveau, string $debut, string $fin, ?string $session = null) => $calendriers->enregistrer($c,
        ['type' => $type, 'libelle' => $libelle, 'niveau' => $niveau, 'session' => $session, 'debut_prevu' => $debut, 'fin_prevue' => $fin], $this->agent);
    $this->examensM1 = $ajouter('examens', 'Examens M1', 'M1', '2027-02-08', '2027-02-12', 'normale');
    $this->examensM2 = $ajouter('examens', 'Examens M2', 'M2', '2027-02-15', '2027-02-19', 'normale');
    $ajouter('deliberation', 'Jury M1', 'M1', '2027-02-22', '2027-02-22', 'normale');
    $ajouter('publication', 'Résultats M1', 'M1', '2027-02-26', '2027-02-26', 'normale');
    $this->rattrapageM1 = $ajouter('rattrapage', 'Rattrapage M1', 'M1', '2027-03-08', '2027-03-12', 'rattrapage');
    $this->cours = $ajouter('periode_cours', 'Première période de cours', 'M1', '2026-11-02', '2026-12-19');
    $calendriers->soumettre($c->refresh(), $this->agent);
    $calendriers->signer($c->refresh(), UploadedFile::fake()->createWithContent('cal.pdf', "%PDF-1.4\n%x\n"), 'Le Chef de la structure', '2026-11-01', ($this->compte)('chef_structure'));

    $this->matin = PlageHoraire::query()->where('code', 'MATIN')->sole();
    $this->midi = PlageHoraire::query()->where('code', 'MIDI')->sole();
    $this->petite = Salle::query()->create(['code' => 'B1', 'libelle' => 'Salle B1', 'capacite' => 3]);
    $this->grande = Salle::query()->create(['code' => 'A1', 'libelle' => 'Amphi A1', 'capacite' => 120]);

    // Cours commun aux options FFCP et GTFP : même code d'ECUE dans les deux maquettes du M2.
    $this->ffcp = Parcours::query()->where('code', 'FFCP')->sole();
    $this->gtfp = Parcours::query()->where('code', 'GTFP')->sole();
    foreach ([$this->ffcp, $this->gtfp] as $p) {
        MaquetteEcue::query()->create(['maquette_ue_id' => Maquette::query()->where('parcours_id', $p->id)->sole()->ues()->value('id'),
            'code' => 'MPCOM901', 'intitule' => 'Séminaire commun', 'credits' => 1, 'heures_cm' => 15, 'heures_td' => 0, 'heures_tp' => 0, 'heures_tpe' => 0, 'ordre' => 99]);
    }

    $baremes = ['M1' => BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']),
        'M2' => BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M2'])];
    $this->inscrire = function (string $nom, string $niveau = 'M1', ?Parcours $parcours = null) use ($baremes): InscriptionAnnuelle {
        $auditeur = Auditeur::factory()->campagneCommune()->create();
        $auditeur->candidature->forceFill(['nom' => $nom, 'prenom' => 'Essai'])->save();
        $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $baremes[$niveau]->id, 'niveau' => $niveau, 'annee_academique' => '2026-2027']);
        if ($parcours !== null) {
            $i->forceFill(['parcours_id' => $parcours->id])->save();
        }
        app(InscriptionsPedagogiques::class)->inscrire($i->refresh());

        return $i;
    };
    $this->epreuve = fn (PlanningExamens $p, string $code, string $date, array $salles = [], array $plus = []): EpreuveExamen => $this->service->enregistrer($p,
        ['code_ecue' => $code, 'date_epreuve' => $date, 'plage_horaire_id' => $this->matin->id, 'salles' => $salles, ...$plus], $this->agent);
    $this->controles = fn (PlanningExamens $p): array => collect(app(ConflitsPlanningExamens::class)->pour($p))->pluck('controle')->all();
    $this->noms = fn ($candidats): array => $candidats->map(fn ($ip): string => CandidatsExamen::nom($ip))->all();
});

it('réunit les options dans une épreuve commune et remplit les salles par ordre alphabétique', function (): void {
    ($this->inscrire)('Zogo', 'M2', $this->ffcp);
    ($this->inscrire)('Abena', 'M2', $this->gtfp);
    ($this->inscrire)('Mballa', 'M2', $this->ffcp);
    ($this->inscrire)('Biya', 'M2', $this->gtfp);
    ($this->inscrire)('Hors Sujet');   // M1 : pas concerné
    $sorti = ($this->inscrire)('Parti', 'M2', $this->ffcp);
    $sorti->auditeur->forceFill(['statut' => Auditeur::STATUT_ABANDON])->save();

    $p = $this->service->creer($this->examensM2, $this->agent);
    expect($this->service->codes($p))->toHaveKey('MPCOM901')->not->toHaveKey('MPMPP711');
    $e = ($this->epreuve)($p, 'MPCOM901', '2027-02-16', [['salle_id' => $this->petite->id, 'surveillants' => [$this->surveillant->id]], ['salle_id' => $this->grande->id]]);

    $r = $this->candidats->repartition($e->refresh());
    expect(($this->noms)($this->candidats->pour($e)))->toBe(['ABENA Essai', 'BIYA Essai', 'MBALLA Essai', 'ZOGO Essai'])
        ->and(($this->noms)($r['salles'][0]['candidats']))->toBe(['ABENA Essai', 'BIYA Essai', 'MBALLA Essai'])
        ->and($r['salles'][0]['surveillants']->pluck('id')->all())->toBe([$this->surveillant->id])
        ->and(($this->noms)($r['salles'][1]['candidats']))->toBe(['ZOGO Essai'])
        ->and($r['sans_place'])->toBeEmpty()
        ->and(($this->controles)($p))->toBe(['sans_surveillant']);

    $this->service->publier($p, $this->agent);
    expect($p->refresh()->estPublie())->toBeTrue()
        ->and(fn () => $this->service->creer($this->examensM2, $this->agent))->toThrow(InvalidArgumentException::class, 'existe déjà')
        ->and(fn () => $this->service->creer($this->cours, $this->agent))->toThrow(InvalidArgumentException::class, 'session d\'examens');
});

it('refuse la publication tant qu\'il reste un conflit bloquant', function (): void {
    foreach (['Atangana', 'Bella', 'Essomba', 'Fouda'] as $nom) {
        ($this->inscrire)($nom);
    }
    JourNonOuvre::query()->create(['jour' => '2027-02-11', 'libelle' => 'Fête de la jeunesse']);
    $p = $this->service->creer($this->examensM1, $this->agent);
    expect(fn () => $this->service->publier($p, $this->agent))->toThrow(InvalidArgumentException::class, 'au moins une épreuve');

    $lieu = [['salle_id' => $this->grande->id, 'surveillants' => [$this->surveillant->id]]];
    ($this->epreuve)($p, 'MPMPP711', '2027-02-09', $lieu);
    ($this->epreuve)($p, 'MPMPP712', '2027-02-09', $lieu);
    expect(($this->controles)($p))->toEqualCanonicalizing(['salle_double', 'surveillant_double', 'candidat_double'])
        ->and(fn () => $this->service->publier($p, $this->agent))->toThrow(InvalidArgumentException::class, 'Publication refusée : 3 conflit(s)');

    $autre = MaquetteEcue::query()->whereHas('maquetteUe.maquette', fn ($q) => $q->whereHas('parcours', fn ($x) => $x->where('code', 'TC')))
        ->whereNotIn('code', ['MPMPP711', 'MPMPP712'])->orderBy('code')->pluck('code');
    ($this->epreuve)($p, $autre[0], '2027-02-20', $lieu);                       // hors session
    ($this->epreuve)($p, $autre[1], '2027-02-11', $lieu);                       // jour non ouvré
    ($this->epreuve)($p, $autre[2], '2027-02-10');                              // sans salle
    ($this->epreuve)($p, $autre[3], '2027-02-12', [['salle_id' => $this->petite->id]]);   // 4 candidats, 3 places
    expect(array_values(array_diff(($this->controles)($p), ['salle_double', 'surveillant_double', 'candidat_double'])))
        ->toEqualCanonicalizing(['hors_session', 'jour_non_ouvre', 'sans_salle', 'capacite', 'sans_surveillant']);

    expect(fn () => ($this->epreuve)($p, 'MPMPP711', '2027-02-10', $lieu))->toThrow(InvalidArgumentException::class, 'figure déjà')
        ->and(fn () => ($this->epreuve)($p, 'MPCOM901', '2027-02-10', $lieu))->toThrow(InvalidArgumentException::class, 'ECUE absent')
        ->and(fn () => ($this->epreuve)($p, $autre[4], '2027-02-10', [['salle_id' => $this->grande->id], ['salle_id' => $this->grande->id]]))->toThrow(InvalidArgumentException::class, 'qu\'une fois')
        ->and(fn () => ($this->epreuve)($p, $autre[4], '2027-02-10', [['salle_id' => $this->grande->id, 'surveillants' => [User::factory()->create()->id]]]))->toThrow(InvalidArgumentException::class, 'Surveillant inconnu')
        ->and(fn () => $this->service->enregistrer($p, ['code_ecue' => $autre[4], 'date_epreuve' => '2027-02-10', 'debut' => '10:00', 'fin' => '09:00'], $this->agent))->toThrow(InvalidArgumentException::class, 'heure de fin')
        ->and(fn () => $this->service->enregistrer($p, ['code_ecue' => $autre[4], 'date_epreuve' => '2027-02-10', 'plage_horaire_id' => $this->matin->id], ($this->compte)('librarian')))->toThrow(HttpException::class);
});

it('laisse reporter une épreuve d\'un planning publié, sauf si le report crée un conflit', function (): void {
    ($this->inscrire)('Atangana');
    $p = $this->service->creer($this->examensM1, $this->agent);
    $lieu = [['salle_id' => $this->grande->id, 'surveillants' => [$this->surveillant->id]]];
    $a = ($this->epreuve)($p, 'MPMPP711', '2027-02-09', $lieu);
    $b = ($this->epreuve)($p, 'MPMPP712', '2027-02-10', $lieu);
    $this->service->publier($p, $this->agent);

    expect(fn () => $this->service->enregistrer($p->refresh(), ['code_ecue' => 'MPMPP712', 'date_epreuve' => '2027-02-09', 'plage_horaire_id' => $this->matin->id, 'salles' => $lieu], $this->agent, $b))
        ->toThrow(InvalidArgumentException::class, 'Modification refusée')
        ->and($b->refresh()->date_epreuve->toDateString())->toBe('2027-02-10')
        ->and(fn () => $this->service->supprimer($b, $this->agent))->toThrow(InvalidArgumentException::class, 'ne se supprime plus');

    $this->service->enregistrer($p, ['code_ecue' => 'MPMPP712', 'date_epreuve' => '2027-02-09', 'plage_horaire_id' => $this->midi->id, 'salles' => $lieu], $this->agent, $b);
    expect($b->refresh()->heures())->toBe('11:30 – 14:30')
        ->and($b->salles()->count())->toBe(1)
        ->and($a->refresh()->surveillants()->count())->toBe(1);
});

it('ne convoque au rattrapage que les éligibles', function (): void {
    ($this->inscrire)('Atangana');
    $normale = $this->service->creer($this->examensM1, $this->agent);
    $rattrapage = $this->service->creer($this->rattrapageM1, $this->agent);

    expect($this->candidats->pour(($this->epreuve)($normale, 'MPMPP711', '2027-02-09')))->toHaveCount(1)
        ->and($rattrapage->estRattrapage())->toBeTrue()
        ->and($this->candidats->pour(($this->epreuve)($rattrapage, 'MPMPP711', '2027-03-09')))->toBeEmpty();
});

it('dresse une liste d\'émargement par salle et le planning de la session', function (): void {
    foreach (['Atangana', 'Bella', 'Essomba', 'Fouda'] as $nom) {
        ($this->inscrire)($nom);
    }
    $p = $this->service->creer($this->examensM1, $this->agent);
    $e = ($this->epreuve)($p, 'MPMPP711', '2027-02-09', [['salle_id' => $this->petite->id, 'surveillants' => [$this->surveillant->id]], ['salle_id' => $this->grande->id]]);
    $documents = app(DocumentsExamen::class);

    $pages = $documents->pages(collect([$e->refresh()]));
    $html = html_entity_decode(view('pdf.lmd.emargement-salle', ['p' => $p, 'pages' => $pages])->render());
    expect($pages)->toHaveCount(2)
        ->and($html)->toContain('Salle B1 (3 candidat(s))', 'Amphi A1 (1 candidat(s))', $this->surveillant->name, 'ATANGANA Essai', 'FOUDA Essai', 'à désigner')
        ->and(html_entity_decode(view('pdf.lmd.planning-examens', ['p' => $p, 'lignes' => $documents->lignes($p)])->render()))
        ->toContain('Examens M1', 'MPMPP711', 'Salle B1 (3)', 'Amphi A1 (1)', 'PROJET, non publié')
        ->and($documents->emargements($p, collect([$e])))->toStartWith('%PDF')
        ->and($documents->planning($p))->toStartWith('%PDF');
});

it('montre à l\'auditeur ses épreuves publiées et sa salle', function (): void {
    ($this->inscrire)('Atangana');
    $moi = ($this->inscrire)('Zogo');
    $moi->auditeur->candidature->forceFill(['user_id' => User::factory()->create()->id])->save();
    $jeton = $moi->auditeur->candidature->refresh()->user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;
    $petite = Salle::query()->create(['code' => 'C1', 'libelle' => 'Salle C1', 'capacite' => 1]);
    $p = $this->service->creer($this->examensM1, $this->agent);
    ($this->epreuve)($p, 'MPMPP711', '2027-02-09', [['salle_id' => $petite->id, 'surveillants' => [$this->surveillant->id]], ['salle_id' => $this->grande->id, 'surveillants' => [$this->agent->id]]]);

    $this->withToken($jeton)->getJson('/v1/applications/me/examens')->assertOk()->assertJsonPath('data.epreuves', []);
    $this->service->publier($p, $this->agent);
    $r = $this->withToken($jeton)->getJson('/v1/applications/me/examens')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($r->json('data.epreuves'))->toHaveCount(1)
        ->and($r->json('data.epreuves.0'))->toMatchArray(['session' => 'Examens M1', 'rattrapage' => false, 'date' => '2027-02-09', 'debut' => '08:00', 'fin' => '11:00',
            'ecue' => 'MPMPP711', 'salle' => 'Amphi A1']);

    $sansDossier = User::factory()->create()->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($sansDossier)->getJson('/v1/applications/me/examens')->assertNotFound();
});

it('ouvre le planning d\'examens à la scolarité, pas à la bibliothèque', function (): void {
    ($this->inscrire)('Atangana');
    $p = $this->service->creer($this->examensM1, $this->agent);
    ($this->epreuve)($p, 'MPMPP711', '2027-02-09', [['salle_id' => $this->grande->id]]);

    $this->actingAs($this->agent)->get(PlanningExamensResource::getUrl('index'))->assertOk()->assertSee('Examens M1');
    $this->actingAs($this->agent)->get(PlanningExamensResource::getUrl('view', ['record' => $p]))->assertOk()
        ->assertSee('Répartition en salles')->assertSee('MPMPP711')->assertSee('Salle sans surveillant')->assertSee('ATANGANA Essai');
    $this->actingAs(($this->compte)('librarian'))->get(PlanningExamensResource::getUrl('index'))->assertForbidden();
});
