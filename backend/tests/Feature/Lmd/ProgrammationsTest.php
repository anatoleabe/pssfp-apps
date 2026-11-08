<?php

declare(strict_types=1);

use App\Filament\Pages\Lmd\SuiviProgrammation;
use App\Filament\Resources\IndisponibiliteEnseignantResource;
use App\Filament\Resources\ProgrammationEnseignantResource;
use App\Filament\Resources\ProgrammationEnseignantResource\Pages\ListProgrammationsEnseignant;
use App\Filament\Resources\QuotaHoraireResource;
use App\Jobs\SendThrottledMail;
use App\Models\Acte;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\ModeleActe;
use App\Models\Parcours;
use App\Models\PlageHoraire;
use App\Models\ProgrammationEnseignant;
use App\Models\QuotaHoraire;
use App\Models\Seance;
use App\Models\User;
use App\Services\Lmd\Actes;
use App\Services\Lmd\Calendriers;
use App\Services\Lmd\ConflitsEmploiDuTemps;
use App\Services\Lmd\ControlesCalendrier;
use App\Services\Lmd\ControlesProgrammation;
use App\Services\Lmd\EmploisDuTemps;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\Programmations;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
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
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->prof = ($this->compte)('enseignant');
    $this->prof2 = ($this->compte)('enseignant');
    $this->service = app(Programmations::class);
    $this->edt = app(EmploisDuTemps::class);

    $calendriers = app(Calendriers::class);
    $this->c = $calendriers->creer('2026-2027', '2026-10-01', '2027-08-31', $this->agent);
    $ajouter = fn (string $type, string $libelle, ?string $niveau, string $debut, string $fin) => $calendriers->enregistrer($this->c,
        ['type' => $type, 'libelle' => $libelle, 'niveau' => $niveau, 'debut_prevu' => $debut, 'fin_prevue' => $fin], $this->agent);
    $this->periode1 = $ajouter('periode_cours', 'Première période de cours', null, '2026-11-02', '2026-12-19');
    $ajouter('vacances', 'Interruption de fin d\'année', null, '2026-12-20', '2027-01-04');
    $this->periode2 = $ajouter('periode_cours', 'Seconde période de cours', null, '2027-01-05', '2027-02-12');
    $calendriers->soumettre($this->c->refresh(), $this->agent);
    $calendriers->signer($this->c->refresh(), UploadedFile::fake()->createWithContent('cal.pdf', "%PDF-1.4\n%x\n"), 'Le Chef de la structure', '2026-11-01', ($this->compte)('chef_structure'));

    $this->tc = Parcours::query()->where('code', 'TC')->sole();
    $this->ecue = MaquetteEcue::query()->where('code', 'MPMPP711')->sole();      // 30 h CM + 5 h TD
    $this->matin = PlageHoraire::query()->where('code', 'MATIN')->sole();         // 3 h
    $this->quota = fn (array $h = ['heures_cm' => 25, 'heures_td' => 5]): QuotaHoraire => $this->service->definirQuota($this->periode1, $this->ecue, $h, $this->agent);
    $this->programmer = fn (QuotaHoraire $q, User $u, array $plus = []): ProgrammationEnseignant => $this->service->programmer($q,
        ['enseignant_id' => $u->id, 'role' => 'intervenant', 'heures_cm' => 10, 'modalite' => 'presentiel', ...$plus], $this->agent);
    $this->controles = fn (): array => collect(app(ControlesProgrammation::class)->pour($this->periode1->refresh()))->pluck('controle')->unique()->values()->all();
});

it('signale un quota mal réparti, refuse un second responsable et un enseignant programmé deux fois', function (): void {
    $q = ($this->quota)();
    ($this->programmer)($q, $this->prof, ['role' => 'responsable', 'heures_cm' => 20]);
    ($this->programmer)($q, $this->prof2, ['heures_cm' => 0, 'heures_td' => 6]);

    expect(($this->controles)())->toContain('repartition_quota')
        ->and(collect(app(ControlesProgrammation::class)->pour($this->periode1))->firstWhere('controle', 'repartition_quota')['detail'])
        ->toContain('quota 30 h, réparti 26 h')
        ->and(fn () => ($this->programmer)($q, ($this->compte)('enseignant'), ['role' => 'responsable']))->toThrow(InvalidArgumentException::class, 'déjà un responsable')
        ->and(fn () => ($this->programmer)($q, $this->prof))->toThrow(InvalidArgumentException::class, 'déjà programmé')
        ->and(fn () => ($this->programmer)($q, $this->agent))->toThrow(InvalidArgumentException::class, 'rôle d\'enseignant');
});

it('ne change un quota validé qu\'avec un motif, et le marque alors modifié', function (): void {
    $q = ($this->quota)();
    expect(fn () => $this->service->validerQuota($q, $this->agent))->toThrow(HttpException::class);
    $this->service->validerQuota($q, $this->responsable);

    expect(fn () => $this->service->definirQuota($this->periode1, $this->ecue, ['heures_cm' => 20], $this->agent))->toThrow(InvalidArgumentException::class, 'motif')
        ->and(fn () => $this->service->definirQuota($this->periode1, $this->ecue, ['heures_cm' => 2.3], $this->agent, 'Correction du volume'))->toThrow(InvalidArgumentException::class, 'demi-point');
    $this->service->definirQuota($this->periode1, $this->ecue, ['heures_cm' => 20, 'heures_td' => 2.5], $this->agent, 'Report de 7,5 h sur la seconde période');

    expect($q->refresh()->statut)->toBe(QuotaHoraire::MODIFIE)
        ->and($q->total())->toBe(22.5)
        ->and($q->motif_modification)->toBe('Report de 7,5 h sur la seconde période');
});

it('fait suivre le circuit proposée, validée, notifiée, acceptée ou déclinée, avec le courriel à l\'enseignant', function (): void {
    Bus::fake([SendThrottledMail::class]);
    $q = ($this->quota)();
    $p = ($this->programmer)($q, $this->prof, ['heures_cm' => 25, 'heures_td' => 5, 'role' => 'responsable']);
    $p2 = ($this->programmer)($q, $this->prof2, ['heures_cm' => 2]);

    expect(fn () => $this->service->valider($p, $this->responsable))->toThrow(InvalidArgumentException::class, 'quota');
    $this->service->validerQuota($q, $this->responsable);
    $this->service->valider($p, $this->responsable);
    $this->service->valider($p2, $this->responsable);
    $bilan = $this->service->notifier(ProgrammationEnseignant::query()->get(), $this->responsable);

    expect($bilan)->toBe(['notifiees' => 2, 'ignorees' => 0])
        ->and($p->refresh()->statut)->toBe(ProgrammationEnseignant::NOTIFIE);
    Bus::assertDispatchedTimes(SendThrottledMail::class, 2);

    $p2->refresh();
    expect(fn () => $this->service->repondre($p, true, null, $this->prof2))->toThrow(HttpException::class)
        ->and(fn () => $this->service->repondre($p2, false, '', $this->prof2))->toThrow(InvalidArgumentException::class, 'motif');
    $this->service->repondre($p, true, null, $this->prof);
    $this->service->repondre($p2, false, 'Mission à l\'étranger en novembre', $this->prof2);

    expect($p->refresh()->statut)->toBe(ProgrammationEnseignant::ACCEPTE)
        ->and($p2->refresh()->statut)->toBe(ProgrammationEnseignant::DECLINE)
        ->and(fn () => $this->service->modifier($p, ['enseignant_id' => $this->prof->id, 'heures_cm' => 20], $this->agent))->toThrow(InvalidArgumentException::class, 'annulez-la');
    // Une programmation déclinée se modifie et repasse à « proposée ».
    $this->service->modifier($p2, ['enseignant_id' => $this->prof2->id, 'heures_cm' => 1, 'modalite' => 'distanciel'], $this->agent);
    expect($p2->refresh()->statut)->toBe(ProgrammationEnseignant::PROPOSE)->and($p2->motif)->toBeNull();
});

it('rattache les séances à la programmation et calcule l\'avancement, repris dans le Gantt', function (): void {
    $q = ($this->quota)(['heures_cm' => 30]);
    $p = ($this->programmer)($q, $this->prof, ['heures_cm' => 30, 'role' => 'responsable']);
    $e = $this->edt->creer($this->periode1, $this->tc, $this->agent);
    $this->edt->programmer($e, ['maquette_ecue_id' => $this->ecue->id, 'date_seance' => '2026-11-02', 'plage_horaire_id' => $this->matin->id,
        'enseignant_id' => $this->prof->id, 'modalite' => 'presentiel', 'nature' => 'CM'], $this->agent, '2026-12-07');   // 6 lundis, 18 h
    $e->seances()->orderBy('date_seance')->limit(4)->get()->each(fn (Seance $s) => $s->forceFill(['statut' => 'tenue'])->save());

    expect($e->seances()->where('programmation_id', $p->id)->count())->toBe(6);
    $l = $this->service->quotasPeriode($this->periode1)[0];
    expect($l['reparti'])->toBe(30.0)->and($l['programme'])->toBe(18.0)->and($l['tenu'])->toBe(12.0)->and($l['avancement'])->toBe(40);

    $lignes = collect(app(ControlesCalendrier::class)->gantt($this->c->refresh()));
    $sous = $lignes->firstWhere('type', 'programmation');
    expect($sous['libelle'])->toBe($this->prof->name.' — MPMPP711')
        ->and($sous['avancement'])->toBe(40)
        ->and($sous['debut_prevu']->toDateString())->toBe('2026-11-02')
        ->and($lignes->search(fn (array $x): bool => ($x['type'] ?? null) === 'programmation'))
        ->toBe($lignes->search(fn (array $x): bool => $x['libelle'] === 'Première période de cours') + 1);

    // Annulée, la programmation libère ses séances.
    $this->service->annuler($p, 'Changement d\'enseignant', $this->agent);
    expect($e->seances()->whereNotNull('programmation_id')->count())->toBe(0);
});

it('bloque la publication d\'un emploi du temps pour une séance pendant une indisponibilité de l\'enseignant', function (): void {
    $q = ($this->quota)(['heures_cm' => 30]);
    ($this->programmer)($q, $this->prof, ['heures_cm' => 30]);
    expect(fn () => $this->service->declarerIndisponibilite($this->prof, '2026-11-16 08:00', '2026-11-16 10:00', 'Jury', $this->prof2))->toThrow(HttpException::class);
    $i = $this->service->declarerIndisponibilite($this->prof, '2026-11-16 09:00', '2026-11-17 12:00', 'Mission', $this->prof);
    expect($i->refresh()->libelle())->toBe('du 16/11/2026 09:00 au 17/11/2026 12:00');
    $e = $this->edt->creer($this->periode1, $this->tc, $this->agent);
    $this->edt->programmer($e, ['maquette_ecue_id' => $this->ecue->id, 'date_seance' => '2026-11-16', 'plage_horaire_id' => $this->matin->id,
        'enseignant_id' => $this->prof->id, 'modalite' => 'presentiel'], $this->agent);
    $this->edt->programmer($e, ['maquette_ecue_id' => $this->ecue->id, 'date_seance' => '2026-11-18', 'plage_horaire_id' => $this->matin->id,
        'enseignant_id' => $this->prof->id, 'modalite' => 'presentiel'], $this->agent);

    $conflits = collect(app(ConflitsEmploiDuTemps::class)->pour($e->refresh()));
    expect($conflits->where('controle', 'enseignant_indisponible')->count())->toBe(1)
        ->and($conflits->firstWhere('controle', 'enseignant_indisponible')['bloquant'])->toBeTrue()
        ->and(fn () => $this->edt->publier($e, $this->agent))->toThrow(InvalidArgumentException::class, 'Publication refusée')
        ->and(($this->controles)())->toContain('enseignant_indisponible');
});

it('déclenche les contrôles de charge, de fenêtre, de séance sans programmation, de notification et de maquette', function (): void {
    $this->travelTo('2026-11-10 10:00');
    $this->service->definirPlafond(20, $this->responsable);
    expect(fn () => $this->service->definirPlafond(20, $this->agent))->toThrow(HttpException::class);
    $q = ($this->quota)();                                           // 30 h sur 35 h à la maquette
    ($this->programmer)($q, $this->prof, ['heures_cm' => 25, 'heures_td' => 5, 'debut_prevu' => '2026-10-20', 'fin_prevue' => '2026-11-20']);
    $e = $this->edt->creer($this->periode1, $this->tc, $this->agent);
    $this->edt->programmer($e, ['maquette_ecue_id' => $this->ecue->id, 'date_seance' => '2026-11-30', 'plage_horaire_id' => $this->matin->id,
        'enseignant_id' => $this->prof->id, 'modalite' => 'presentiel'], $this->agent);
    $this->edt->programmer($e, ['maquette_ecue_id' => $this->ecue->id, 'date_seance' => '2026-12-01', 'plage_horaire_id' => $this->matin->id,
        'enseignant_id' => $this->prof2->id, 'modalite' => 'presentiel'], $this->agent);

    expect(($this->controles)())->toEqualCanonicalizing(['quota_annuel_maquette', 'charge_max_depassee', 'fenetre_hors_periode', 'non_notifiee',
        'seance_sans_programmation', 'seance_hors_fenetre'])
        ->and(collect(app(ConflitsEmploiDuTemps::class)->pour($e->refresh()))->where('controle', 'seance_sans_programmation')->count())->toBe(1);

    // La seconde période complète la maquette : le contrôle annuel s'éteint.
    $this->service->definirQuota($this->periode2, $this->ecue, ['heures_cm' => 5], $this->agent);
    expect(($this->controles)())->not->toContain('quota_annuel_maquette');
});

it('propose les quotas d\'une période : maquette moins les autres périodes', function (): void {
    $this->service->definirQuota($this->periode1, $this->ecue, ['heures_cm' => 20, 'heures_td' => 5], $this->agent);
    $b = $this->service->proposerQuotas($this->periode2, [$this->tc->id], [7], $this->agent);

    $semestre7 = MaquetteEcue::query()->whereHas('maquetteUe', fn ($q) => $q->where('semestre', 7)
        ->whereHas('maquette', fn ($m) => $m->where('parcours_id', $this->tc->id)))->count();
    $q = QuotaHoraire::query()->where('periode_id', $this->periode2->id)->where('maquette_ecue_id', $this->ecue->id)->sole();
    expect($b['creees'])->toBe($semestre7)
        ->and([$q->heures_cm, $q->heures_td, $q->heures_tp])->toBe([10.0, 0.0, 0.0])
        ->and($this->service->proposerQuotas($this->periode2, [$this->tc->id], [7], $this->agent)['creees'])->toBe(0);
});

it('reprend les programmations dans la note de programmation de la période', function (): void {
    $q = ($this->quota)();
    $p = ($this->programmer)($q, $this->prof, ['heures_cm' => 25, 'heures_td' => 5, 'debut_prevu' => '2026-11-02', 'fin_prevue' => '2026-11-27']);
    $acte = $this->periode1->actes()->whereHas('modele', fn ($m) => $m->where('code', 'PROGRAMMATION_ENSEIGNANTS'))->first()
        ?? tap(new Acte, fn ($a) => $a->forceFill(['modele_id' => ModeleActe::query()->where('code', 'PROGRAMMATION_ENSEIGNANTS')->value('id'),
            'evenement_id' => $this->periode1->id, 'objet' => 'Note de programmation', 'echeance' => '2026-10-18'])->save());

    expect(app(Actes::class)->texteInitial($acte->refresh()))->toContain('aucune programmation validée');
    $this->service->validerQuota($q, $this->responsable);
    $this->service->valider($p, $this->responsable);
    expect(app(Actes::class)->texteInitial($acte->refresh()))->toContain($this->prof->name.' :')
        ->toContain('- MPMPP711')->toContain('30 h, du 02/11/2026 au 27/11/2026');
});

it('ouvre les écrans à la scolarité, « Mes programmations » à l\'enseignant pour les siennes seulement, rien aux autres', function (): void {
    Bus::fake([SendThrottledMail::class]);
    $q = ($this->quota)();
    $this->service->validerQuota($q, $this->responsable);
    $p = ($this->programmer)($q, $this->prof, ['heures_cm' => 25, 'heures_td' => 5]);
    $autre = ($this->programmer)(($this->service->definirQuota($this->periode1, MaquetteEcue::query()->where('code', 'MPMPP712')->sole(), ['heures_cm' => 10], $this->agent)), $this->prof2);
    $this->service->valider($p, $this->responsable);
    $this->service->notifier([$p->refresh()], $this->responsable);

    $this->actingAs($this->agent)->get(QuotaHoraireResource::getUrl('index'))->assertOk()->assertSee('MPMPP711')->assertSee('MPMPP712');
    $this->actingAs($this->agent)->get(QuotaHoraireResource::getUrl('view', ['record' => $q]))->assertOk()->assertSee('Consommation')->assertSee('Volume de la maquette (année)')->assertSee('CM 30 · TD 5 · TP 0 = 35 h');
    $this->actingAs($this->agent)->get(SuiviProgrammation::getUrl(['periode' => $this->periode1->id]))->assertOk()->assertSee('Charge des enseignants')->assertSee($this->prof2->name);
    $this->actingAs($this->agent)->get(ProgrammationEnseignantResource::getUrl('index'))->assertOk()->assertSee($this->prof->name)->assertSee($this->prof2->name);

    $this->actingAs($this->prof)->get(ProgrammationEnseignantResource::getUrl('index'))->assertOk()->assertSee('Mes programmations')->assertSee('MPMPP711')->assertDontSee('MPMPP712');
    $this->actingAs($this->prof)->get(IndisponibiliteEnseignantResource::getUrl('index'))->assertOk()->assertSee('Mes indisponibilités');
    $this->actingAs($this->prof)->get(QuotaHoraireResource::getUrl('index'))->assertForbidden();
    $this->actingAs($this->prof)->get(SuiviProgrammation::getUrl())->assertForbidden();
    $this->actingAs(($this->compte)('librarian'))->get(ProgrammationEnseignantResource::getUrl('index'))->assertForbidden();

    Livewire::actingAs($this->prof)->test(ListProgrammationsEnseignant::class)
        ->assertTableActionVisible('accepter', $p)
        ->assertCanNotSeeTableRecords([$autre])
        ->callTableAction('accepter', $p);
    expect($p->refresh()->statut)->toBe(ProgrammationEnseignant::ACCEPTE);
});
