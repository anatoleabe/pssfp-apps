<?php

declare(strict_types=1);

use App\Filament\Resources\SyntheseAnimationResource;
use App\Filament\Resources\SyntheseAnimationResource\Pages\CreateSyntheseAnimation;
use App\Filament\Resources\SyntheseAnimationResource\Pages\ListSynthesesAnimation;
use App\Filament\Resources\SyntheseAnimationResource\Pages\ViewSyntheseAnimation;
use App\Models\ActiviteAnimation;
use App\Models\EmploiDuTemps;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\Parcours;
use App\Models\PlageHoraire;
use App\Models\ProgrammationEnseignant;
use App\Models\Seance;
use App\Models\SyntheseAnimation;
use App\Models\User;
use App\Services\Lmd\Calendriers;
use App\Services\Lmd\EmploisDuTemps;
use App\Services\Lmd\EtatsPaie;
use App\Services\Lmd\ExportWhatsApp;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\PointagesEnseignant;
use App\Services\Lmd\Programmations;
use App\Services\Lmd\SynthesesAnimation;
use App\Support\AppSettings;
use App\Support\CandidatureObjectStorage;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

/** Export fictif de D25 : 10 lignes, Android et iPhone, un message sur deux lignes, un message hors période. */
const EXPORT_D25 = <<<'TXT'
02/11/2026 08:15 - Dr Essai Prof: Bonjour à tous, le support du chapitre 1 est en ligne
02/11/2026 08:16 - Dr Essai Prof: <Médias omis>
02/11/2026 09:00 - Auditeur Un: Merci professeur
[05/11/2026 10:00:03] Dr Essai Prof: Rappel : le quiz ferme vendredi
suite du rappel sur une deuxième ligne
[05/11/2026 10:05:41] Dr Essai Prof: ‎<attached: 00000012-cours2.pdf>
05/11/2026 à 11:00 - Auditeur Deux: Bien reçu
09/11/2026 14:30 - Dr Essai Prof: Correction du devoir disponible demain
09/11/2026 14:31 - Dr Essai Prof: audio omis
15/12/2026 09:00 - Dr Essai Prof: Message hors période
TXT;

beforeEach(function (): void {
    Storage::fake(CandidatureObjectStorage::DISK);
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    Maquette::query()->update(['statut' => Maquette::VALIDEE, 'validee_at' => now()]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Essai '.Str::random(4)]), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->financier = ($this->compte)('service_financier');
    $this->profA = ($this->compte)('enseignant');
    $this->profB = ($this->compte)('enseignant');
    $this->pointages = app(PointagesEnseignant::class);
    $this->etats = app(EtatsPaie::class);
    $this->syntheses = app(SynthesesAnimation::class);
    $programmations = app(Programmations::class);

    $calendriers = app(Calendriers::class);
    $c = $calendriers->creer('2026-2027', '2026-10-01', '2027-08-31', $this->agent);
    $periode = $calendriers->enregistrer($c, ['type' => 'periode_cours', 'libelle' => 'Première période de cours', 'niveau' => null,
        'debut_prevu' => '2026-11-02', 'fin_prevue' => '2026-12-19'], $this->agent);
    $calendriers->soumettre($c->refresh(), $this->agent);
    $calendriers->signer($c->refresh(), UploadedFile::fake()->createWithContent('cal.pdf', "%PDF-1.4\n%x\n"), 'Le Chef de la structure', '2026-11-01', ($this->compte)('chef_structure'));

    $this->ecue = MaquetteEcue::query()->where('code', 'MPMPP711')->sole();
    $quota = $programmations->definirQuota($periode, $this->ecue, ['heures_cm' => 30], $this->agent);
    $programmations->validerQuota($quota, $this->responsable);
    $this->programmer = function (User $prof, float $cm) use ($programmations, $quota): ProgrammationEnseignant {
        $p = $programmations->programmer($quota, ['enseignant_id' => $prof->id, 'role' => 'intervenant', 'heures_cm' => $cm, 'modalite' => 'hybride',
            'debut_prevu' => '2026-11-02', 'fin_prevue' => '2026-11-20'], $this->agent);
        $programmations->valider($p, $this->responsable);

        return $p->refresh();
    };
    $this->progA = ($this->programmer)($this->profA, 6);
    $this->progB = ($this->programmer)($this->profB, 3);

    $edt = app(EmploisDuTemps::class);
    $e = $edt->creer($periode, Parcours::query()->where('code', 'TC')->sole(), $this->agent);
    $edt->programmer($e, ['maquette_ecue_id' => $this->ecue->id, 'date_seance' => '2026-11-02', 'plage_horaire_id' => PlageHoraire::query()->where('code', 'MATIN')->sole()->id,
        'enseignant_id' => $this->profA->id, 'modalite' => 'presentiel', 'nature' => 'CM'], $this->agent, '2026-11-09');   // 2 lundis, 08:00-11:00
    $e->forceFill(['statut' => EmploiDuTemps::PUBLIE])->save();
    $seances = Seance::query()->orderBy('date_seance')->get();
    $seanceB = $seances[0]->replicate(['uuid'])->forceFill(['date_seance' => '2026-11-03', 'enseignant_id' => $this->profB->id, 'programmation_id' => null]);
    $seanceB->save();
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 20)->setTime(10, 0));

    $this->pointer = function (Seance $s, User $prof): void {
        $p = $this->pointages->emarger($s, $prof, ['arrivee' => '08:00', 'depart' => '11:00', 'nature' => 'CM', 'contenu_enseigne' => 'Séance du '.$s->date_seance->format('d/m')], $this->agent);
        $this->pointages->valider($p, $this->responsable);
    };
    $this->seancesA = $seances;
    $this->seanceB = $seanceB;
    $this->dossier = function (User $u): void {
        $d = $this->pointages->ouvrirDossier($u, $this->agent);
        $this->pointages->majDossier($d, ['statut_paie' => 'vacataire', 'niu' => 'P0000000'.random_int(10000, 99999).'X', 'banque' => 'Banque fictive',
            'compte_paiement' => '10000 00000 '.random_int(10000000, 99999999).' 00'], $this->financier);
    };
    // Synthèse : 4 documents, 5 jours, 5 messages attendus (14 au total) ; réalisations données.
    $this->synthese = function (ProgrammationEnseignant $p, array $realise = [0, 0, 0]): SyntheseAnimation {
        return $this->syntheses->creer($p, ['periode_du' => '2026-11-02', 'periode_au' => '2026-11-20', 'groupe_whatsapp' => 'Cours fictif', 'activites' => [
            ['type_activite' => ActiviteAnimation::DOCUMENTS, 'famille' => 'contribution', 'attendu' => 4, 'realise' => $realise[0]],
            ['type_activite' => ActiviteAnimation::JOURS, 'famille' => 'animation', 'attendu' => 5, 'realise' => $realise[1]],
            ['type_activite' => ActiviteAnimation::MESSAGES, 'famille' => 'animation', 'attendu' => 5, 'realise' => $realise[2]],
        ]], $this->agent);
    };
    $this->fichier = function (string $contenu, string $nom = 'Discussion WhatsApp avec Cours fictif.txt'): string {
        $chemin = tempnam(sys_get_temp_dir(), 'wa');
        file_put_contents($chemin, $contenu);

        return $chemin;
    };
});

it('compte l\'export fictif de D25 et paie 64,3 % de la prime : 64 290 brut, 7 072 de retenue, 57 218 net', function (): void {
    ($this->dossier)($this->profA);
    $s = ($this->synthese)($this->progA);

    $r = $this->syntheses->importer($s, ($this->fichier)(EXPORT_D25), 'Discussion WhatsApp avec Cours fictif.txt', 'dr essai PROF', $this->agent);

    expect($r['comptes'])->toBe([ActiviteAnimation::DOCUMENTS => 2, ActiviteAnimation::JOURS => 3, ActiviteAnimation::MESSAGES => 4])
        ->and($r['lignes'])->toBe(9)
        ->and($this->syntheses->taux($s->refresh())['global'])->toBe(64.3)
        ->and($s->source)->toBe('export_whatsapp')
        ->and($s->justificatif)->toStartWith('export WhatsApp Discussion WhatsApp avec Cours fictif.txt sha256=')
        ->and($s->justificatif)->toContain(hash('sha256', EXPORT_D25));
    // Ni le contenu des messages ni le nom saisi ne sont enregistrés.
    $journal = json_encode(Activity::query()->where('subject_type', SyntheseAnimation::class)->get()->toArray());
    expect($journal)->not->toContain('dr essai')->not->toContain('quiz')->not->toContain('Auditeur');

    $this->syntheses->valider($s, $this->responsable);
    $etat = $this->etats->creerMensuel('2026-11', $this->agent);
    $prime = $etat->lignes()->where('nature', EtatsPaie::PRIME)->sole();

    expect($prime->quantite)->toBe(0.6429)
        ->and($prime->montant_brut)->toBe(64290)
        ->and($prime->montant_retenue)->toBe(7072)
        ->and($prime->montant_net)->toBe(57218)
        ->and($prime->observation)->toBe('Animation en ligne du cours : 64,3 % réalisé')
        ->and($s->refresh()->ligne_paie_id)->toBe($prime->id);
});

it('lit aussi l\'archive de l\'iPhone et refuse un fichier qui n\'est pas un export', function (): void {
    $zip = new ZipArchive;
    $chemin = tempnam(sys_get_temp_dir(), 'wa').'.zip';
    $zip->open($chemin, ZipArchive::CREATE);
    $zip->addFromString('_chat.txt', EXPORT_D25);
    $zip->close();
    $export = app(ExportWhatsApp::class);

    $lu = $export->lire($chemin);
    $r = $export->compter($lu['texte'], 'Dr Essai Prof', CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-20'));

    expect($r['comptes'])->toBe([ActiviteAnimation::DOCUMENTS => 2, ActiviteAnimation::JOURS => 3, ActiviteAnimation::MESSAGES => 4])
        ->and($lu['empreinte'])->toBe(hash_file('sha256', $chemin));
    expect(fn () => $export->compter("Bonjour\nceci n'est pas un export", 'X', CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-20')))
        ->toThrow(InvalidArgumentException::class, 'Aucune ligne reconnue');
    expect(fn () => $export->compter(EXPORT_D25, ' - ', CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-20')))
        ->toThrow(InvalidArgumentException::class, 'nom ou le numéro');
});

it('paie l\'état de novembre de D25 : 347 500 brut et 309 275 net pour deux enseignants', function (): void {
    ($this->dossier)($this->profA);
    ($this->dossier)($this->profB);
    ($this->pointer)($this->seancesA[0], $this->profA);
    ($this->pointer)($this->seancesA[1], $this->profA);
    ($this->pointer)($this->seanceB, $this->profB);
    $this->syntheses->valider(($this->synthese)($this->progA, [4, 5, 5]), $this->responsable);   // 100 %
    $this->syntheses->valider(($this->synthese)($this->progB, [4, 5, 0]), $this->agent);        // 9 / 14 → 64,3 %
    $sB = SyntheseAnimation::query()->where('enseignant_id', $this->profB->id)->sole();
    $this->syntheses->rouvrir($sB, 'Erreur de saisie des messages', $this->agent);
    $this->syntheses->modifier($sB->refresh(), ['periode_du' => '2026-11-02', 'periode_au' => '2026-11-20', 'activites' => [
        ['type_activite' => 'Classes virtuelles', 'famille' => 'animation', 'attendu' => 10, 'realise' => 9],
    ]], $this->agent);
    $this->syntheses->valider($sB->refresh(), $this->agent);   // 90 %

    $etat = $this->etats->creerMensuel('2026-11', $this->agent);
    $par = collect($this->etats->synthese($etat)['lignes'])->keyBy('enseignant');

    expect($par[$this->profA->name])->toMatchArray(['heures_cm' => 6.0, 'primes' => 100000, 'brut' => 205000, 'net' => 182450])
        ->and($par[$this->profB->name])->toMatchArray(['heures_cm' => 3.0, 'primes' => 90000, 'brut' => 142500, 'net' => 126825])
        ->and($this->etats->synthese($etat)['totaux'])->toMatchArray(['brut' => 347500, 'net' => 309275, 'nb_primes' => 2, 'primes' => 190000]);
});

it('fige une prime payée, la libère au rejet de l\'état et la reprend sur l\'état suivant du mois', function (): void {
    ($this->dossier)($this->profA);
    $s = ($this->synthese)($this->progA, [4, 5, 5]);
    $this->syntheses->valider($s, $this->responsable);
    $etat = $this->etats->creerMensuel('2026-11', $this->agent);

    expect(fn () => $this->syntheses->rouvrir($s->refresh(), 'Correction tardive', $this->agent))->toThrow(InvalidArgumentException::class, 'état de paie');
    expect(fn () => $this->syntheses->modifier($s->refresh(), ['periode_du' => '2026-11-02', 'periode_au' => '2026-11-20', 'activites' => []], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'figée');

    $this->etats->rejeter($etat, 'Prime à contrôler', $this->responsable);
    expect($s->refresh()->ligne_paie_id)->toBeNull();

    $nouvel = $this->etats->creerMensuel('2026-11', $this->agent);
    expect($nouvel->numero)->toBe('EP-2026-11-2')
        ->and($nouvel->lignes()->where('nature', EtatsPaie::PRIME)->sole()->montant_brut)->toBe(100000);
});

it('ne paie ni une synthèse en brouillon ni celle d\'un enseignant écarté ; personne ne valide sa propre synthèse', function (): void {
    ($this->dossier)($this->profA);
    ($this->synthese)($this->progA, [4, 5, 5]);
    $sB = ($this->synthese)($this->progB, [4, 5, 5]);
    $this->syntheses->valider($sB, $this->responsable);   // profB sans dossier de paie

    $etat = $this->etats->creerMensuel('2026-11', $this->agent);
    expect($etat->lignes()->count())->toBe(0)
        ->and(collect($etat->ecartes)->pluck('raison')->all())->toBe(['pas de dossier de paie'])
        ->and($sB->refresh()->ligne_paie_id)->toBeNull();

    $this->profA->assignRole('scolarite_agent');
    $sA = SyntheseAnimation::query()->where('enseignant_id', $this->profA->id)->sole();
    expect($this->syntheses->peutValider($sA, $this->profA))->toBeFalse();
    expect(fn () => $this->syntheses->valider($sA, $this->profA))->toThrow(InvalidArgumentException::class, 'propre synthèse');
    expect(fn () => ($this->synthese)($this->progA))->toThrow(InvalidArgumentException::class, 'existe déjà');
});

it('en mode seuil (100 %), paie la prime entière ou rien', function (): void {
    ($this->dossier)($this->profA);
    ($this->dossier)($this->profB);
    AppSettings::set(SynthesesAnimation::CLE_MODE, SynthesesAnimation::SEUIL);
    AppSettings::set(SynthesesAnimation::CLE_SEUIL, 100);
    $this->syntheses->valider(($this->synthese)($this->progA, [4, 5, 5]), $this->responsable);
    $this->syntheses->valider(($this->synthese)($this->progB, [4, 5, 4]), $this->responsable);   // 13 / 14

    $etat = $this->etats->creerMensuel('2026-11', $this->agent);
    $prime = $etat->lignes()->where('nature', EtatsPaie::PRIME)->sole();

    expect($prime->enseignant_id)->toBe($this->profA->id)
        ->and($prime->montant_brut)->toBe(100000)
        ->and($prime->observation)->toContain('seuil atteint')
        ->and(SyntheseAnimation::query()->where('enseignant_id', $this->profB->id)->sole()->ligne_paie_id)->toBeNull();
    expect(fn () => $this->syntheses->definirMode(SynthesesAnimation::PRORATA, 100, $this->responsable))->toThrow(HttpException::class);
});

it('affiche les écrans : liste, création depuis une programmation, import de l\'export et validation', function (): void {
    $this->actingAs($this->agent);
    $this->get(SyntheseAnimationResource::getUrl('index'))->assertOk()->assertSee('Primes d\'animation');
    $this->actingAs($this->profA)->get(SyntheseAnimationResource::getUrl('index'))->assertForbidden();
    $this->actingAs($this->agent);

    Livewire::test(CreateSyntheseAnimation::class)
        ->fillForm(['programmation_id' => (string) $this->progA->id])
        ->assertFormSet(['periode_du' => '2026-11-02', 'periode_au' => '2026-11-20'])
        ->fillForm(['activites' => [
            ['type_activite' => ActiviteAnimation::DOCUMENTS, 'famille' => 'contribution', 'attendu' => 4, 'realise' => 0],
            ['type_activite' => ActiviteAnimation::JOURS, 'famille' => 'animation', 'attendu' => 5, 'realise' => 0],
            ['type_activite' => ActiviteAnimation::MESSAGES, 'famille' => 'animation', 'attendu' => 5, 'realise' => 0],
        ]])
        ->call('create')
        ->assertHasNoFormErrors();
    $s = SyntheseAnimation::query()->sole();
    // Livewire enregistre les fichiers de test vides : la lecture du fichier (couverte plus haut) est simulée ici.
    $this->partialMock(ExportWhatsApp::class, fn ($m) => $m->shouldReceive('lire')->once()->andReturn(['texte' => EXPORT_D25, 'empreinte' => hash('sha256', EXPORT_D25)]));

    Livewire::test(ViewSyntheseAnimation::class, ['record' => $s->uuid])
        ->assertActionVisible('importer')->assertActionVisible('modifier')
        ->callAction('importer', data: ['export' => UploadedFile::fake()->createWithContent('discussion.txt', EXPORT_D25), 'auteur' => 'Dr Essai Prof'])
        ->assertHasNoActionErrors()
        ->assertNotified('9 messages lus. Documents partagés : 2 ; Jours d\'animation : 3 ; Messages aux auditeurs : 4.');
    expect($s->activites()->pluck('realise', 'type_activite')->all())->toBe([ActiviteAnimation::DOCUMENTS => 2, ActiviteAnimation::JOURS => 3, ActiviteAnimation::MESSAGES => 4]);

    Livewire::test(ViewSyntheseAnimation::class, ['record' => $s->uuid])
        ->assertSee('64,3 %')
        ->callAction('valider')
        ->assertHasNoActionErrors();
    expect($s->refresh()->statut)->toBe(SyntheseAnimation::VALIDE);
    Livewire::test(ViewSyntheseAnimation::class, ['record' => $s->uuid])->assertActionHidden('modifier')->assertActionHidden('importer')->assertActionVisible('rouvrir');
});

it('refuse à l\'enseignant de la Scolarité d\'établir, modifier, rouvrir ou valider sa propre prime', function (): void {
    $this->profA->assignRole('scolarite_agent');
    expect(fn () => $this->syntheses->creer($this->progA, ['periode_du' => '2026-11-02', 'periode_au' => '2026-11-20', 'activites' => [
        ['type_activite' => 'Quiz', 'famille' => 'animation', 'attendu' => 1, 'realise' => 1],
    ]], $this->profA))->toThrow(InvalidArgumentException::class, 'propre synthèse');

    $s = ($this->synthese)($this->progA, [1, 1, 1]);
    expect(fn () => $this->syntheses->modifier($s, ['periode_du' => '2026-11-02', 'periode_au' => '2026-11-20', 'activites' => [
        ['type_activite' => 'Quiz', 'famille' => 'animation', 'attendu' => 1, 'realise' => 1],
    ]], $this->profA))->toThrow(InvalidArgumentException::class, 'propre synthèse');
    expect(fn () => $this->syntheses->importer($s, ($this->fichier)(EXPORT_D25), 'x.txt', 'Dr Essai Prof', $this->profA))->toThrow(InvalidArgumentException::class, 'propre synthèse');
    expect($this->profA->can('update', $s))->toBeFalse();

    $this->syntheses->valider($s, $this->agent);
    expect(fn () => $this->syntheses->rouvrir($s->refresh(), 'Je corrige mes chiffres', $this->profA))->toThrow(InvalidArgumentException::class, 'propre synthèse');
    $this->actingAs($this->profA);
    Livewire::test(ViewSyntheseAnimation::class, ['record' => $s->uuid])->assertActionHidden('rouvrir')->assertActionHidden('valider');
});

it('valide la version affichée, trace les corrections et ne paie pas une programmation déclinée', function (): void {
    ($this->dossier)($this->profA);
    $s = ($this->synthese)($this->progA, [4, 5, 5]);
    $vue = $this->syntheses->empreinte($s);
    $this->syntheses->modifier($s, ['periode_du' => '2026-11-02', 'periode_au' => '2026-11-20', 'activites' => [
        ['type_activite' => ActiviteAnimation::DOCUMENTS, 'famille' => 'contribution', 'attendu' => 1, 'realise' => 1],
    ]], $this->agent);
    expect(fn () => $this->syntheses->valider($s->refresh(), $this->responsable, $vue))->toThrow(InvalidArgumentException::class, 'a changé');

    $journal = Activity::query()->where('event', 'synthese_modifiee')->sole();
    expect($journal->properties['avant']['activites'])->toHaveCount(3)
        ->and($journal->properties['apres']['activites'][0])->toMatchArray(['attendu' => 1, 'realise' => 1]);

    $this->syntheses->valider($s->refresh(), $this->responsable, $this->syntheses->empreinte($s));
    app(Programmations::class)->annuler($this->progA->refresh(), 'Cours retiré de la maquette', $this->responsable);
    $etat = $this->etats->creerMensuel('2026-11', $this->agent);
    expect($etat->lignes()->count())->toBe(0)
        ->and(collect($etat->ecartes)->pluck('raison')->first())->toContain('déclinée ou annulée')
        ->and($s->refresh()->ligne_paie_id)->toBeNull();
});

it('réserve l\'écran à la Scolarité et le mode de calcul à l\'administration ; rejette et rouvre depuis la fiche', function (): void {
    $this->actingAs($this->financier)->get(SyntheseAnimationResource::getUrl('index'))->assertForbidden();
    $s = ($this->synthese)($this->progA, [4, 5, 5]);

    $this->actingAs($this->agent);
    Livewire::test(ListSynthesesAnimation::class)->assertActionHidden('mode')->assertCanSeeTableRecords([$s]);
    Livewire::test(ViewSyntheseAnimation::class, ['record' => $s->uuid])
        ->callAction('rejeter', data: ['motif' => ''])->assertHasActionErrors(['motif' => 'required']);
    Livewire::test(ViewSyntheseAnimation::class, ['record' => $s->uuid])
        ->callAction('rejeter', data: ['motif' => 'Activités à justifier'])->assertHasNoActionErrors();
    expect($s->refresh()->statut)->toBe(SyntheseAnimation::REJETE);
    Livewire::test(ViewSyntheseAnimation::class, ['record' => $s->uuid])
        ->callAction('rouvrir', data: ['motif' => 'Justificatifs reçus'])->assertHasNoActionErrors();
    expect($s->refresh()->statut)->toBe(SyntheseAnimation::BROUILLON);

    ($this->dossier)($this->profA);
    $this->syntheses->valider($s, $this->responsable);
    $this->etats->creerMensuel('2026-11', $this->agent);
    Livewire::test(ViewSyntheseAnimation::class, ['record' => $s->uuid])
        ->assertSee('EP-2026-11')
        ->assertActionHidden('modifier')->assertActionHidden('importer')->assertActionHidden('valider')->assertActionHidden('rejeter')->assertActionHidden('rouvrir');

    $this->actingAs(($this->compte)('admin'));
    Livewire::test(ListSynthesesAnimation::class)->assertActionVisible('mode')
        ->callAction('mode', data: ['mode' => SynthesesAnimation::SEUIL, 'seuil' => 90])->assertHasNoActionErrors();
    expect(AppSettings::get(SynthesesAnimation::CLE_MODE))->toBe(SynthesesAnimation::SEUIL);
});
