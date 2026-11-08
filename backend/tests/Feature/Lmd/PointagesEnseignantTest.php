<?php

declare(strict_types=1);

use App\Filament\Pages\Lmd\SuiviHeuresEnseignees;
use App\Filament\Resources\DossierPaieEnseignantResource;
use App\Filament\Resources\DossierPaieEnseignantResource\Pages\ListDossiersPaieEnseignant;
use App\Filament\Resources\PointageEnseignantResource;
use App\Filament\Resources\PointageEnseignantResource\Pages\ListPointagesEnseignant;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\EmploiDuTemps;
use App\Models\EtatPaie;
use App\Models\InscriptionAnnuelle;
use App\Models\LigneEtatPaie;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\Parcours;
use App\Models\PlageHoraire;
use App\Models\Pointage;
use App\Models\PointageEnseignant;
use App\Models\Seance;
use App\Models\User;
use App\Services\Lmd\Calendriers;
use App\Services\Lmd\ControlesPointage;
use App\Services\Lmd\EmploisDuTemps;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\PointagesEnseignant;
use App\Services\Lmd\Programmations;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
    $this->service = app(PointagesEnseignant::class);
    $programmations = app(Programmations::class);

    $calendriers = app(Calendriers::class);
    $c = $calendriers->creer('2026-2027', '2026-10-01', '2027-08-31', $this->agent);
    $this->periode = $calendriers->enregistrer($c, ['type' => 'periode_cours', 'libelle' => 'Première période de cours', 'niveau' => null,
        'debut_prevu' => '2026-11-02', 'fin_prevue' => '2026-12-19'], $this->agent);
    $calendriers->soumettre($c->refresh(), $this->agent);
    $calendriers->signer($c->refresh(), UploadedFile::fake()->createWithContent('cal.pdf', "%PDF-1.4\n%x\n"), 'Le Chef de la structure', '2026-11-01', ($this->compte)('chef_structure'));

    $this->ecue = MaquetteEcue::query()->where('code', 'MPMPP711')->sole();      // 30 h CM + 5 h TD
    $this->quota = $programmations->definirQuota($this->periode, $this->ecue, ['heures_cm' => 30, 'heures_td' => 5], $this->agent);
    $this->prog = $programmations->programmer($this->quota, ['enseignant_id' => $this->prof->id, 'role' => 'responsable', 'heures_cm' => 6, 'heures_td' => 3, 'modalite' => 'presentiel'], $this->agent);
    $edt = app(EmploisDuTemps::class);
    $e = $edt->creer($this->periode, Parcours::query()->where('code', 'TC')->sole(), $this->agent);
    $edt->programmer($e, ['maquette_ecue_id' => $this->ecue->id, 'date_seance' => '2026-11-02', 'plage_horaire_id' => PlageHoraire::query()->where('code', 'MATIN')->sole()->id,
        'enseignant_id' => $this->prof->id, 'modalite' => 'presentiel', 'nature' => 'CM'], $this->agent, '2026-11-30');   // 5 lundis, 08:00-11:00
    $e->forceFill(['statut' => EmploiDuTemps::PUBLIE])->save();
    $this->edt = $e;
    // Remplaçant : programmation validée sur le cours, pour la période.
    $this->remplacant = function (User $u) use ($programmations): void {
        $programmations->validerQuota($this->quota->refresh(), $this->responsable);
        $programmations->valider($programmations->programmer($this->quota, ['enseignant_id' => $u->id, 'heures_cm' => 3, 'modalite' => 'presentiel'], $this->agent), $this->responsable);
    };
    $this->seances = Seance::query()->orderBy('date_seance')->get();
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 20)->setTime(10, 0));
    $this->emarger = fn (Seance $s, string $arrivee = '08:00', string $depart = '11:00', ?User $u = null, string $nature = 'CM'): PointageEnseignant => $this->service->emarger($s,
        $u ?? $this->prof, ['arrivee' => $arrivee, 'depart' => $depart, 'nature' => $nature, 'contenu_enseigne' => 'Chapitre 1 : le cadre budgétaire'], $u ?? $this->prof);
    $this->controles = fn (): array => collect(app(ControlesPointage::class)->pour($this->periode->refresh()))->pluck('controle')->unique()->values()->all();
});

it('laisse l\'enseignant pointer ses séances passées, une seule fois, et rien d\'autre', function (): void {
    expect($this->service->seancesAEmarger($this->prof)->pluck('id')->all())->toBe($this->seances->take(3)->pluck('id')->reverse()->values()->all())
        ->and($this->service->seancesAEmarger($this->prof2))->toHaveCount(0);

    // L'enseignant pointe la nature de la séance (CM), quoi qu'il envoie.
    $p = ($this->emarger)($this->seances[0], '08:20', '11:00', null, 'TD');

    expect($p->statut)->toBe(PointageEnseignant::A_VALIDER)
        ->and($p->mode)->toBe('signature')
        ->and($p->nature)->toBe('CM')
        ->and($p->remplace_enseignant_id)->toBeNull()
        ->and($p->refresh()->horaires())->toBe('08:20 – 11:00')
        ->and($this->service->seancesAEmarger($this->prof))->toHaveCount(2)
        ->and(fn () => ($this->emarger)($this->seances[0]))->toThrow(InvalidArgumentException::class, 'déjà pointée')
        ->and(fn () => ($this->emarger)($this->seances[3]))->toThrow(InvalidArgumentException::class, 'passée ou du jour')
        ->and(fn () => ($this->emarger)($this->seances[1], '08:00', '11:00', $this->prof2))->toThrow(InvalidArgumentException::class, 'ni l\'enseignant')
        ->and(fn () => ($this->emarger)($this->seances[1], '11:00', '09:00'))->toThrow(InvalidArgumentException::class, 'départ suit')
        ->and(fn () => $this->service->emarger($this->seances[1], $this->prof, ['arrivee' => '08:00', 'depart' => '11:00'], $this->prof2))->toThrow(HttpException::class)
        ->and(fn () => $this->service->emarger($this->seances[1], $this->agent, ['arrivee' => '08:00', 'depart' => '11:00'], $this->agent))->toThrow(InvalidArgumentException::class, 'rôle d\'enseignant');

    // Une programmation seulement proposée n'autorise pas le remplacement.
    app(Programmations::class)->programmer($this->quota, ['enseignant_id' => $this->prof2->id, 'heures_cm' => 3, 'modalite' => 'presentiel'], $this->agent);
    expect($this->service->seancesAEmarger($this->prof2))->toHaveCount(0)
        ->and(fn () => ($this->emarger)($this->seances[1], '08:00', '11:00', $this->prof2))->toThrow(InvalidArgumentException::class, 'ni l\'enseignant');

    // Emploi du temps en brouillon : rien à pointer.
    $this->edt->forceFill(['statut' => EmploiDuTemps::BROUILLON])->save();
    expect($this->service->seancesAEmarger($this->prof))->toHaveCount(0)
        ->and(fn () => ($this->emarger)($this->seances[1]))->toThrow(InvalidArgumentException::class, 'emploi du temps publié');
});

it('valide 2,5 h pour 20 min de retard, plafonne à la séance et passe la séance à « tenue »', function (): void {
    $retard = ($this->emarger)($this->seances[0], '08:20', '11:00');
    $tardif = ($this->emarger)($this->seances[1], '07:50', '11:40');

    expect(fn () => $this->service->valider($retard, $this->prof))->toThrow(HttpException::class)
        ->and(PointageEnseignantResource::descriptionValidation($retard))->toStartWith('2,5 h de CM seront validées');
    $this->service->valider($retard, $this->agent);
    $this->service->valider($tardif, $this->agent);

    expect($retard->refresh()->statut)->toBe(PointageEnseignant::VALIDE)
        ->and($retard->minutes_payables)->toBe(150)
        ->and($retard->heures())->toBe(2.5)
        ->and($tardif->refresh()->minutes_payables)->toBe(180)
        ->and($this->seances[0]->refresh()->statut)->toBe('tenue')
        ->and(fn () => $this->service->valider($retard, $this->agent))->toThrow(InvalidArgumentException::class, 'à valider');
    // L'avancement de la programmation (lot 12d) compte la séance tenue.
    expect(app(Programmations::class)->consommation(collect([$this->prog->refresh()]))[$this->prog->id]['tenu'])->toBe(6.0);
});

it('exige le contenu enseigné pour valider et un motif pour rejeter ; un rejet se corrige', function (): void {
    $p = $this->service->emarger($this->seances[0], $this->prof, ['arrivee' => '08:00', 'depart' => '11:00', 'contenu_enseigne' => '  '], $this->prof);

    expect(fn () => $this->service->valider($p, $this->agent))->toThrow(InvalidArgumentException::class, 'contenu enseigné')
        ->and(fn () => $this->service->rejeter($p, ' ', $this->agent))->toThrow(InvalidArgumentException::class, 'motif');
    $this->service->rejeter($p, 'Cahier de textes non rempli', $this->agent);
    expect($p->refresh()->statut)->toBe(PointageEnseignant::REJETE);

    $this->service->modifier($p, ['arrivee' => '08:00', 'depart' => '11:00', 'contenu_enseigne' => 'Chapitre 2 : la loi de finances'], $this->prof);
    expect($p->refresh()->statut)->toBe(PointageEnseignant::A_VALIDER)->and($p->motif_rejet)->toBeNull();
    $this->service->valider($p, $this->agent);
    expect(fn () => $this->service->modifier($p->refresh(), ['arrivee' => '08:00', 'depart' => '10:00'], $this->prof))->toThrow(InvalidArgumentException::class, 'seule la Scolarité');

    // La Scolarité modifie un pointage validé : il repasse à valider, en saisie à son nom ; la séance n'est plus tenue.
    $this->service->modifier($p, ['arrivee' => '08:00', 'depart' => '10:00', 'contenu_enseigne' => 'Chapitre 2'], $this->agent);
    expect($p->refresh()->statut)->toBe(PointageEnseignant::A_VALIDER)
        ->and($p->minutes_payables)->toBeNull()
        ->and($p->mode)->toBe('saisie')
        ->and($p->saisi_par)->toBe($this->agent->id)
        ->and($this->seances[0]->refresh()->statut)->toBe('prevue');
    // L'agent valide ce qu'il a saisi.
    $this->service->valider($p, $this->agent);
    expect($p->refresh()->minutes_payables)->toBe(120);
});

it('trace le remplacement, ne valide qu\'un pointage par séance, ni ses propres heures', function (): void {
    ($this->remplacant)($this->prof2);
    $r = ($this->emarger)($this->seances[1], '08:00', '11:00', $this->prof2);
    expect($r->remplace_enseignant_id)->toBe($this->prof->id)
        ->and(fn () => ($this->emarger)($this->seances[1]))->toThrow(InvalidArgumentException::class, 'autre enseignant')
        ->and(($this->controles)())->not->toContain('remplacant_non_programme');

    // La Scolarité peut saisir un second pointage : il est signalé, et ne se valide pas si le premier l'est.
    $titulaire = $this->service->emarger($this->seances[1], $this->prof, ['arrivee' => '08:00', 'depart' => '11:00', 'contenu_enseigne' => 'Chapitre 2'], $this->agent);
    expect(($this->controles)())->toContain('seance_plusieurs_enseignants');
    $this->service->valider($r, $this->agent);
    expect(fn () => $this->service->valider($titulaire, $this->responsable))->toThrow(InvalidArgumentException::class, 'déjà un pointage validé');

    // Un compte à la fois enseignant et agent ne valide pas ses propres heures.
    $double = tap(($this->compte)('enseignant'), fn (User $u) => $u->assignRole('scolarite_agent'));
    $propre = $this->service->emarger($this->seances[0], $double, ['arrivee' => '08:00', 'depart' => '11:00', 'contenu_enseigne' => 'Chapitre 1'], $this->agent);
    expect(fn () => $this->service->valider($propre, $double->fresh()))->toThrow(InvalidArgumentException::class, 'vos propres heures');

    // Remplaçant non programmé, saisi par la Scolarité (jamais en mode « signature »).
    $prof3 = ($this->compte)('enseignant');
    expect(fn () => $this->service->emarger($this->seances[2], $prof3, ['arrivee' => '08:00', 'depart' => '11:00', 'mode' => 'signature'], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'Mode');
    $q = $this->service->emarger($this->seances[2], $prof3, ['arrivee' => '08:00', 'depart' => '11:00', 'mode' => 'badge', 'confirme_delegue' => true], $this->agent);
    expect($q->mode)->toBe('badge')->and($q->confirme_delegue)->toBeTrue()
        ->and(($this->controles)())->toContain('remplacant_non_programme');
});

it('ne modifie plus un pointage payé', function (): void {
    $p = ($this->emarger)($this->seances[0]);
    $this->service->valider($p, $this->agent);
    // Ligne d'un état de paie (lot 13b) : le pointage est payé.
    $etat = (new EtatPaie)->forceFill(['numero' => 'EP-2026-11', 'periode_du' => '2026-11-01', 'periode_au' => '2026-11-30', 'objet' => 'Essai']);
    $etat->save();
    $ligne = (new LigneEtatPaie)->forceFill(['etat_id' => $etat->id, 'enseignant_id' => $this->prof->id, 'maquette_ecue_id' => $this->ecue->id, 'annee_academique' => '2026-2027', 'nature' => 'CM',
        'quantite' => 3, 'montant_unitaire' => 17500, 'taux_retenue' => 11, 'montant_brut' => 52500, 'montant_retenue' => 5775, 'montant_net' => 46725]);
    $ligne->save();
    $p->refresh()->forceFill(['ligne_paie_id' => $ligne->id])->save();

    expect(fn () => $this->service->modifier($p->refresh(), ['arrivee' => '08:00', 'depart' => '10:00', 'contenu_enseigne' => 'x'], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'payé')
        ->and($this->agent->can('update', $p))->toBeFalse();
});

it('déclenche les contrôles du pointage', function (): void {
    // Auditeur pointé présent à la séance 3, sans pointage de l'enseignant.
    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory()->campagneCommune(), 'bareme_id' => $bareme->id]);
    app(InscriptionsPedagogiques::class)->inscrire($inscription);
    $ip = $inscription->inscriptionsPedagogiques()->where('maquette_ue_id', $this->ecue->maquette_ue_id)->firstOrFail();
    (new Pointage)->forceFill(['seance_id' => $this->seances[2]->id, 'inscription_pedagogique_id' => $ip->id, 'statut' => 'present', 'mode' => 'manuel'])->save();

    // Séance 1 validée (3 h de CM sur 6 allouées) ; séance 2 à valider depuis 11 jours (délai : 3) ;
    // un cours d'un autre ECUE, fait par l'enseignant sans programmation, qui chevauche la séance 1.
    $a = ($this->emarger)($this->seances[0]);
    $this->service->valider($a, $this->agent);
    ($this->emarger)($this->seances[1]);
    $this->service->definirParametres(15, 3, $this->responsable);
    $autre = $this->seances[0]->replicate(['uuid'])->forceFill(['maquette_ecue_id' => MaquetteEcue::query()->where('code', '<>', $this->ecue->code)->firstOrFail()->id, 'enseignant_id' => $this->prof2->id]);
    $autre->save();
    $this->service->emarger($autre, $this->prof, ['arrivee' => '09:00', 'depart' => '10:00', 'contenu_enseigne' => 'Remplacement'], $this->agent);

    expect(($this->controles)())->toContain('seance_sans_pointage', 'auditeurs_sans_enseignant', 'pointage_en_attente', 'double_emargement', 'dossier_paie_incomplet', 'remplacant_non_programme')
        ->and(($this->controles)())->not->toContain('heures_hors_allocation');

    // 4 h de TD validées pour 3 h allouées : au-delà de l'allocation, par nature (saisie de la Scolarité, validée par le responsable).
    $td = $this->service->emarger($this->seances[2], $this->prof, ['arrivee' => '08:00', 'depart' => '11:00', 'nature' => 'TD', 'contenu_enseigne' => 'TD 1'], $this->agent);
    DB::table('seances')->where('id', $this->seances[2]->id)->update(['debut' => '07:00']);
    $this->service->modifier($td, ['arrivee' => '07:00', 'depart' => '11:00', 'nature' => 'TD', 'contenu_enseigne' => 'TD 1'], $this->agent);
    $this->service->valider($td->refresh(), $this->responsable);
    expect(($this->controles)())->toContain('heures_hors_allocation')
        ->and(collect(app(ControlesPointage::class)->pour($this->periode))->firstWhere('controle', 'heures_hors_allocation')['detail'])->toContain('TD 4 h validées pour 3 h allouées');
    $ligne = collect($this->service->bilan($this->periode))->first(fn (array $l): bool => $l['ecue']->id === $this->ecue->id);
    expect($ligne['pointe'])->toBe(10.0)->and($ligne['a_valider'])->toBe(3.0);

    // Le dossier complet fait tomber le contrôle 7.
    $d = $this->service->ouvrirDossier($this->prof, $this->agent);
    expect(($this->controles)())->toContain('dossier_paie_incomplet');
    $this->service->majDossier($d, ['statut_paie' => 'vacataire', 'niu' => 'p012345678901a', 'banque' => 'Banque fictive', 'compte_paiement' => '10005 00001 0123456789 12'], ($this->compte)('service_financier'));
    expect(($this->controles)())->not->toContain('dossier_paie_incomplet');
});

it('montre au bilan l\'enseignant programmé qui n\'a rien pointé', function (): void {
    $bilan = $this->service->bilan($this->periode);

    expect($bilan)->toHaveCount(1)
        ->and($bilan[0]['enseignant']->id)->toBe($this->prof->id)
        ->and($bilan[0]['alloue'])->toBe(['CM' => 6.0, 'TD' => 3.0, 'TP' => 0.0])
        ->and($bilan[0]['pointe'])->toBe(0.0);
});

it('chiffre le NIU et le compte, les garde hors du journal et les réserve au service financier', function (): void {
    $financier = ($this->compte)('service_financier');
    $d = $this->service->ouvrirDossier($this->prof, $this->agent);
    $this->service->majDossier($d, ['statut_paie' => 'permanent', 'service_du_heures' => 6, 'niu' => 'P012345678901A', 'compte_paiement' => '10005 00001 0123456789 12'], $this->agent);
    expect($d->refresh()->niu)->toBeNull()->and($d->service_du_heures)->toBe(6.0);

    $this->service->majDossier($d, ['statut_paie' => 'permanent', 'service_du_heures' => 6, 'niu' => 'p012345678901a', 'banque' => 'Banque fictive', 'compte_paiement' => '10005 00001 0123456789 12'], $financier);
    $brut = DB::table('dossiers_paie_enseignant')->where('id', $d->id)->first();

    expect($d->refresh()->niu)->toBe('P012345678901A')
        ->and($d->estComplet())->toBeTrue()
        ->and($brut->niu)->not->toContain('P012345678901A')
        ->and($brut->compte_paiement)->not->toContain('0123456789')
        ->and(DB::table('activity_log')->where('log_name', 'lmd')->count())->toBeGreaterThan(0)
        ->and(json_encode(DB::table('activity_log')->pluck('properties')))->not->toContain('0123456789')->not->toContain('P0123')->not->toContain('Banque fictive')
        ->and($d->toArray())->not->toHaveKey('niu')
        ->and(fn () => $this->service->majDossier($d, ['statut_paie' => 'vacataire', 'niu' => 'abc'], $financier))->toThrow(InvalidArgumentException::class, 'NIU')
        ->and(fn () => $this->service->ouvrirDossier($this->prof, $this->agent))->toThrow(InvalidArgumentException::class, 'déjà')
        ->and(fn () => $this->service->ouvrirDossier($this->prof, $this->prof2))->toThrow(HttpException::class);

    // Écran : l'agent ne reçoit pas les coordonnées ; le service financier, si.
    $this->actingAs($this->agent);
    $vue = Livewire::test(ListDossiersPaieEnseignant::class)->assertCanSeeTableRecords([$d])->assertSee('Complet')->assertDontSee('Banque fictive')
        ->filterTable('complet', true)->assertCanSeeTableRecords([$d])
        ->mountTableAction('modifier', $d)->assertTableActionDataSet(['statut_paie' => 'permanent']);
    $donnees = $vue->instance()->mountedTableActionsData[0];
    expect($donnees['niu'] ?? null)->toBeNull()->and($donnees['compte_paiement'] ?? null)->toBeNull()
        ->and(json_encode($donnees))->not->toContain('0123456789');
    $this->actingAs($financier);
    Livewire::test(ListDossiersPaieEnseignant::class)->assertSee('Banque fictive')
        ->mountTableAction('modifier', $d)->assertTableActionDataSet(['niu' => 'P012345678901A']);
});

it('ouvre les écrans par rôle : l\'enseignant ne voit que ses émargements', function (): void {
    ($this->remplacant)($this->prof2);
    $p = ($this->emarger)($this->seances[0]);
    $autre = ($this->emarger)($this->seances[1], '08:00', '11:00', $this->prof2);

    $this->actingAs($this->agent);
    $this->get(PointageEnseignantResource::getUrl('index'))->assertOk()->assertSee('Pointages des enseignants');
    $this->get(DossierPaieEnseignantResource::getUrl('index'))->assertOk();
    $this->get(SuiviHeuresEnseignees::getUrl(['periode' => $this->periode->id]))->assertOk()->assertSee('Heures par enseignant et par cours')->assertSee('MPMPP711');
    Livewire::test(ListPointagesEnseignant::class)
        ->callTableAction('rejeter', $autre, ['motif' => 'non'])->assertHasTableActionErrors(['motif'])
        ->callTableBulkAction('valider', [$p, $autre])
        // Saisie par la Scolarité : séance retrouvée par la date ; l'agent peut valider sa saisie.
        ->callAction('saisir', ['enseignant_id' => (string) $this->prof2->id, 'seance_id' => (string) $this->seances[0]->id, 'arrivee' => '08:00', 'depart' => '11:00',
            'nature' => 'CM', 'mode' => 'saisie', 'contenu_enseigne' => 'Co-animation'])
        ->assertHasNoActionErrors();
    $saisi = PointageEnseignant::query()->where('seance_id', $this->seances[0]->id)->where('enseignant_id', $this->prof2->id)->sole();
    expect($p->refresh()->statut)->toBe(PointageEnseignant::VALIDE)
        ->and($autre->refresh()->statut)->toBe(PointageEnseignant::VALIDE)
        ->and($saisi->mode)->toBe('saisie')
        ->and($this->service->seancesPointablesPour($this->prof2, '16/11')->pluck('id')->all())->toBe([$this->seances[2]->id])
        ->and($this->service->seancesPointablesPour($this->prof2, 'mpmpp7')->pluck('id')->all())->toContain($this->seances[2]->id);
    Livewire::test(ListPointagesEnseignant::class)->assertTableActionVisible('valider', $saisi);

    $this->actingAs($this->prof);
    $this->get(PointageEnseignantResource::getUrl('index'))->assertOk()->assertSee('Mes émargements');
    $this->get(DossierPaieEnseignantResource::getUrl('index'))->assertForbidden();
    $this->get(SuiviHeuresEnseignees::getUrl())->assertForbidden();
    Livewire::test(ListPointagesEnseignant::class)->assertCanSeeTableRecords([$p])->assertCanNotSeeTableRecords([$autre])
        ->assertTableActionHidden('valider', $p)
        ->callAction('emarger', ['seance_id' => (string) $this->seances[2]->id, 'arrivee' => '08:10', 'depart' => '11:00', 'contenu_enseigne' => 'Chapitre 3'])
        ->assertHasNoActionErrors();
    expect(PointageEnseignant::query()->where('seance_id', $this->seances[2]->id)->where('enseignant_id', $this->prof->id)->value('mode'))->toBe('signature');

    $this->actingAs(($this->compte)('service_financier'));
    $this->get(DossierPaieEnseignantResource::getUrl('index'))->assertOk();
    $this->get(PointageEnseignantResource::getUrl('index'))->assertForbidden();
});
