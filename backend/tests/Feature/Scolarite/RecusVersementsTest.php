<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\AuditeurResource\RelationManagers\VersementsRelationManager;
use App\Filament\Resources\RecuVersementResource;
use App\Models\AnnulationRecu;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\RecuVersement;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->mock(SmsServiceInterface::class)->shouldReceive('send')->andReturnNull();
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->financier = ($this->compte)('service_financier');
    $admin = ($this->compte)('admin');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());

    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $retenu = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel, 'email' => Str::random(8).'@example.org',
        'ecampus_statut' => ComptesEcampus::CREE,
        'user_id' => User::factory()->create(['phone_e164' => $tel, 'email' => Str::random(8).'@example.org'])->id,
    ]);
    $this->awa = $retenu('Abena', '+237699003001');
    $this->bruno = $retenu('Bekono', '+237699003002');
    app(AttributionMatricules::class)->attribuer($campagne, '26', $admin);
    $this->awa->refresh();
    $this->bruno->refresh();
    $this->inscription = $this->awa->auditeur->inscriptionCourante;
    $this->service = app(RecusVersements::class);
    $this->verser = fn (int $montant, string $date = '2026-11-02'): Versement => app(RegistreVersements::class)->enregistrer($this->inscription,
        ['montant' => $montant, 'date_versement' => $date, 'mode' => 'cremincam_agence', 'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur'], $this->agent)->versement;
    $this->jeton = fn (Candidature $c): string => $c->user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;
});

it('numérote les reçus RCPT-AAAA-NNNNNN, fige leur contenu et impute le versement sur le barème', function (): void {
    $a = ($this->verser)(185000);
    $b = ($this->verser)(300000, '2026-11-05');

    $r1 = $this->service->emettre($a, $this->financier);
    $r2 = $this->service->emettre($b, $this->financier);

    expect($r1->numero)->toBe('RCPT-2026-000001')
        ->and($r2->numero)->toBe('RCPT-2026-000002')
        ->and($r1->objet)->toBe('Frais de scolarité 2026-2027 : droits d\'inscription (185 000 FCFA)')
        ->and($r2->objet)->toBe('Frais de scolarité 2026-2027 : 1re tranche (300 000 FCFA)')
        ->and($r2->total_verse)->toBe(485000)
        ->and($r2->reste_a_payer)->toBe(700000)
        ->and($r1->auditeur_nom)->toBe('ABENA Essai')
        ->and($r1->matricule)->toBe($this->awa->matricule)
        ->and($r1->emetteur_nom)->toBe('Agent service_financier')
        ->and($this->service->enLettres(185000))->toBe('cent quatre-vingt-cinq mille francs CFA')
        ->and(substr($this->service->pdf($r1), 0, 4))->toBe('%PDF');

    // Un versement à cheval sur deux paliers, dont l'excédent va en avance au compte (ADR-0013) : le total
    // versé pour l'année ne le compte plus (décision du 15/11/2026, validée avec le service financier).
    $c = ($this->verser)(800000, '2026-11-06');
    $r3 = $this->service->emettre($c, $this->financier);
    expect($r3->objet)
        ->toBe('Frais de scolarité 2026-2027 : 1re tranche (200 000 FCFA), 2e tranche (500 000 FCFA), avance sur la scolarité à venir (100 000 FCFA)')
        ->and($r3->total_verse)->toBe(1185000)
        ->and($r3->reste_a_payer)->toBe(0);
});

it('E1 au reçu : format d\'une seule année, avance libellée à part et hors du total versé pour l\'année', function (): void {
    $r = $this->service->emettre(($this->verser)(2320000), $this->financier);

    expect($r->objet)->toBe('Frais de scolarité 2026-2027 : droits d\'inscription (185 000 FCFA), 1re tranche (500 000 FCFA), 2e tranche (500 000 FCFA), avance sur la scolarité à venir (1 135 000 FCFA)')
        ->and($r->montant)->toBe(2320000)
        ->and($r->total_verse)->toBe(1185000)
        ->and($r->reste_a_payer)->toBe(0);
});

it('détaille l\'objet par année quand le versement règle une autre année que la sienne', function (): void {
    ($this->verser)(185000, '2026-11-02');
    ($this->verser)(500000, '2026-11-03');
    ($this->verser)(200000, '2026-11-04');
    $m2 = InscriptionAnnuelle::factory()->create(['auditeur_id' => $this->awa->auditeur->id,
        'bareme_id' => BaremeScolarite::factory()->m2()->create(['annee_academique' => '2027-2028'])->id]);

    // Saisi sur l'inscription courante (2027-2028) : l'arriéré du M1 passe d'abord (R1).
    $v = app(RegistreVersements::class)->enregistrer($m2, ['montant' => 1475000, 'date_versement' => '2026-11-08', 'mode' => 'virement',
        'reference' => 'BOR-MULTI', 'payeur' => 'auditeur'], $this->agent)->versement;
    $r = $this->service->emettre($v, $this->financier);

    expect($r->objet)->toBe('Frais de scolarité 2026-2027 (M1) : 2e tranche (300 000 FCFA) ; 2027-2028 (M2) : droits d\'inscription (135 000 FCFA), '
        .'1re tranche (500 000 FCFA), 2e tranche (500 000 FCFA) ; avance sur la scolarité à venir (40 000 FCFA)')
        ->and($r->annee_academique)->toBe('2027-2028')
        ->and($r->niveau)->toBe('M2')
        ->and($r->total_verse)->toBe(1135000)
        ->and($r->reste_a_payer)->toBe(0);
});

it('résume chaque année par son total quand le détail dépasse 500 caractères', function (): void {
    $derniere = $this->inscription;
    foreach (['2027-2028', '2028-2029', '2029-2030', '2030-2031', '2031-2032'] as $annee) {
        $derniere = InscriptionAnnuelle::factory()->create(['auditeur_id' => $this->awa->auditeur->id,
            'bareme_id' => BaremeScolarite::factory()->create(['annee_academique' => $annee])->id]);
    }
    $v = app(RegistreVersements::class)->enregistrer($derniere, ['montant' => 6 * 1185000 + 1000, 'date_versement' => '2026-11-08',
        'mode' => 'virement', 'reference' => 'BOR-LONG', 'payeur' => 'auditeur'], $this->agent)->versement;

    $r = $this->service->emettre($v, $this->financier);

    expect($r->objet)->toBe('Frais de scolarité 2026-2027 (M1) : 1 185 000 FCFA ; 2027-2028 (M1) : 1 185 000 FCFA ; 2028-2029 (M1) : 1 185 000 FCFA ; '
        .'2029-2030 (M1) : 1 185 000 FCFA ; 2030-2031 (M1) : 1 185 000 FCFA ; 2031-2032 (M1) : 1 185 000 FCFA ; avance sur la scolarité à venir (1 000 FCFA)')
        ->and(mb_strlen($r->objet))->toBeLessThanOrEqual(500)
        ->and($r->total_verse)->toBe(1185000);
});

it('ne touche jamais un reçu émis : une remise ou une annulation après coup laisse ses colonnes intactes', function (): void {
    $v = ($this->verser)(1185000);
    $r = $this->service->emettre($v, $this->financier);
    $avant = (array) DB::table('recus_versements')->where('id', $r->id)->first();

    app(RegistreVersements::class)->accorderRemise($this->inscription->fresh(), 300000, 'Bourse partielle', $this->responsable);
    app(RegistreVersements::class)->annuler($v->refresh(), 'Virement rejeté par la banque', $this->responsable);

    expect((array) DB::table('recus_versements')->where('id', $r->id)->first())->toBe($avant)
        ->and($r->fresh()->estAnnule())->toBeTrue();
});

it('régénère avec le même texte un reçu émis avant le compte de l\'auditeur', function (): void {
    $v = ($this->verser)(1285000);
    DB::table('recus_versements')->insert([
        'uuid' => (string) Str::uuid(), 'numero' => 'RCPT-2026-000099', 'annee_numero' => 2026, 'sequence' => 99,
        'versement_id' => $v->id, 'inscription_annuelle_id' => $this->inscription->id, 'montant' => 1285000,
        'objet' => 'Frais de scolarité 2026-2027 : droits d\'inscription (185 000 FCFA), 1re tranche (500 000 FCFA), 2e tranche (500 000 FCFA), trop-perçu (100 000 FCFA)',
        'auditeur_nom' => 'ABENA Essai', 'matricule' => $this->awa->matricule, 'annee_academique' => '2026-2027', 'niveau' => 'M1',
        'mode' => 'virement', 'reference' => $v->reference, 'date_versement' => '2026-11-02', 'payeur' => 'auditeur',
        'total_verse' => 1285000, 'reste_a_payer' => 0, 'emis_par' => $this->financier->id, 'emetteur_nom' => 'Agent service_financier',
        'emis_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ancien = RecuVersement::query()->where('numero', 'RCPT-2026-000099')->sole();

    $html = view('pdf.recu-versement', ['recu' => $ancien->loadMissing('annulation'), 'lettres' => $this->service->enLettres($ancien->montant)])->render();

    expect($html)->toContain('trop-perçu (100 000 FCFA)')
        ->and($html)->toContain('1 285 000')
        ->and(substr($this->service->pdf($ancien), 0, 4))->toBe('%PDF');
});

it('refuse le reçu d\'un versement annulé, déjà couvert ou antérieur à la mise en service, et sans la permission', function (): void {
    $v = ($this->verser)(185000);

    expect(fn () => $this->service->emettre($v, $this->agent))->toThrow(HttpException::class);
    $this->service->emettre($v, $this->financier);
    expect(fn () => $this->service->emettre($v, $this->financier))->toThrow(InvalidArgumentException::class, 'déjà un reçu');

    $annule = ($this->verser)(50000);
    app(RegistreVersements::class)->annuler($annule, 'Doublon de saisie', $this->responsable);
    expect(fn () => $this->service->emettre($annule->refresh(), $this->financier))->toThrow(InvalidArgumentException::class, 'annulé');

    // Séparation des tâches (Chef USI, 08/11/2026) : qui enregistre le versement n'en émet pas le reçu.
    $propre = app(RegistreVersements::class)->enregistrer($this->inscription,
        ['montant' => 20000, 'date_versement' => '2026-11-03', 'mode' => 'especes', 'reference' => 'CAISSE-01', 'payeur' => 'auditeur'], $this->financier)->versement;
    expect(fn () => $this->service->emettre($propre, $this->financier))->toThrow(InvalidArgumentException::class, 'Vous avez enregistré ce versement');
    $this->service->emettre($propre, ($this->compte)('service_financier'));

    $ancien = ($this->verser)(10000);
    DB::table('versements')->where('id', $ancien->id)->update(['created_at' => now()->subMonth()]);
    expect(fn () => $this->service->emettre($ancien->refresh(), $this->financier))->toThrow(InvalidArgumentException::class, 'avant la mise en service');
});

it('annule un reçu par une écriture distincte, sans le modifier, puis en émet un nouveau', function (): void {
    $v = ($this->verser)(185000);
    $r = $this->service->emettre($v, $this->financier);

    expect(fn () => $this->service->annuler($r, 'non', $this->financier))->toThrow(InvalidArgumentException::class, 'motif')
        ->and(fn () => $this->service->annuler($r, 'Erreur sur le payeur', $this->agent))->toThrow(HttpException::class);
    $a = $this->service->annuler($r, 'Erreur sur le payeur', $this->financier);

    expect($a->numero)->toBe('RCPT-2026-000001-A')
        ->and($r->fresh()->updated_at->equalTo($r->updated_at))->toBeTrue()
        ->and(fn () => $r->forceFill(['montant' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $this->service->annuler($r->fresh(), 'Erreur sur le payeur', $this->financier))->toThrow(InvalidArgumentException::class, 'déjà annulé')
        ->and(substr($this->service->pdfAnnulation($a), 0, 4))->toBe('%PDF');

    $nouveau = $this->service->emettre($v->refresh(), $this->financier);
    expect($nouveau->numero)->toBe('RCPT-2026-000002');

    // L'annulation du versement passe une écriture d'annulation sur son reçu en vigueur.
    app(RegistreVersements::class)->annuler($v->refresh(), 'Bordereau rejeté par la banque', $this->responsable);
    expect(AnnulationRecu::query()->where('recu_id', $nouveau->id)->value('motif'))->toBe('Versement annulé : Bordereau rejeté par la banque');
});

it('donne à l\'auditeur le reçu de ses versements, et à lui seul', function (): void {
    $v = ($this->verser)(185000);
    $r = $this->service->emettre($v, $this->financier);

    $this->withToken(($this->jeton)($this->awa))->getJson('/v1/applications/me/scolarite')->assertOk()
        ->assertJsonPath('data.versements.0.recu.numero', 'RCPT-2026-000001')
        ->assertJsonPath('data.versements.0.recu.uuid', $r->uuid)
        ->assertJsonMissingPath('data.versements.0.recu.id');
    $pdf = $this->withToken(($this->jeton)($this->awa))->get('/v1/applications/me/scolarite/recus/'.$r->uuid)->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($pdf->headers->get('Content-Disposition'))->toContain('rcpt-2026-000001.pdf');

    $this->app['auth']->forgetGuards();
    $this->withToken(($this->jeton)($this->bruno))->get('/v1/applications/me/scolarite/recus/'.$r->uuid)->assertNotFound();
});

it('réserve l\'émission au service financier dans la fiche de l\'auditeur et la liste des reçus', function (): void {
    $v = ($this->verser)(185000);
    $fiche = $this->awa->auditeur;

    $this->actingAs($this->agent);
    $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $fiche, 'pageClass' => ViewAuditeur::class])
        ->assertTableActionHidden('emettreRecu', $v);

    $this->actingAs($this->financier);
    $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $fiche, 'pageClass' => ViewAuditeur::class])
        ->assertCanSeeTableRecords([$v])
        ->callTableAction('emettreRecu', $v)->assertHasNoTableActionErrors()
        ->assertTableActionVisible('annulerRecu', $v->refresh())
        ->callTableAction('annulerRecu', $v, data: ['motif' => 'non'])->assertHasTableActionErrors(['motif']);
    expect(RecuVersement::query()->where('versement_id', $v->id)->value('numero'))->toBe('RCPT-2026-000001');

    $this->get(RecuVersementResource::getUrl('index'))->assertOk()->assertSee('RCPT-2026-000001')->assertSee('ABENA Essai');
    $this->actingAs($this->agent);
    $this->get(RecuVersementResource::getUrl('index'))->assertOk()->assertDontSee('Annuler le reçu');
});

it('rend les reçus et leurs annulations immuables jusque dans la base', function (): void {
    // Chaque tentative dans son point de sauvegarde : l'erreur PostgreSQL n'interrompt pas la transaction du test.
    $r = $this->service->emettre(($this->verser)(185000), $this->financier);
    $a = $this->service->annuler($r, 'Erreur sur le payeur', $this->financier);

    expect(fn () => DB::transaction(fn () => RecuVersement::query()->whereKey($r->id)->update(['montant' => 1])))->toThrow(QueryException::class, 'immuable');
    expect(fn () => DB::transaction(fn () => DB::table('recus_versements')->where('id', $r->id)->delete()))->toThrow(QueryException::class, 'immuable');
    expect(fn () => DB::transaction(fn () => DB::table('annulations_recus')->where('id', $a->id)->update(['motif' => 'Autre motif inventé'])))->toThrow(QueryException::class, 'immuable');
    expect(fn () => DB::transaction(fn () => $this->financier->delete()))->toThrow(QueryException::class);
});

it('refuse le reçu sans date de mise en service ou pour un dossier retiré', function (): void {
    $v = ($this->verser)(185000);

    AppSettings::set(RecusVersements::CLE_DEPUIS, null);
    expect(fn () => $this->service->emettre($v, $this->financier))->toThrow(InvalidArgumentException::class, 'mise en service');
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());

    $this->awa->forceFill(['withdrawn_at' => now()])->save();
    expect(fn () => $this->service->emettre($v->refresh(), $this->financier))->toThrow(InvalidArgumentException::class, 'retiré');
});

it('sert à l\'auditeur son reçu annulé, journalise le téléchargement, et répond 404 sans scolarité', function (): void {
    $r = $this->service->emettre(($this->verser)(185000), $this->financier);
    $this->service->annuler($r, 'Erreur sur le payeur', $this->financier);

    $this->withToken(($this->jeton)($this->awa))->get('/v1/applications/me/scolarite/recus/'.$r->uuid)->assertOk();
    expect(Activity::query()->where('event', 'recu_telecharge')->where('subject_id', $r->id)->exists())->toBeTrue();

    $sansScolarite = User::factory()->create(['email' => Str::random(8).'@example.org']);
    $this->app['auth']->forgetGuards();
    $this->withToken($sansScolarite->createToken('candidat', ['application:read'])->plainTextToken)
        ->getJson('/v1/applications/me/scolarite/recus/'.$r->uuid)->assertNotFound()->assertJsonPath('code', 'recu_missing');
});

it('liste les versements qui attendent leur reçu, sauf pour l\'agent qui les a saisis', function (): void {
    $a = ($this->verser)(185000);
    $b = ($this->verser)(300000, '2026-11-05');

    expect($this->service->enAttente($this->financier)->pluck('id')->all())->toEqualCanonicalizing([$a->id, $b->id])
        ->and($this->service->enAttente($this->agent)->count())->toBe(0);

    $this->service->emettre($a, $this->financier);

    expect($this->service->enAttente($this->financier)->pluck('id')->all())->toBe([$b->id]);
});

it('n\'attend aucun reçu tant que la mise en service n\'est pas réglée', function (): void {
    ($this->verser)(185000);
    AppSettings::set(RecusVersements::CLE_DEPUIS, null);

    expect($this->service->enAttente($this->financier)->count())->toBe(0);
});

it('signale au service financier, dans le menu, les versements sans reçu', function (): void {
    ($this->verser)(185000);

    $this->actingAs($this->financier);
    expect(RecuVersementResource::getNavigationBadge())->toBe('1');

    $this->actingAs($this->agent);
    expect(RecuVersementResource::getNavigationBadge())->toBeNull();
});
