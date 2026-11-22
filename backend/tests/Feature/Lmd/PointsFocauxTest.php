<?php

declare(strict_types=1);

use App\Filament\Pages\Lmd\SaisieNotes as EcranSaisie;
use App\Filament\Resources\AffectationPointFocalResource;
use App\Filament\Resources\AuditeurResource;
use App\Filament\Resources\MaClasseResource;
use App\Filament\Resources\MaClasseResource\Pages\ListMaClasse;
use App\Filament\Resources\SeanceResource;
use App\Models\AffectationPointFocal;
use App\Models\AttributionEcue;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\EtapeSaisie;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\NoteEcue;
use App\Models\Parcours;
use App\Models\User;
use App\Services\Lmd\Assiduite;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\PerimetreCours;
use App\Services\Lmd\SaisieNotes;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    Maquette::query()->update(['statut' => Maquette::VALIDEE, 'validee_at' => now()]);
    $compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->responsable = $compte('scolarite_responsable');
    $this->pointFocal = $compte('point_focal');
    $this->enseignant = $compte('enseignant');

    $inscrire = function (string $niveau, ?string $specialite): InscriptionAnnuelle {
        $bareme = BaremeScolarite::query()->firstOrCreate(['annee_academique' => '2026-2027', 'niveau' => $niveau],
            ['montant_inscription' => 185000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000]);
        $auditeur = Auditeur::factory()->campagneCommune()->create();
        $auditeur->candidature->forceFill(['specialite' => $specialite])->save();
        $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id]);
        app(InscriptionsPedagogiques::class)->inscrire($i);

        return $i->refresh();
    };
    $this->m1 = $inscrire('M1', null);
    $this->m2 = $inscrire('M2', 'Gouvernance Territoriale et Finances Publiques locales');

    $this->tc = Parcours::query()->where('code', Parcours::TRONC_COMMUN)->firstOrFail();
    $this->ecueM1 = MaquetteEcue::query()->where('code', 'MPMPP711')->firstOrFail();
    $this->ecueM2 = MaquetteEcue::query()->whereHas('maquetteUe.maquette', fn ($q) => $q->where('parcours_id', $this->m2->parcours_id))->orderBy('id')->firstOrFail();
    $this->saisie = app(SaisieNotes::class);
    $this->assiduite = app(Assiduite::class);
    $this->affecter = fn (User $u, Parcours $p): AffectationPointFocal => AffectationPointFocal::query()
        ->create(['user_id' => $u->id, 'parcours_id' => $p->id, 'annee_academique' => '2026-2027']);
    $this->ip = fn (InscriptionAnnuelle $i, MaquetteEcue $e) => $i->inscriptionsPedagogiques()->where('maquette_ue_id', $e->maquette_ue_id)->firstOrFail();
});

it('ne donne ni cours ni auditeur à un point focal sans affectation', function (): void {
    expect(PerimetreCours::ecues($this->pointFocal)->count())->toBe(0)
        ->and(PerimetreCours::inscriptions($this->pointFocal)->count())->toBe(0)
        ->and($this->saisie->peutSaisir($this->saisie->etape($this->ecueM1, 'normale', 'cc'), $this->pointFocal))->toBeFalse();
});

it('laisse le point focal saisir le CC et l\'assiduité des cours de sa classe, jamais l\'examen ni une autre classe', function (): void {
    ($this->affecter)($this->pointFocal, $this->tc);
    $cc = $this->saisie->etape($this->ecueM1, 'normale', 'cc');
    $examen = $this->saisie->etape($this->ecueM1, 'normale', 'examen');
    $this->saisie->ouvrir($cc, $this->responsable);
    $this->saisie->ouvrir($examen, $this->responsable);

    expect($this->saisie->peutSaisir($cc, $this->pointFocal))->toBeTrue()
        ->and($this->saisie->peutSaisir($this->saisie->etape($this->ecueM1, 'normale', 'assiduite'), $this->pointFocal))->toBeTrue()
        ->and($this->saisie->peutSaisir($examen, $this->pointFocal))->toBeFalse()
        ->and($this->saisie->peutSaisir($this->saisie->etape($this->ecueM2, 'normale', 'cc'), $this->pointFocal))->toBeFalse();

    $ip = ($this->ip)($this->m1, $this->ecueM1);
    expect($this->saisie->saisir($cc->refresh(), [$ip->id => ['note' => 15]], $this->pointFocal))->toBe(1)
        ->and((string) NoteEcue::query()->where('inscription_pedagogique_id', $ip->id)->where('maquette_ecue_id', $this->ecueM1->id)->value('cc'))->toBe('15.00');
    expect(fn () => $this->saisie->saisir($examen->refresh(), [$ip->id => ['note' => 12]], $this->pointFocal))->toThrow(HttpException::class);
});

it('réserve à la scolarité l\'ouverture, le verrouillage et la réouverture d\'une étape', function (): void {
    ($this->affecter)($this->pointFocal, $this->tc);
    $cc = $this->saisie->etape($this->ecueM1, 'normale', 'cc');

    expect(fn () => $this->saisie->ouvrir($cc, $this->pointFocal))->toThrow(HttpException::class);
    $this->saisie->ouvrir($cc, $this->responsable);
    expect(fn () => $this->saisie->verrouiller($cc->refresh(), $this->pointFocal))->toThrow(HttpException::class);
    expect(fn () => $this->saisie->rouvrir($cc->refresh(), $this->pointFocal, 'Erreur de report signalée en salle'))->toThrow(HttpException::class);
});

it('fait tenir l\'assiduité de sa classe par le point focal : séance, feuille de présence, report dans les notes', function (): void {
    ($this->affecter)($this->pointFocal, $this->tc);
    $creneau = ['date_seance' => '2026-11-24', 'debut' => '08:00', 'fin' => '10:00'];

    expect($this->assiduite->peutGerer($this->ecueM1, $this->pointFocal))->toBeTrue()
        ->and($this->assiduite->peutGerer($this->ecueM2, $this->pointFocal))->toBeFalse();
    expect(fn () => $this->assiduite->planifier($this->ecueM2, $creneau, $this->pointFocal))->toThrow(HttpException::class);

    $seance = $this->assiduite->planifier($this->ecueM1, $creneau, $this->pointFocal);
    $ip = ($this->ip)($this->m1, $this->ecueM1);
    $this->assiduite->pointer($seance, [$ip->id], [], null, $this->pointFocal);

    $this->saisie->ouvrir($this->saisie->etape($this->ecueM1, 'normale', 'assiduite'), $this->responsable);
    expect($this->assiduite->reporter($this->ecueM1, $this->pointFocal))->toBe(1)
        ->and((string) NoteEcue::query()->where('inscription_pedagogique_id', $ip->id)->where('maquette_ecue_id', $this->ecueM1->id)->value('assiduite'))->toBe('20.00');
});

it('montre au point focal les auditeurs de sa classe, en lecture seule, et lui ferme les écrans de la scolarité', function (): void {
    ($this->affecter)($this->pointFocal, $this->tc);
    $this->actingAs($this->pointFocal);

    Livewire::test(ListMaClasse::class)
        ->assertCanSeeTableRecords([$this->m1])
        ->assertCanNotSeeTableRecords([$this->m2])
        ->assertSee((string) $this->m1->auditeur->candidature->matricule);

    $this->get(AuditeurResource::getUrl())->assertForbidden();
    $this->get(AffectationPointFocalResource::getUrl())->assertForbidden();
    $this->get(SeanceResource::getUrl())->assertOk();
    $this->get(EcranSaisie::getUrl())->assertOk();
});

it('ferme « Ma classe » à qui n\'est pas point focal', function (): void {
    $this->actingAs($this->responsable)->get(MaClasseResource::getUrl())->assertForbidden();
    $this->actingAs($this->enseignant)->get(MaClasseResource::getUrl())->assertForbidden();
});

it('fait affecter les points focaux par la préparation du référentiel, une seule fois par classe', function (): void {
    expect($this->responsable->can('create', AffectationPointFocal::class))->toBeTrue()
        ->and($this->pointFocal->can('viewAny', AffectationPointFocal::class))->toBeFalse()
        ->and($this->pointFocal->can('create', AffectationPointFocal::class))->toBeFalse();

    ($this->affecter)($this->pointFocal, $this->tc);
    expect(fn () => ($this->affecter)($this->pointFocal, $this->tc))->toThrow(UniqueConstraintViolationException::class);
});

it('garde le cloisonnement des enseignants : leurs ECUE attribués, rien de plus', function (): void {
    ($this->affecter)($this->pointFocal, $this->tc);
    $cc = $this->saisie->etape($this->ecueM1, 'normale', 'cc');

    expect($this->saisie->peutSaisir($cc, $this->enseignant))->toBeFalse();
    AttributionEcue::query()->create(['maquette_ecue_id' => $this->ecueM1->id, 'user_id' => $this->enseignant->id]);
    expect($this->saisie->peutSaisir($cc, $this->enseignant))->toBeTrue()
        ->and(PerimetreCours::ecues($this->enseignant)->pluck('id')->all())->toBe([$this->ecueM1->id])
        ->and(PerimetreCours::inscriptions($this->enseignant)->count())->toBe(0);
});

it('ne montre ni examen ni rattrapage au point focal, même en forçant les filtres de l\'écran de saisie', function (): void {
    ($this->affecter)($this->pointFocal, $this->tc);
    $ip = ($this->ip)($this->m1, $this->ecueM1);
    (new NoteEcue(['inscription_pedagogique_id' => $ip->id, 'maquette_ecue_id' => $this->ecueM1->id, 'session' => 'normale']))
        ->forceFill(['cc' => '13.00', 'examen' => '9.50', 'presence_examen' => 'present'])->save();
    $maquette = $this->ecueM1->maquetteUe->maquette_id;

    $ecran = Livewire::actingAs($this->pointFocal)->test(EcranSaisie::class)
        ->set('filtres.maquette_id', $maquette)->set('filtres.ecue_id', $this->ecueM1->id)
        ->set('filtres.session', 'normale')->set('filtres.etape', 'cc');
    expect($ecran->instance()->lignes())->toHaveCount(1);

    $ecran->set('filtres.etape', 'examen');
    expect($ecran->instance()->etapeCourante())->toBeNull()->and($ecran->instance()->lignes())->toHaveCount(0);

    $ecran->set('filtres.session', 'rattrapage');
    expect($ecran->instance()->etapeCourante())->toBeNull();
    expect(EtapeSaisie::query()->where('maquette_ecue_id', $this->ecueM1->id)->where('etape', 'examen')->exists())->toBeFalse();
});

it('exclut du périmètre les cours d\'une maquette non validée', function (): void {
    ($this->affecter)($this->pointFocal, $this->tc);
    expect(PerimetreCours::couvre($this->pointFocal, $this->ecueM1->id))->toBeTrue();

    Maquette::query()->whereKey($this->ecueM1->maquetteUe->maquette_id)->update(['statut' => Maquette::BROUILLON]);
    expect(PerimetreCours::couvre($this->pointFocal, $this->ecueM1->id))->toBeFalse();
});
