<?php

declare(strict_types=1);

use App\Jobs\NotifierPlanning;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CandidatureRelance;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\Parcours;
use App\Models\PlageHoraire;
use App\Models\Salle;
use App\Models\User;
use App\Services\Lmd\Calendriers;
use App\Services\Lmd\EmploisDuTemps;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\NotificationsPlanning;
use App\Services\Lmd\PlanningsExamens;
use App\Services\NotificationCandidatsService;
use App\Services\Sms\SmsServiceInterface;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

beforeEach(function (): void {
    Storage::fake(CandidatureObjectStorage::DISK);
    Queue::fake([NotifierPlanning::class]);
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    Maquette::query()->update(['statut' => Maquette::VALIDEE, 'validee_at' => now()]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');

    $calendriers = app(Calendriers::class);
    $c = $calendriers->creer('2026-2027', '2026-10-01', '2027-08-31', $this->agent);
    $ajouter = fn (string $type, string $libelle, string $debut, string $fin, ?string $session = null) => $calendriers->enregistrer($c,
        ['type' => $type, 'libelle' => $libelle, 'niveau' => 'M1', 'session' => $session, 'debut_prevu' => $debut, 'fin_prevue' => $fin], $this->agent);
    $this->periode = $ajouter('periode_cours', 'Première période de cours', '2026-11-02', '2026-12-19');
    $this->examens = $ajouter('examens', 'Examens M1', '2027-02-08', '2027-02-12', 'normale');
    $calendriers->soumettre($c->refresh(), $this->agent);
    $calendriers->signer($c->refresh(), UploadedFile::fake()->createWithContent('cal.pdf', "%PDF-1.4\n%x\n"), 'Le Chef de la structure', '2026-11-01', ($this->compte)('chef_structure'));

    $this->tc = Parcours::query()->where('code', 'TC')->sole();
    $this->ecue = MaquetteEcue::query()->where('code', 'MPMPP711')->sole();
    $this->matin = PlageHoraire::query()->where('code', 'MATIN')->sole();
    $this->salle = Salle::query()->create(['code' => 'A1', 'libelle' => 'Amphi A1', 'capacite' => 120]);
    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->inscrire = function (?string $statut = null) use ($bareme): InscriptionAnnuelle {
        $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory()->campagneCommune(), 'bareme_id' => $bareme->id, 'niveau' => 'M1', 'annee_academique' => '2026-2027']);
        $i->forceFill(['parcours_id' => $this->tc->id])->save();
        app(InscriptionsPedagogiques::class)->inscrire($i->refresh());
        if ($statut !== null) {
            $i->auditeur->forceFill(['statut' => $statut])->save();
        }

        return $i;
    };
    $this->a = ($this->inscrire)();
    $this->b = ($this->inscrire)();
    ($this->inscrire)(Auditeur::STATUT_ABANDON);
    $this->attendus = collect([$this->a, $this->b])->map(fn (InscriptionAnnuelle $i): int => $i->auditeur->candidature_id)->sort()->values()->all();
    $this->edt = app(EmploisDuTemps::class);
    $this->plannings = app(PlanningsExamens::class);
    $this->seance = ['maquette_ecue_id' => $this->ecue->id, 'date_seance' => '2026-11-16', 'plage_horaire_id' => $this->matin->id, 'modalite' => 'presentiel'];
    $this->envoye = fn (string $fragment, bool $sms = false) => Queue::assertPushed(NotifierPlanning::class,
        fn (NotifierPlanning $j): bool => str_contains($j->texte, $fragment) && collect($j->candidatures)->sort()->values()->all() === $this->attendus
            && $j->email && $j->sms === $sms && str_ends_with($j->texte, '/dossier/emploi-du-temps'));
});

it('prévient les auditeurs du parcours à la publication, au report et à l\'annulation, pas pour un brouillon', function (): void {
    $e = $this->edt->creer($this->periode, $this->tc, $this->agent);
    $this->edt->programmer($e, $this->seance, $this->agent, '2026-11-30');
    [$s1, $s2, $s3] = $e->seances()->orderBy('date_seance')->get()->all();
    $this->edt->reporter($s1, 'Enseignant en mission', ['date_seance' => '2026-11-18'], $this->agent);
    Queue::assertNothingPushed();

    $this->edt->publier($e->refresh(), $this->agent);
    ($this->envoye)('emploi du temps de votre parcours pour « Première période de cours » est publié');

    $this->edt->reporter($s2->refresh(), 'Enseignant en mission', ['date_seance' => '2026-11-25'], $this->agent);
    ($this->envoye)('la séance MPMPP711 du 23/11/2026 est reportée au 25/11/2026 de 08:00 à 11:00');
    $this->edt->annuler($s3->refresh(), 'Salle indisponible', $this->agent);
    ($this->envoye)('la séance MPMPP711 du 30/11/2026 à 08:00 est annulée');
    Queue::assertPushed(NotifierPlanning::class, 3);
});

it('prévient les candidats à la publication du planning et quand une épreuve change de date', function (): void {
    $p = $this->plannings->creer($this->examens, $this->agent);
    $lieu = [['salle_id' => $this->salle->id]];
    $epreuve = $this->plannings->enregistrer($p, ['code_ecue' => 'MPMPP711', 'date_epreuve' => '2027-02-09', 'plage_horaire_id' => $this->matin->id, 'salles' => $lieu], $this->agent);
    $this->plannings->enregistrer($p, ['code_ecue' => 'MPMPP711', 'date_epreuve' => '2027-02-10', 'plage_horaire_id' => $this->matin->id, 'salles' => $lieu], $this->agent, $epreuve);
    Queue::assertNothingPushed();

    NotificationsPlanning::regler(true, true, ($this->compte)('admin'));
    $this->plannings->publier($p, $this->agent);
    ($this->envoye)('le planning « Examens M1 » est publié', sms: true);

    // Même date, mêmes heures, autre salle : personne n'est dérangé.
    $autre = Salle::query()->create(['code' => 'B1', 'libelle' => 'Salle B1', 'capacite' => 50]);
    $this->plannings->enregistrer($p->refresh(), ['code_ecue' => 'MPMPP711', 'date_epreuve' => '2027-02-10', 'plage_horaire_id' => $this->matin->id, 'salles' => [['salle_id' => $autre->id]]], $this->agent, $epreuve->refresh());
    Queue::assertPushed(NotifierPlanning::class, 1);
    $this->plannings->enregistrer($p, ['code_ecue' => 'MPMPP711', 'date_epreuve' => '2027-02-11', 'debut' => '14:00', 'fin' => '16:00', 'salles' => $lieu], $this->agent, $epreuve->refresh());
    ($this->envoye)('l\'épreuve MPMPP711 aura lieu le 11/02/2027 de 14:00 à 16:00', sms: true);
});

it('n\'envoie rien quand les deux canaux sont coupés, et réserve le réglage aux administrateurs', function (): void {
    expect(NotificationsPlanning::reglages())->toBe(['email' => true, 'sms' => false])
        ->and(fn () => NotificationsPlanning::regler(false, false, $this->agent))->toThrow(HttpException::class);
    NotificationsPlanning::regler(false, false, ($this->compte)('admin'));
    expect(Activity::query()->where('event', 'notifications_planning_reglees')->count())->toBe(1);

    $e = $this->edt->creer($this->periode, $this->tc, $this->agent);
    $this->edt->programmer($e, $this->seance, $this->agent);
    $this->edt->publier($e->refresh(), $this->agent);
    Queue::assertNothingPushed();
});

it('trace chaque message avec la cause « planning » et n\'envoie de SMS que s\'il est demandé', function (): void {
    $this->mock(SmsServiceInterface::class, fn ($mock) => $mock->shouldNotReceive('send'));
    $this->a->auditeur->candidature->forceFill(['email' => 'auditeur-essai@pssfp.local'])->save();

    (new NotifierPlanning([$this->a->auditeur->candidature_id], 'PSSFP — Emploi du temps publié', 'PSSFP : essai', true, false))->handle(app(NotificationCandidatsService::class));

    $traces = CandidatureRelance::query()->where('candidature_id', $this->a->auditeur->candidature_id)->where('cause', CandidatureRelance::CAUSE_PLANNING)->get();
    expect($traces)->toHaveCount(1)
        ->and($traces[0]->canal)->toBe(CandidatureRelance::CANAL_EMAIL)
        ->and(CandidatureRelance::CAUSES_AFFICHEES)->toContain(CandidatureRelance::CAUSE_PLANNING);
});
