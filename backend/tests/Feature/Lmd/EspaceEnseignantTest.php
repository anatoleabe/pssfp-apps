<?php

declare(strict_types=1);

use App\Filament\Pages\Lmd\MesCours;
use App\Filament\Pages\Lmd\MesHeures;
use App\Models\EmploiDuTemps;
use App\Models\EtatPaie;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\Parcours;
use App\Models\PlageHoraire;
use App\Models\PointageEnseignant;
use App\Models\Salle;
use App\Models\Seance;
use App\Models\User;
use App\Services\Lmd\Calendriers;
use App\Services\Lmd\EmploisDuTemps;
use App\Services\Lmd\EspaceEnseignant;
use App\Services\Lmd\EtatsPaie;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\PlanningsExamens;
use App\Services\Lmd\PointagesEnseignant;
use App\Services\Lmd\Programmations;
use App\Services\Scolarite\LibelleSituation;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses()->group('lmd');

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
    $this->prof = ($this->compte)('enseignant');
    $this->autre = ($this->compte)('enseignant');
    $this->espace = app(EspaceEnseignant::class);
    $this->pointages = app(PointagesEnseignant::class);
    $programmations = app(Programmations::class);

    $calendriers = app(Calendriers::class);
    $c = $calendriers->creer('2026-2027', '2026-10-01', '2027-08-31', $this->agent);
    $this->periode = $calendriers->enregistrer($c, ['type' => 'periode_cours', 'libelle' => 'Première période de cours', 'niveau' => null,
        'debut_prevu' => '2026-11-02', 'fin_prevue' => '2026-12-19'], $this->agent);
    $this->examens = $calendriers->enregistrer($c, ['type' => 'examens', 'libelle' => 'Examens M1', 'niveau' => 'M1', 'session' => 'normale',
        'debut_prevu' => '2027-02-08', 'fin_prevue' => '2027-02-12'], $this->agent);
    $calendriers->soumettre($c->refresh(), $this->agent);
    $calendriers->signer($c->refresh(), UploadedFile::fake()->createWithContent('cal.pdf', "%PDF-1.4\n%x\n"), 'Le Chef de la structure', '2026-11-01', ($this->compte)('chef_structure'));

    $this->ecue = MaquetteEcue::query()->where('code', 'MPMPP711')->sole();
    $quota = $programmations->definirQuota($this->periode, $this->ecue, ['heures_cm' => 30, 'heures_td' => 5], $this->agent);
    $programmations->validerQuota($quota, $this->responsable);
    $programmations->valider($programmations->programmer($quota, ['enseignant_id' => $this->prof->id, 'role' => 'responsable', 'heures_cm' => 6, 'heures_td' => 3,
        'modalite' => 'presentiel'], $this->agent), $this->responsable);
    $this->edt = app(EmploisDuTemps::class);
    $this->emploi = $this->edt->creer($this->periode, Parcours::query()->where('code', 'TC')->sole(), $this->agent);
    $this->matin = PlageHoraire::query()->where('code', 'MATIN')->sole();
    $this->edt->programmer($this->emploi, ['maquette_ecue_id' => $this->ecue->id, 'date_seance' => '2026-11-02', 'plage_horaire_id' => $this->matin->id,
        'enseignant_id' => $this->prof->id, 'modalite' => 'presentiel', 'nature' => 'CM'], $this->agent, '2026-11-30');   // 5 lundis, 08:00-11:00
    $this->publier = fn () => $this->emploi->forceFill(['statut' => EmploiDuTemps::PUBLIE])->save();
    $this->seances = Seance::query()->orderBy('date_seance')->get();
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 20)->setTime(10, 0));
    $this->dates = fn ($seances): array => collect($seances)->map(fn (Seance $s): string => $s->date_seance->toDateString())->all();
});

it('montre à l\'enseignant ses séances publiées, à venir et récentes, et rien à un autre', function (): void {
    // Brouillon : rien n'est montré.
    expect($this->espace->aVenir($this->prof))->toHaveCount(0)->and($this->espace->recentes($this->prof))->toHaveCount(0);

    ($this->publier)();
    $this->pointages->emarger($this->seances[0], $this->prof, ['arrivee' => '08:00', 'depart' => '11:00', 'nature' => 'CM', 'contenu_enseigne' => 'Chapitre 1 : le cadre budgétaire'], $this->prof);
    $recentes = $this->espace->recentes($this->prof);

    expect(($this->dates)($this->espace->aVenir($this->prof)))->toBe(['2026-11-23', '2026-11-30'])
        ->and(($this->dates)($recentes))->toBe(['2026-11-16', '2026-11-09', '2026-11-02'])
        ->and($recentes->map(fn (Seance $s): string => EspaceEnseignant::emargement($s))->all())->toBe(['a_emarger', 'a_emarger', PointageEnseignant::A_VALIDER])
        ->and($this->espace->aVenir($this->autre))->toHaveCount(0)
        ->and($this->espace->recentes($this->autre))->toHaveCount(0)
        ->and($this->espace->heures($this->autre))->toBe([]);

    // Une séance annulée reste affichée, avec son statut.
    $this->edt->annuler($this->seances[4]->refresh(), 'Mission de l\'enseignant à l\'étranger', $this->agent);
    expect($this->espace->aVenir($this->prof)->pluck('statut')->all())->toBe(['prevue', 'annulee']);

    $this->actingAs($this->prof)->get(MesCours::getUrl())->assertOk()
        ->assertSee('Lundi 23 novembre 2026')->assertSee('MPMPP711')->assertSee('Annulée')->assertSee('Mission de l\'enseignant')
        ->assertSee('Émargé, à valider')->assertSee('À émarger');
});

it('montre les surveillances d\'un planning publié, avec la salle du surveillant', function (): void {
    $salle = Salle::query()->create(['code' => 'B1', 'libelle' => 'Salle B1', 'capacite' => 40]);
    $plannings = app(PlanningsExamens::class);
    $p = $plannings->creer($this->examens, $this->agent);
    $plannings->enregistrer($p, ['code_ecue' => 'MPMPP711', 'date_epreuve' => '2027-02-09', 'plage_horaire_id' => $this->matin->id,
        'salles' => [['salle_id' => $salle->id, 'surveillants' => [$this->prof->id]]]], $this->agent);

    expect($this->espace->surveillances($this->prof))->toBe([]);

    $plannings->publier($p->refresh(), $this->agent);
    $s = $this->espace->surveillances($this->prof);

    expect($s)->toHaveCount(1)
        ->and($s[0]['epreuve']->code_ecue)->toBe('MPMPP711')
        ->and($s[0]['salle']?->code)->toBe('B1')
        ->and($this->espace->surveillances($this->autre))->toBe([]);

    $this->actingAs($this->prof)->get(MesCours::getUrl())->assertOk()->assertSee('Mardi 9 février 2027')->assertSee('Salle B1')->assertSee('Examens M1');
});

it('récapitule les heures par cours et ne montre la paie qu\'une fois l\'état arrêté', function (): void {
    ($this->publier)();
    $valider = function (Seance $s): void {
        $this->pointages->valider($this->pointages->emarger($s, $this->prof, ['arrivee' => '08:00', 'depart' => '11:00', 'nature' => 'CM',
            'contenu_enseigne' => 'Séance du '.$s->date_seance->format('d/m')], $this->agent), $this->responsable);
    };
    $valider($this->seances[0]);
    $valider($this->seances[1]);
    $this->pointages->emarger($this->seances[2], $this->prof, ['arrivee' => '08:00', 'depart' => '11:00', 'nature' => 'CM', 'contenu_enseigne' => 'Chapitre 3 : la dépense'], $this->prof);
    $dossier = $this->pointages->ouvrirDossier($this->prof, $this->agent);
    $this->pointages->majDossier($dossier, ['statut_paie' => 'vacataire', 'niu' => 'P000000012345X', 'banque' => 'Banque fictive',
        'compte_paiement' => '10000 00000 12345678 00'], $this->financier);

    $etats = app(EtatsPaie::class);
    $etat = $etats->creerMensuel('2026-11', $this->agent);
    $l = $this->espace->heures($this->prof);

    expect($l)->toHaveCount(1)
        ->and($l[0]['ecue']->code)->toBe('MPMPP711')
        ->and($l[0]['periode'])->toBe('Première période de cours')
        ->and([$l[0]['alloue'], $l[0]['programme'], $l[0]['valide'], $l[0]['a_valider'], $l[0]['en_paie'], $l[0]['rejetes']])->toBe([9.0, 15.0, 6.0, 3.0, 6.0, 0])
        ->and($this->espace->paie($this->prof))->toBe([]);   // état en brouillon : montants non arrêtés

    $etats->avancer($etat, EtatPaie::VERIFIE, $this->responsable);
    $paie = $this->espace->paie($this->prof);

    expect($paie)->toHaveCount(1)
        ->and($paie[0]['etat']->numero)->toBe('EP-2026-11')
        ->and($paie[0]['heures'])->toBe(6.0)
        ->and($paie[0]['net'])->toBe(93450)
        ->and($this->espace->paie($this->autre))->toBe([]);

    $this->actingAs($this->prof)->get(MesHeures::getUrl())->assertOk()
        ->assertSee('MPMPP711')->assertSee('EP-2026-11')->assertSee('Vérifié')->assertSee(LibelleSituation::fcfa(93450));
});

it('réserve les deux écrans à qui enseigne', function (): void {
    ($this->publier)();

    foreach ([MesCours::class, MesHeures::class] as $page) {
        $this->actingAs($this->prof)->get($page::getUrl())->assertOk();
        // La Scolarité gère les pointages mais n'enseigne pas : pas d'espace d'enseignant.
        $this->actingAs($this->agent)->get($page::getUrl())->assertForbidden();
        $this->actingAs($this->responsable)->get($page::getUrl())->assertForbidden();
        $this->actingAs($this->financier)->get($page::getUrl())->assertForbidden();
    }

    expect(EspaceEnseignant::concerne($this->prof))->toBeTrue()
        ->and(EspaceEnseignant::concerne($this->agent))->toBeFalse()
        ->and(EspaceEnseignant::concerne(null))->toBeFalse();
});
