<?php

declare(strict_types=1);

use App\Filament\Pages\Lmd\SaisieNotes as PageSaisie;
use App\Filament\Resources\AttributionEcueResource;
use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Models\AttributionEcue;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\EtapeSaisie;
use App\Models\HistoriqueNote;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\NoteEcue;
use App\Models\RegleEvaluation;
use App\Models\User;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\NotesExcel;
use App\Services\Lmd\SaisieNotes;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    $this->tc = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'TC'))->firstOrFail();
    $this->tc->forceFill(['statut' => Maquette::VALIDEE, 'validee_at' => now()])->save();
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->agent = ($this->compte)('scolarite_agent');
    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->inscriptions = collect(range(1, 3))->map(function () use ($bareme): InscriptionAnnuelle {
        $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory()->campagneCommune(), 'bareme_id' => $bareme->id]);
        app(InscriptionsPedagogiques::class)->inscrire($i);

        return $i;
    });
    $this->ecue = MaquetteEcue::query()->where('code', 'MPMPP711')->firstOrFail();
    $this->ip = fn (int $n) => $this->inscriptions[$n]->inscriptionsPedagogiques()->where('maquette_ue_id', $this->ecue->maquette_ue_id)->firstOrFail();
    $this->service = app(SaisieNotes::class);
    $this->etapeCc = $this->service->etape($this->ecue, EtapeSaisie::NORMALE, EtapeSaisie::CC);
});

it('refuse la saisie dans une étape fermée (SAI-04)', function (): void {
    expect(fn () => $this->service->saisir($this->etapeCc, [($this->ip)(0)->id => ['note' => 12]], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'étape « Contrôle continu » fermée');
});

it('accepte la saisie dans une étape ouverte et historise chaque changement avec son motif (SAI-01, SAI-02)', function (): void {
    $this->service->ouvrir($this->etapeCc, $this->responsable);
    $ip = ($this->ip)(0);

    expect($this->service->saisir($this->etapeCc, [$ip->id => ['note' => '12,5']], $this->agent))->toBe(1);
    expect(fn () => $this->service->saisir($this->etapeCc, [$ip->id => ['note' => 14]], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'motif');
    $this->service->saisir($this->etapeCc, [$ip->id => ['note' => 14, 'motif' => 'Erreur de report']], $this->agent);

    $note = NoteEcue::query()->where('inscription_pedagogique_id', $ip->id)->sole();
    expect((float) $note->cc)->toBe(14.0)
        ->and($note->historique->map(fn ($h) => [$h->ancienne_valeur, $h->nouvelle_valeur, $h->motif])->all())
        ->toBe([[null, '12.50', 'Saisie'], ['12.50', '14.00', 'Erreur de report']]);
});

it('refuse une note hors bornes ou un auditeur non inscrit, sans rien écrire', function (): void {
    $this->service->ouvrir($this->etapeCc, $this->responsable);

    expect(fn () => $this->service->saisir($this->etapeCc, [($this->ip)(0)->id => ['note' => 12], ($this->ip)(1)->id => ['note' => 21]], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'entre 0 et 20');
    expect(fn () => $this->service->saisir($this->etapeCc, [999999 => ['note' => 12]], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'non inscrit');
    expect(NoteEcue::query()->count())->toBe(0);
});

it('refuse le verrouillage tant qu\'une note manque, puis fige l\'étape (SAI-03)', function (): void {
    $this->service->ouvrir($this->etapeCc, $this->responsable);
    $this->service->saisir($this->etapeCc, [($this->ip)(0)->id => ['note' => 12], ($this->ip)(1)->id => ['note' => 13]], $this->agent);

    expect(fn () => $this->service->verrouiller($this->etapeCc, $this->responsable))->toThrow(InvalidArgumentException::class, '1 auditeur(s) sans note');
    expect(fn () => $this->service->verrouiller($this->etapeCc, $this->agent))->toThrow(HttpException::class);

    $this->service->saisir($this->etapeCc, [($this->ip)(2)->id => ['note' => 9]], $this->agent);
    $this->service->verrouiller($this->etapeCc, $this->responsable);

    expect(fn () => $this->service->saisir($this->etapeCc, [($this->ip)(2)->id => ['note' => 10, 'motif' => 'Correction']], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'verrouillée');
    expect(fn () => $this->service->rouvrir($this->etapeCc, $this->responsable, 'court'))->toThrow(InvalidArgumentException::class);
    $this->service->rouvrir($this->etapeCc, $this->responsable, 'Erreur de report signalée par l\'enseignant');
    expect($this->etapeCc->refresh()->statut)->toBe(EtapeSaisie::OUVERTE)->and($this->etapeCc->motif_reouverture)->not->toBeNull();
});

it('garde l\'historique des notes en ajout seul, même directement en base', function (): void {
    $this->service->ouvrir($this->etapeCc, $this->responsable);
    $this->service->saisir($this->etapeCc, [($this->ip)(0)->id => ['note' => 12]], $this->agent);
    $h = HistoriqueNote::query()->firstOrFail();

    expect(fn () => DB::transaction(fn () => DB::table('historique_notes')->where('id', $h->id)->update(['nouvelle_valeur' => '20.00'])))->toThrow(QueryException::class, 'ajout seul');
    expect(fn () => DB::transaction(fn () => DB::table('historique_notes')->where('id', $h->id)->delete()))->toThrow(QueryException::class, 'ajout seul');
});

it('saisit l\'absence à l\'examen sans note, et refuse une note pour un absent', function (): void {
    $examen = $this->service->etape($this->ecue, EtapeSaisie::NORMALE, EtapeSaisie::EXAMEN);
    $this->service->ouvrir($examen, $this->responsable);

    expect(fn () => $this->service->saisir($examen, [($this->ip)(0)->id => ['note' => 8, 'presence' => 'absent_justifie']], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'absent');
    $this->service->saisir($examen, [($this->ip)(0)->id => ['presence' => 'absent_justifie'], ($this->ip)(1)->id => ['note' => 11]], $this->agent);

    $notes = NoteEcue::query()->get()->keyBy('inscription_pedagogique_id');
    expect($notes[($this->ip)(0)->id]->presence_examen)->toBe('absent_justifie')
        ->and($notes[($this->ip)(0)->id]->examen)->toBeNull()
        ->and($notes[($this->ip)(1)->id]->presence_examen)->toBe('present');
});

it('n\'ouvre le rattrapage qu\'une fois l\'examen de la session normale verrouillé', function (): void {
    $rattrapage = $this->service->etape($this->ecue, EtapeSaisie::RATTRAPAGE, EtapeSaisie::EXAMEN);

    expect(fn () => $this->service->ouvrir($rattrapage, $this->responsable))->toThrow(InvalidArgumentException::class, 'session normale');
    expect(fn () => $this->service->etape($this->ecue, EtapeSaisie::RATTRAPAGE, EtapeSaisie::CC))->toThrow(InvalidArgumentException::class);
});

it('limite l\'enseignant au CC et à l\'assiduité de ses ECUE', function (): void {
    $enseignant = ($this->compte)('enseignant');
    $this->service->ouvrir($this->etapeCc, $this->responsable);
    $examen = $this->service->etape($this->ecue, EtapeSaisie::NORMALE, EtapeSaisie::EXAMEN);
    $this->service->ouvrir($examen, $this->responsable);

    expect($this->service->peutSaisir($this->etapeCc, $enseignant))->toBeFalse();
    AttributionEcue::query()->create(['maquette_ecue_id' => $this->ecue->id, 'user_id' => $enseignant->id]);
    expect($this->service->peutSaisir($this->etapeCc, $enseignant))->toBeTrue()
        ->and($this->service->peutSaisir($examen, $enseignant))->toBeFalse()
        ->and($this->service->peutSaisir($this->service->etape(MaquetteEcue::query()->where('code', 'MPMPP712')->firstOrFail(), 'normale', 'cc'), $enseignant))->toBeFalse();
    expect(fn () => $this->service->saisir($examen, [($this->ip)(0)->id => ['note' => 12]], $enseignant))->toThrow(HttpException::class);
    expect($this->service->saisir($this->etapeCc, [($this->ip)(0)->id => ['note' => 15]], $enseignant))->toBe(1);
});

it('importe le modèle Excel rempli, en essai à blanc puis pour de bon', function (): void {
    $this->service->ouvrir($this->etapeCc, $this->responsable);
    $chemin = tempnam(sys_get_temp_dir(), 'notes').'.xlsx';
    file_put_contents($chemin, app(NotesExcel::class)->modele($this->etapeCc));
    $classeur = IOFactory::load($chemin);
    $feuille = $classeur->getSheet(0);
    expect($feuille->getCell('A4')->getValue())->toBe('Matricule');
    $feuille->setCellValue('D5', '13,5');
    $feuille->setCellValue('D6', 11);
    IOFactory::createWriter($classeur, 'Xlsx')->save($chemin);

    expect(app(NotesExcel::class)->importer($this->etapeCc, $chemin, $this->agent, false))->toBe(2)
        ->and(NoteEcue::query()->count())->toBe(0);
    expect(app(NotesExcel::class)->importer($this->etapeCc, $chemin, $this->agent, true))->toBe(2)
        ->and(NoteEcue::query()->whereNotNull('cc')->count())->toBe(2);

    $feuille->setCellValue('A7', '99EFP999');
    $feuille->setCellValue('D7', 10);
    IOFactory::createWriter($classeur, 'Xlsx')->save($chemin);
    expect(fn () => app(NotesExcel::class)->importer($this->etapeCc, $chemin, $this->agent, true))->toThrow(InvalidArgumentException::class, '99EFP999');
    @unlink($chemin);
});

it('ouvre l\'écran de saisie à la scolarité et à l\'enseignant, pas aux autres', function (string $role, int $statut): void {
    $this->actingAs(($this->compte)($role));

    $this->get(PageSaisie::getUrl())->assertStatus($statut);
})->with([['scolarite_agent', 200], ['scolarite_responsable', 200], ['enseignant', 200], ['admission_committee', 403], ['librarian', 403]]);

it('enregistre les notes depuis l\'écran', function (): void {
    $this->service->ouvrir($this->etapeCc, $this->responsable);
    $this->actingAs($this->agent);
    $ip = ($this->ip)(0);

    Livewire::test(PageSaisie::class)
        ->fillForm(['maquette_id' => $this->tc->id, 'ecue_id' => $this->ecue->id, 'session' => 'normale', 'etape' => 'cc'])
        ->set("saisies.{$ip->id}.note", '15')
        ->call('enregistrer')
        ->assertNotified('1 note(s) enregistrée(s)');

    expect((float) NoteEcue::query()->where('inscription_pedagogique_id', $ip->id)->value('cc'))->toBe(15.0);
});

it('montre les notes provisoires sur la fiche et consigne une dérogation au rattrapage', function (): void {
    $this->service->ouvrir($this->etapeCc, $this->responsable);
    $this->service->saisir($this->etapeCc, [($this->ip)(0)->id => ['note' => '9,5']], $this->agent);
    $auditeur = $this->inscriptions[0]->auditeur;

    $this->actingAs($this->agent);
    Livewire::test(ViewAuditeur::class, ['record' => $auditeur->getRouteKey()])
        ->assertSee('Notes de l\'année')->assertSee('MPMPP711')->assertSee('9,50')->assertSee('Incomplète')
        ->assertActionHidden('derogationRattrapage');

    $this->actingAs($this->responsable);
    Livewire::test(ViewAuditeur::class, ['record' => $auditeur->getRouteKey()])
        ->callAction('derogationRattrapage', ['inscription_pedagogique_id' => ($this->ip)(0)->id, 'accordee_par' => 'Le Chef de la structure',
            'reference_acte' => 'DS 26/001', 'date_acte' => now()->toDateString(), 'motif' => 'Maladie attestée pendant le CC'])
        ->assertHasNoActionErrors();

    expect(($this->ip)(0)->derogationRattrapage?->reference_acte)->toBe('DS 26/001')
        ->and($this->service->resultat(($this->ip)(0)->refresh(), RegleEvaluation::pourAnnee('2026-2027'))->eligibleRattrapage)->toBeTrue();
});

it('réserve la gestion des attributions à la préparation du référentiel', function (): void {
    $this->actingAs(($this->compte)('enseignant'));
    $this->get(AttributionEcueResource::getUrl('index'))->assertForbidden();

    $this->actingAs($this->agent);
    $this->get(AttributionEcueResource::getUrl('create'))->assertOk();
});
