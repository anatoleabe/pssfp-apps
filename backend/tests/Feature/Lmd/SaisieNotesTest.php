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
use App\Services\Lmd\ImportRefuse;
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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
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

it('rend un rapport d\'import ligne à ligne, sans rien enregistrer, quand plusieurs lignes sont en erreur', function (): void {
    $this->service->ouvrir($this->etapeCc, $this->responsable);
    $chemin = tempnam(sys_get_temp_dir(), 'notes').'.xlsx';
    file_put_contents($chemin, app(NotesExcel::class)->modele($this->etapeCc));
    $classeur = IOFactory::load($chemin);
    $feuille = $classeur->getSheet(0);
    $feuille->setCellValue('D5', 13);                                             // ligne valide
    $feuille->setCellValue('D6', 25);                                             // note hors bornes
    $feuille->setCellValue('A7', '99EFP999');                                     // matricule non inscrit
    $feuille->setCellValue('D7', 10);
    $feuille->setCellValueExplicit('A8', (string) $feuille->getCell('A5')->getValue(), DataType::TYPE_STRING); // doublon de la ligne 5
    $feuille->setCellValue('D8', 9);
    IOFactory::createWriter($classeur, 'Xlsx')->save($chemin);

    $refus = null;
    try {
        app(NotesExcel::class)->importer($this->etapeCc, $chemin, $this->agent, true);
    } catch (ImportRefuse $e) {
        $refus = $e;
    }
    @unlink($chemin);

    expect($refus)->not->toBeNull()
        ->and(array_column($refus->lignes, 'ligne'))->toBe([6, 7, 8])
        ->and($refus->lignes[1])->toBe(['ligne' => 7, 'matricule' => '99EFP999', 'message' => 'matricule non inscrit à cette UE'])
        ->and($refus->lignes[2]['message'])->toBe('matricule déjà présent à la ligne 5')
        ->and($refus->getMessage())->toContain('3 lignes en erreur')
        ->and(NoteEcue::query()->count())->toBe(0);

    $rapport = tempnam(sys_get_temp_dir(), 'rapport').'.xlsx';
    file_put_contents($rapport, app(NotesExcel::class)->rapport($this->etapeCc, $refus->lignes));
    $lu = IOFactory::load($rapport)->getSheet(0);
    @unlink($rapport);
    expect($lu->getCell('A5')->getValue())->toBe(6)
        ->and($lu->getCell('B6')->getValue())->toBe('99EFP999')
        ->and($lu->getHighestDataRow())->toBe(7);
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

it('rend à l\'essai à blanc le sort de chaque ligne du fichier, sans rien enregistrer', function (): void {
    $this->service->ouvrir($this->etapeCc, $this->responsable);
    $excel = app(NotesExcel::class);
    $chemin = tempnam(sys_get_temp_dir(), 'notes').'.xlsx';
    file_put_contents($chemin, $excel->modele($this->etapeCc));
    $classeur = IOFactory::load($chemin);
    $feuille = $classeur->getSheet(0);
    $feuille->setCellValue('D5', 13);
    IOFactory::createWriter($classeur, 'Xlsx')->save($chemin);
    $excel->importer($this->etapeCc, $chemin, $this->agent, true);                // la ligne 5 a désormais 13

    file_put_contents($chemin, $excel->modele($this->etapeCc));
    $classeur = IOFactory::load($chemin);
    $feuille = $classeur->getSheet(0);
    $feuille->setCellValue('D5', 13);                                             // inchangée
    $feuille->setCellValue('D6', '14,5');                                         // nouvelle note
    $feuille->setCellValue('A7', '99EFP999');                                     // matricule non inscrit
    $feuille->setCellValue('D7', 10);
    IOFactory::createWriter($classeur, 'Xlsx')->save($chemin);
    $essai = $excel->essai($this->etapeCc, $chemin, $this->agent);

    expect(array_column($essai['lignes'], 'ligne'))->toBe([5, 6, 7])
        ->and(array_column($essai['lignes'], 'resultat'))->toBe([SaisieNotes::INCHANGEE, SaisieNotes::NOUVELLE, SaisieNotes::ERREUR])
        ->and([$essai['lignes'][0]['avant'], $essai['lignes'][0]['apres']])->toBe(['13,00', '13,00'])
        ->and([$essai['lignes'][1]['avant'], $essai['lignes'][1]['apres']])->toBe([null, '14,50'])
        ->and($essai['lignes'][1]['nom'])->not->toBe('')
        ->and($essai['lignes'][2]['message'])->toBe('matricule non inscrit à cette UE')
        ->and($essai['comptes'])->toBe([SaisieNotes::NOUVELLE => 1, SaisieNotes::MODIFIEE => 0, SaisieNotes::INCHANGEE => 1, SaisieNotes::ERREUR => 1])
        ->and(NotesExcel::resume($essai['comptes']))->toBe('1 nouvelle note, 1 inchangée, 1 erreur.')
        ->and(NoteEcue::query()->whereNotNull('cc')->count())->toBe(1);           // rien de plus qu'avant l'essai

    // Une note déjà saisie qui change sans motif : erreur, avec l'avant et l'après ; avec un motif : modifiée.
    $feuille->setCellValue('D5', 15);
    $feuille->setCellValue('A7', null);
    $feuille->setCellValue('D7', null);
    IOFactory::createWriter($classeur, 'Xlsx')->save($chemin);
    $sansMotif = $excel->essai($this->etapeCc, $chemin, $this->agent)['lignes'][0];
    $feuille->setCellValue('F5', 'Erreur de report de la copie');
    IOFactory::createWriter($classeur, 'Xlsx')->save($chemin);
    $avecMotif = $excel->essai($this->etapeCc, $chemin, $this->agent);

    expect([$sansMotif['resultat'], $sansMotif['avant'], $sansMotif['apres']])->toBe([SaisieNotes::ERREUR, '13,00', '15,00'])
        ->and($avecMotif['lignes'][0]['resultat'])->toBe(SaisieNotes::MODIFIEE);

    $rapport = tempnam(sys_get_temp_dir(), 'essai').'.xlsx';
    file_put_contents($rapport, $excel->rapportEssai($this->etapeCc, $avecMotif));
    $lu = IOFactory::load($rapport)->getSheet(0);
    @unlink($rapport);
    @unlink($chemin);
    expect($lu->getCell('F5')->getValue())->toBe('Note modifiée')
        ->and($lu->getCell('D5')->getValue())->toBe('13,00')
        ->and($lu->getCell('E6')->getValue())->toBe('14,50')
        ->and((string) $lu->getCell('A2')->getValue())->toContain('Rien n\'a été enregistré');
});
