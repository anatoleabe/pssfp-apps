<?php

declare(strict_types=1);

use App\Filament\Resources\BaremeRemunerationResource;
use App\Filament\Resources\EtatPaieResource;
use App\Filament\Resources\EtatPaieResource\Pages\ListEtatsPaie;
use App\Filament\Resources\EtatPaieResource\Pages\ViewEtatPaie;
use App\Models\BaremeRemuneration;
use App\Models\DossierPaieEnseignant;
use App\Models\EmploiDuTemps;
use App\Models\EtatPaie;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\Parcours;
use App\Models\PlageHoraire;
use App\Models\PointageEnseignant;
use App\Models\Seance;
use App\Models\User;
use App\Services\Lmd\Assiduite;
use App\Services\Lmd\Calendriers;
use App\Services\Lmd\EmploisDuTemps;
use App\Services\Lmd\EtatsPaie;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\PointagesEnseignant;
use App\Services\Lmd\Programmations;
use App\Support\AppSettings;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
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
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Essai '.Str::random(4)]), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->financier = ($this->compte)('service_financier');
    $this->prof = ($this->compte)('enseignant');
    $this->pointages = app(PointagesEnseignant::class);
    $this->service = app(EtatsPaie::class);
    $this->programmations = app(Programmations::class);

    $calendriers = app(Calendriers::class);
    $c = $calendriers->creer('2026-2027', '2026-10-01', '2027-08-31', $this->agent);
    $this->periode = $calendriers->enregistrer($c, ['type' => 'periode_cours', 'libelle' => 'Première période de cours', 'niveau' => null,
        'debut_prevu' => '2026-11-02', 'fin_prevue' => '2026-12-19'], $this->agent);
    $calendriers->soumettre($c->refresh(), $this->agent);
    $calendriers->signer($c->refresh(), UploadedFile::fake()->createWithContent('cal.pdf', "%PDF-1.4\n%x\n"), 'Le Chef de la structure', '2026-11-01', ($this->compte)('chef_structure'));

    $this->ecue = MaquetteEcue::query()->where('code', 'MPMPP711')->sole();
    $this->quota = $this->programmations->definirQuota($this->periode, $this->ecue, ['heures_cm' => 30, 'heures_td' => 5], $this->agent);
    $this->programmations->validerQuota($this->quota, $this->responsable);
    // Programmation validée : seule une programmation validée, notifiée ou acceptée ouvre droit au paiement.
    $this->prog = $this->programmations->programmer($this->quota, ['enseignant_id' => $this->prof->id, 'role' => 'responsable', 'heures_cm' => 6, 'heures_td' => 3, 'modalite' => 'presentiel'], $this->agent);
    $this->programmations->valider($this->prog, $this->responsable);
    $this->allouer = function (array $heures): void {
        $this->programmations->modifier($this->prog->refresh(), ['enseignant_id' => $this->prof->id, 'role' => 'responsable', 'modalite' => 'presentiel', 'heures_td' => 3, ...$heures], $this->agent);
        $this->programmations->valider($this->prog->refresh(), $this->responsable);
    };
    $edt = app(EmploisDuTemps::class);
    $e = $edt->creer($this->periode, Parcours::query()->where('code', 'TC')->sole(), $this->agent);
    $edt->programmer($e, ['maquette_ecue_id' => $this->ecue->id, 'date_seance' => '2026-11-02', 'plage_horaire_id' => PlageHoraire::query()->where('code', 'MATIN')->sole()->id,
        'enseignant_id' => $this->prof->id, 'modalite' => 'presentiel', 'nature' => 'CM'], $this->agent, '2026-11-16');   // 3 lundis, 08:00-11:00
    $e->forceFill(['statut' => EmploiDuTemps::PUBLIE])->save();
    $this->seances = Seance::query()->orderBy('date_seance')->get();
    // Séance supplémentaire de 3 h (même cours, l'après-midi).
    $this->seanceEnPlus = function (string $date, ?int $ecueId = null): Seance {
        $s = $this->seances[0]->replicate(['uuid'])->forceFill(['date_seance' => $date, 'debut' => '14:00', 'fin' => '17:00', 'programmation_id' => null,
            'maquette_ecue_id' => $ecueId ?? $this->ecue->id]);
        $s->save();

        return $s;
    };
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 20)->setTime(10, 0));

    // Pointage validé de 3 h (saisie de l'agent, validation du responsable).
    $this->pointer = function (Seance $s, string $nature = 'CM', ?User $u = null): PointageEnseignant {
        $p = $this->pointages->emarger($s, $u ?? $this->prof, ['arrivee' => substr((string) $s->debut, 0, 5), 'depart' => substr((string) $s->fin, 0, 5),
            'nature' => $nature, 'contenu_enseigne' => 'Séance du '.$s->date_seance->format('d/m')], $this->agent);
        $this->pointages->valider($p, $this->responsable);

        return $p->refresh();
    };
    $this->dossier = function (User $u, array $plus = []): void {
        $d = $this->pointages->ouvrirDossier($u, $this->agent);
        $this->pointages->majDossier($d, ['statut_paie' => 'vacataire', 'niu' => 'P0000000'.random_int(10000, 99999).'X', 'banque' => 'Banque fictive',
            'compte_paiement' => '10000 00000 '.random_int(10000000, 99999999).' 00', ...$plus], $this->financier);
    };
    $this->permanent = fn (float $service) => $this->pointages->majDossier(DossierPaieEnseignant::query()->where('enseignant_id', $this->prof->id)->sole(),
        ['statut_paie' => 'permanent', 'service_du_heures' => $service], $this->agent);
    // Circuit complet par des personnes distinctes, jusqu'au virement.
    $this->payer = function (EtatPaie $etat): void {
        $this->service->avancer($etat, EtatPaie::VERIFIE, $this->responsable);
        $this->service->avancer($etat, EtatPaie::TRANSMIS, $this->responsable, 'BT-'.Str::random(4));
        $this->service->avancer($etat, EtatPaie::PRIS_EN_CHARGE, $this->financier);
        $this->service->avancer($etat, EtatPaie::PAYE, $this->financier, 'VIR-'.Str::random(4), '2026-11-20');
    };
});

it('paie l\'essai de D24 : 6 h de CM à 17 500 FCFA avec 11 % de retenue, 3 h de TD à 0 FCFA', function (): void {
    ($this->dossier)($this->prof);
    ($this->pointer)($this->seances[0]);
    ($this->pointer)($this->seances[1]);
    ($this->pointer)($this->seances[2], 'TD');

    $etat = $this->service->creerMensuel('2026-11', $this->agent);
    $cm = $etat->lignes()->where('nature', 'CM')->sole();
    $td = $etat->lignes()->where('nature', 'TD')->sole();

    expect($etat->numero)->toBe('EP-2026-11')
        ->and($cm->quantite)->toBe(6.0)
        ->and($cm->montant_brut)->toBe(105000)
        ->and($cm->montant_retenue)->toBe(11550)
        ->and($cm->montant_net)->toBe(93450)
        ->and($td->quantite)->toBe(3.0)
        ->and($td->montant_brut)->toBe(0)
        ->and($td->observation)->toContain('non rémunérées')
        ->and(PointageEnseignant::query()->whereNull('ligne_paie_id')->count())->toBe(0)
        ->and($this->service->synthese($etat)['totaux'])->toMatchArray(['enseignants' => 1, 'heures_cm' => 6.0, 'brut' => 105000, 'net' => 93450]);
    expect(fn () => $this->pointages->modifier(PointageEnseignant::query()->first(), ['arrivee' => '08:00', 'depart' => '10:00', 'contenu_enseigne' => 'x'], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'payé');
});

it('plafonne aux heures allouées et rattache les heures hors quota sans les payer', function (): void {
    ($this->dossier)($this->prof);
    foreach ($this->seances as $s) {
        ($this->pointer)($s);   // 9 h de CM validées pour 6 h allouées
    }
    $etat = $this->service->creerMensuel('2026-11', $this->agent);
    $cm = $etat->lignes()->sole();

    expect($cm->quantite)->toBe(6.0)->and($cm->heures_hors_quota)->toBe(3.0)->and($cm->observation)->toContain('3 h au-delà')
        ->and(PointageEnseignant::query()->whereNull('ligne_paie_id')->count())->toBe(0);

    // Un mois payé, 3 h de plus ensuite : rattachées en hors quota, jamais payées.
    ($this->payer)($etat);
    ($this->pointer)(($this->seanceEnPlus)('2026-11-09'));
    $r = $this->service->creerRectificatif($etat->refresh(), $this->agent);
    expect($r->lignes()->sole()->quantite)->toBe(0.0)->and($r->lignes()->sole()->heures_hors_quota)->toBe(3.0)->and($r->lignes()->sole()->montant_brut)->toBe(0);
});

it('impute d\'abord le service dû d\'un permanent, une seule fois, et seulement sur des heures allouées (relecture de sécurité)', function (): void {
    ($this->dossier)($this->prof);
    ($this->allouer)(['heures_cm' => 9]);
    ($this->permanent)(6);
    // 3 h sur un cours non alloué : hors quota, sans consommer le service dû.
    $autre = MaquetteEcue::query()->where('code', '<>', $this->ecue->code)->firstOrFail();
    ($this->pointer)(($this->seanceEnPlus)('2026-11-03', $autre->id));
    foreach ($this->seances as $s) {
        ($this->pointer)($s);   // 9 h allouées et faites
    }
    $etat = $this->service->creerMensuel('2026-11', $this->agent);
    $ligne = $etat->lignes()->where('maquette_ecue_id', $this->ecue->id)->sole();

    expect($ligne->heures_service)->toBe(6.0)->and($ligne->quantite)->toBe(3.0)->and($ligne->observation)->toContain('6 h imputées sur le service dû')
        ->and($etat->lignes()->where('maquette_ecue_id', $autre->id)->sole()->heures_hors_quota)->toBe(3.0)
        ->and($etat->lignes()->where('maquette_ecue_id', $autre->id)->sole()->heures_service)->toBe(0.0);

    // Rectificatif après paiement : l'allocation (3 payées + 6 imputées) est épuisée, le service dû aussi.
    ($this->payer)($etat);
    ($this->pointer)(($this->seanceEnPlus)('2026-11-10'));
    $r = $this->service->creerRectificatif($etat->refresh(), $this->agent);
    expect($r->lignes()->sole()->quantite)->toBe(0.0)->and($r->lignes()->sole()->heures_hors_quota)->toBe(3.0)->and($r->lignes()->sole()->heures_service)->toBe(0.0);
});

it('écarte l\'enseignant sans dossier complet et garde ses pointages libres', function (): void {
    $p = ($this->pointer)($this->seances[0]);

    $etat = $this->service->creerMensuel('2026-11', $this->agent);

    expect($etat->lignes()->count())->toBe(0)
        ->and($etat->ecartes)->toEqual([['enseignant' => $this->prof->name, 'raison' => 'pas de dossier de paie']])
        ->and($p->refresh()->ligne_paie_id)->toBeNull()
        ->and(fn () => $this->service->avancer($etat, EtatPaie::VERIFIE, $this->responsable))->toThrow(InvalidArgumentException::class, 'Aucune ligne');

    ($this->dossier)($this->prof);
    $this->service->regenerer($etat, $this->agent);
    expect($etat->refresh()->lignes()->count())->toBe(1)->and($etat->ecartes)->toBe([]);
});

it('ne paie pas sur une programmation seulement proposée, ni une séance annulée', function (): void {
    ($this->dossier)($this->prof);
    $p = ($this->pointer)($this->seances[0]);
    $this->programmations->modifier($this->prog->refresh(), ['enseignant_id' => $this->prof->id, 'role' => 'responsable', 'heures_cm' => 100, 'modalite' => 'presentiel'], $this->agent);

    $etat = $this->service->creerMensuel('2026-11', $this->agent);
    expect($etat->lignes()->sole()->quantite)->toBe(0.0)->and($etat->lignes()->sole()->heures_hors_quota)->toBe(3.0);

    expect(fn () => app(Assiduite::class)->annuler($p->seance, 'Séance annulée après coup', $this->agent))->toThrow(InvalidArgumentException::class, 'ne s\'annule plus');
});

it('suit le circuit par des personnes distinctes et refuse les étapes sautées, le doublon du mois et le rejet après prise en charge', function (): void {
    ($this->dossier)($this->prof);
    ($this->pointer)($this->seances[0]);
    $etat = $this->service->creerMensuel('2026-11', $this->agent);
    $empreinte = $this->service->empreinte($etat);

    expect(fn () => $this->service->creerMensuel('2026-11', $this->agent))->toThrow(InvalidArgumentException::class, 'existe déjà')
        ->and(fn () => $this->service->creerMensuel('2027-01', $this->agent))->toThrow(InvalidArgumentException::class, 'futur')
        ->and(fn () => $this->service->avancer($etat, EtatPaie::VERIFIE, $this->agent))->toThrow(HttpException::class)
        ->and(fn () => $this->service->avancer($etat, EtatPaie::PRIS_EN_CHARGE, $this->financier))->toThrow(InvalidArgumentException::class, 'impossible');

    // Le responsable qui régénère ne vérifie pas ; un autre responsable le fait, sur la version qu'il a vue.
    $this->service->regenerer($etat, $this->responsable);
    expect(fn () => $this->service->avancer($etat, EtatPaie::VERIFIE, $this->responsable))->toThrow(InvalidArgumentException::class, 'déjà agi');
    $autreResponsable = ($this->compte)('scolarite_responsable');
    expect(fn () => $this->service->avancer($etat, EtatPaie::VERIFIE, $autreResponsable, empreinte: $empreinte))->toThrow(InvalidArgumentException::class, 'a changé');
    $this->service->avancer($etat, EtatPaie::VERIFIE, $autreResponsable, empreinte: $this->service->empreinte($etat->refresh()));

    expect(fn () => $this->service->avancer($etat, EtatPaie::VISE, ($this->compte)('super_admin')))->toThrow(InvalidArgumentException::class, 'pas requis')
        ->and(fn () => $this->service->avancer($etat, EtatPaie::TRANSMIS, $autreResponsable, ' '))->toThrow(InvalidArgumentException::class, 'bordereau')
        ->and(fn () => $this->service->definirVisa(true, $autreResponsable))->toThrow(HttpException::class);

    // Visa exigé : la transmission attend le visa ; désactivé ensuite, un état visé se transmet quand même.
    $admin = ($this->compte)('super_admin');
    $this->service->definirVisa(true, $admin);
    expect(fn () => $this->service->avancer($etat, EtatPaie::TRANSMIS, $autreResponsable, 'BT-2026-11'))->toThrow(InvalidArgumentException::class, 'visa');
    $this->service->avancer($etat, EtatPaie::VISE, ($this->compte)('super_admin'));
    $this->service->definirVisa(false, $admin);
    $this->service->avancer($etat, EtatPaie::TRANSMIS, $autreResponsable, 'BT-2026-11');

    // Un état transmis ne se rejette que par le service financier.
    expect(fn () => $this->service->rejeter($etat, 'Montants à revoir', $autreResponsable))->toThrow(HttpException::class);
    $this->service->avancer($etat, EtatPaie::PRIS_EN_CHARGE, $this->financier);
    expect(fn () => $this->service->rejeter($etat, 'Trop tard pour rejeter', $this->financier))->toThrow(InvalidArgumentException::class, 'rectificatif')
        ->and(fn () => $this->service->avancer($etat, EtatPaie::PAYE, $this->financier, 'VIR-1'))->toThrow(InvalidArgumentException::class, 'date et la référence')
        ->and(fn () => $this->service->avancer($etat, EtatPaie::PAYE, $this->financier, 'VIR-1', '2026-11-01'))->toThrow(InvalidArgumentException::class, 'précéder');
    $this->service->avancer($etat, EtatPaie::PAYE, $this->financier, 'VIR-2026-11-001', '2026-11-20');
    expect($etat->refresh()->statut)->toBe(EtatPaie::PAYE)->and($etat->reference_paiement)->toBe('VIR-2026-11-001');

    // Rectificatif : un seul à la fois, jamais vide.
    expect(fn () => $this->service->creerRectificatif($etat, $this->agent))->toThrow(InvalidArgumentException::class, 'Aucun pointage');
    ($this->pointer)($this->seances[1]);
    $r = $this->service->creerRectificatif($etat, $this->agent);
    ($this->pointer)($this->seances[2]);
    expect($r->numero)->toBe('EP-2026-11-R1')->and($r->lignes()->sole()->quantite)->toBe(3.0)
        ->and(fn () => $this->service->creerRectificatif($etat->refresh(), $this->agent))->toThrow(InvalidArgumentException::class, 'déjà en cours');
});

it('libère les pointages d\'un état rejeté avant la prise en charge', function (): void {
    ($this->dossier)($this->prof);
    $p = ($this->pointer)($this->seances[0]);
    $etat = $this->service->creerMensuel('2026-11', $this->agent);

    expect(fn () => $this->service->rejeter($etat, 'non', $this->responsable))->toThrow(InvalidArgumentException::class, 'motif');
    $this->service->rejeter($etat, 'Montants à revoir', $this->responsable);

    expect($etat->refresh()->statut)->toBe(EtatPaie::REJETE)
        ->and($p->refresh()->ligne_paie_id)->toBeNull()
        ->and($this->service->creerMensuel('2026-11', $this->agent)->lignes()->count())->toBe(1);
});

it('paie une surveillance à la séance ; le barème modifié se valide par un autre responsable', function (): void {
    ($this->dossier)($this->prof);
    $evaluation = $this->seances[0]->replicate(['uuid'])->forceFill(['nature' => 'evaluation', 'debut' => '14:00', 'fin' => '16:00', 'programmation_id' => null]);
    $evaluation->save();
    $p = $this->pointages->emarger($evaluation, $this->prof, ['arrivee' => '14:00', 'depart' => '16:00', 'nature' => 'CM', 'contenu_enseigne' => 'Surveillance'], $this->prof);
    expect($p->nature)->toBe('surveillance');
    $this->pointages->valider($p, $this->agent);

    $bareme = BaremeRemuneration::query()->where('annee_academique', '2026-2027')->where('nature', 'surveillance')->sole();
    $etat = $this->service->creerMensuel('2026-11', $this->agent);
    expect($etat->ecartes[0]['raison'])->toContain('barème surveillance 2026-2027 non validé');

    expect(fn () => $this->service->validerBareme($bareme, $this->agent))->toThrow(HttpException::class);
    expect($this->service->modifierBareme($bareme, 5000, 11, 'Note de service n° 001 (essai)', $this->responsable))->toBeTrue()
        ->and($this->service->modifierBareme($bareme->refresh(), 5000, 11, 'Note de service n° 001 (essai)', $this->agent))->toBeFalse()
        ->and(fn () => $this->service->validerBareme($bareme->refresh(), $this->responsable))->toThrow(InvalidArgumentException::class, 'un autre responsable')
        ->and(fn () => $this->service->validerBareme($bareme->refresh(), ($this->compte)('scolarite_responsable'), 4000))->toThrow(InvalidArgumentException::class, 'a changé');
    $this->service->validerBareme($bareme->refresh(), ($this->compte)('scolarite_responsable'), 5000);
    $this->service->regenerer($etat, $this->agent);

    $ligne = $etat->refresh()->lignes()->sole();
    expect($ligne->nature)->toBe('surveillance')->and($ligne->quantite)->toBe(1.0)->and($ligne->montant_brut)->toBe(5000)->and($ligne->montant_retenue)->toBe(550);
});

it('fait valider par un autre agent un pointage dont la nature a été changée', function (): void {
    $p = $this->pointages->emarger($this->seances[0], $this->prof, ['arrivee' => '08:00', 'depart' => '11:00', 'nature' => 'TD', 'contenu_enseigne' => 'TD'], $this->agent);

    expect(fn () => $this->pointages->valider($p, $this->agent))->toThrow(InvalidArgumentException::class, 'changé la nature');
    $this->pointages->valider($p, $this->responsable);
    expect($p->refresh()->statut)->toBe(PointageEnseignant::VALIDE);
});

it('ouvre les écrans de la paie selon le rôle et produit les PDF', function (): void {
    ($this->dossier)($this->prof);
    ($this->pointer)($this->seances[0]);

    $this->actingAs($this->agent);
    $this->get(BaremeRemunerationResource::getUrl('index'))->assertOk()->assertSee('17 500 FCFA');
    Livewire::test(ListEtatsPaie::class)->assertActionHidden('visa')->callAction('preparer', ['mois' => '2026-11'])->assertHasNoActionErrors();
    $etat = EtatPaie::query()->sole();
    $this->get(EtatPaieResource::getUrl('view', ['record' => $etat]))->assertOk()->assertSee('EP-2026-11')->assertSee('46 725 FCFA');
    Livewire::test(ViewEtatPaie::class, ['record' => $etat->getRouteKey()])->assertActionHidden('verifier')->assertActionHidden('pdfBordereau')
        ->callAction('pdfEtat')->assertFileDownloaded('ep-2026-11.pdf');

    // Chaque étape recharge la fiche : une instance par action.
    $this->actingAs($this->responsable);
    Livewire::test(ViewEtatPaie::class, ['record' => $etat->getRouteKey()])->callAction('verifier')->assertHasNoActionErrors();
    Livewire::test(ViewEtatPaie::class, ['record' => $etat->getRouteKey()])->callAction('transmettre', ['reference' => 'BT-2026-11'])->assertHasNoActionErrors();
    Livewire::test(ViewEtatPaie::class, ['record' => $etat->getRouteKey()])->assertActionHidden('rejeter')
        ->callAction('pdfBordereau')->assertFileDownloaded('bordereau-ep-2026-11.pdf');

    $this->actingAs($this->financier);
    $this->get(EtatPaieResource::getUrl('index'))->assertOk();
    $this->get(BaremeRemunerationResource::getUrl('index'))->assertOk();
    Livewire::test(ViewEtatPaie::class, ['record' => $etat->getRouteKey()])->assertActionHidden('verifier')->assertActionVisible('rejeter')
        ->callAction('prendreEnCharge')->assertHasNoActionErrors();
    Livewire::test(ViewEtatPaie::class, ['record' => $etat->getRouteKey()])->callAction('payer', ['date' => '2026-11-20', 'reference' => 'VIR-001'])->assertHasNoActionErrors();
    expect($etat->refresh()->statut)->toBe(EtatPaie::PAYE);

    $this->actingAs($this->prof);
    $this->get(EtatPaieResource::getUrl('index'))->assertForbidden();
    $this->get(BaremeRemunerationResource::getUrl('index'))->assertForbidden();
    AppSettings::forget(EtatsPaie::CLE_VISA);
});
