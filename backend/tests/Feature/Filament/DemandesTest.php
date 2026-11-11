<?php

declare(strict_types=1);

use App\Filament\Resources\DemandeAutorisationResource;
use App\Filament\Resources\DemandeAutorisationResource\Pages\CreateDemande;
use App\Filament\Resources\DemandeAutorisationResource\Pages\ListDemandes;
use App\Filament\Resources\DemandeAutorisationResource\Pages\ViewDemande;
use App\Models\AnomalieSysteme;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\DecisionDemande;
use App\Models\DemandeAutorisation;
use App\Models\DocumentDelivre;
use App\Models\EtapeSaisie;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\Message;
use App\Models\User;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use App\Services\Supervision\Supervision;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses()->group('filament', 'messagerie', 'lmd');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->compte = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role.' '.Str::random(3)]),
        fn (User $u) => $u->assignRole($role),
    );
    $this->demandes = app(Demandes::class);
    $this->demandeur = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->controleur = ($this->compte)('admin');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 11)->setTime(9, 0));
});

function etapeVerrouillee(): EtapeSaisie
{
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    $etape = EtapeSaisie::query()->create(['maquette_ecue_id' => MaquetteEcue::query()->value('id'), 'session' => 'normale', 'etape' => 'examen']);
    $etape->forceFill(['statut' => EtapeSaisie::VERROUILLEE, 'verrouillee_at' => now()])->save();

    return $etape;
}

function documentDelivre(): DocumentDelivre
{
    $bareme = BaremeScolarite::query()->firstOrCreate(['annee_academique' => '2026-2027', 'niveau' => 'M1'], ['montant_inscription' => 185000,
        'montant_tranche1' => 500000, 'montant_tranche2' => 500000, 'tranche1_echeance' => now()->addMonth()->toDateString()]);
    $auditeur = Auditeur::factory()->campagneCommune()->create();
    $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id]);

    return DocumentDelivre::query()->create([
        'type' => DocumentDelivre::CERTIFICAT, 'numero' => 'CS-2026-'.Str::upper(Str::random(6)), 'code_verification' => Str::random(20),
        'auditeur_id' => $auditeur->id, 'inscription_annuelle_id' => $i->id, 'titulaire' => 'Titulaire fictif', 'chemin' => 'documents/x.pdf',
        'sha256' => str_repeat('a', 64),
    ]);
}

it('numérote la demande, la route aux décideurs et refuse les doublons et qui détient déjà le droit', function (): void {
    $etape = etapeVerrouillee();
    expect(app(TypesDemande::class)->objets(TypesDemande::ROUVRIR_ETAPE))->toHaveKey($etape->id);

    $d = $this->demandes->soumettre($this->demandeur, TypesDemande::ROUVRIR_ETAPE, $etape->id, 'Note d\'examen mal saisie pour deux auditeurs');

    expect($d->numero)->toBe('DM-2026-00001')->and($d->statut)->toBe(DemandeAutorisation::SOUMISE)
        ->and($d->echeance->diffInHours($d->soumise_le, true))->toEqual(24.0)
        ->and($d->objet)->toContain('Examen');
    $m = Message::query()->where('categorie', Message::DEMANDE)->sole();
    expect($m->expediteur_id)->toBe($this->demandeur->id)->and($m->priorite)->toBe(Message::HAUTE)
        ->and($m->destinataires()->pluck('user_id')->all())->toContain($this->responsable->id)->not->toContain($this->demandeur->id)
        ->and($this->demandes->decideurs($d))->toContain($this->responsable->id)->not->toContain($this->demandeur->id);

    expect(fn () => $this->demandes->soumettre(($this->compte)('scolarite_agent'), TypesDemande::ROUVRIR_ETAPE, $etape->id, 'Même étape, autre agent'))
        ->toThrow(InvalidArgumentException::class, 'déjà en cours')
        ->and(fn () => $this->demandes->soumettre($this->demandeur, TypesDemande::ROUVRIR_ETAPE, 999999, 'Objet inexistant à rouvrir'))
        ->toThrow(InvalidArgumentException::class, 'ne se prête pas')
        ->and(fn () => $this->demandes->soumettre($this->demandeur, TypesDemande::VALIDER_AUTRE, null, 'court'))
        ->toThrow(InvalidArgumentException::class, 'motif');
    expect(fn () => $this->demandes->soumettre($this->controleur, TypesDemande::VALIDER_AUTRE, null, 'Je détiens déjà ce droit', 'Réglages'))
        ->toThrow(InvalidArgumentException::class, 'déjà ce droit');
});

it('exécute à l\'approbation du responsable, sous son identité, et refuse le demandeur et qui n\'a pas le droit', function (): void {
    $etape = etapeVerrouillee();
    $d = $this->demandes->soumettre($this->demandeur, TypesDemande::ROUVRIR_ETAPE, $etape->id, 'Note d\'examen mal saisie pour deux auditeurs');

    expect(fn () => $this->demandes->decider($this->demandeur, $d, true))->toThrow(InvalidArgumentException::class, 'propre demande')
        ->and(fn () => $this->demandes->decider(($this->compte)('editor'), $d, true))->toThrow(InvalidArgumentException::class, 'droit requis')
        ->and(fn () => $this->demandes->decider($this->responsable, $d, true, null, DemandeAutorisation::VISA_ATTENDU))->toThrow(InvalidArgumentException::class, 'a changé');

    expect($this->demandes->decider($this->responsable, $d, true, 'D\'accord'))->toBe('Étape de saisie rouverte');
    expect($etape->refresh()->statut)->toBe(EtapeSaisie::OUVERTE)->and($etape->rouverte_par)->toBe($this->responsable->id)
        ->and($etape->motif_reouverture)->toStartWith('Demande DM-2026-00001 :')
        ->and($d->refresh()->statut)->toBe(DemandeAutorisation::EXECUTEE)
        ->and(Message::query()->where('categorie', Message::DECISION)->sole()->destinataires()->pluck('user_id')->all())->toBe([$this->demandeur->id])
        ->and(fn () => $this->demandes->decider($this->responsable, $d, false, 'Trop tard pour rejeter'))->toThrow(InvalidArgumentException::class, 'déjà traitée');
});

it('exige pour une annulation le responsable puis le visa d\'une autre personne', function (): void {
    $doc = documentDelivre();
    $d = $this->demandes->soumettre($this->demandeur, TypesDemande::REVOQUER_DOCUMENT, $doc->id, 'Certificat délivré au mauvais auditeur');

    expect($this->demandes->decider($this->responsable, $d, true))->toContain('visa du contrôle interne attendu');
    expect($d->refresh()->statut)->toBe(DemandeAutorisation::VISA_ATTENDU)->and($doc->refresh()->revoque_at)->toBeNull()
        ->and($this->demandes->decideurs($d))->toContain($this->controleur->id)->not->toContain($this->responsable->id)
        ->and(Message::query()->where('objet', 'like', '%visa du contrôle interne requis')->sole()->destinataires()->pluck('user_id')->all())->toContain($this->controleur->id);

    $this->demandes->decider($this->controleur, $d, true, 'Visa');
    expect($d->refresh()->statut)->toBe(DemandeAutorisation::EXECUTEE)
        ->and($doc->refresh()->revoque_at)->not->toBeNull()->and($doc->revoque_par)->toBe($this->responsable->id)
        ->and($d->decisions()->pluck('qualite')->sort()->values()->all())->toBe([DecisionDemande::CONTROLE_INTERNE, DecisionDemande::RESPONSABLE]);

    $autre = documentDelivre();
    $d2 = $this->demandes->soumettre($this->demandeur, TypesDemande::REVOQUER_DOCUMENT, $autre->id, 'Deuxième certificat à révoquer');
    $this->demandes->decider($this->responsable, $d2, true);
    expect(fn () => $this->demandes->decider($this->controleur, $d2, false, 'court'))->toThrow(InvalidArgumentException::class, 'motive');
    $this->demandes->decider($this->controleur, $d2, false, 'Pièce justificative absente du dossier');
    expect($d2->refresh()->statut)->toBe(DemandeAutorisation::REJETEE)->and($autre->refresh()->revoque_at)->toBeNull();
});

it('enregistre un échec d\'exécution sans perdre les décisions, permet le retrait et garde les décisions en ajout seul', function (): void {
    etapeVerrouillee();
    $maquette = Maquette::query()->where('statut', Maquette::BROUILLON)->firstOrFail();
    $d = $this->demandes->soumettre($this->demandeur, TypesDemande::VALIDER_MAQUETTE, $maquette->id, 'Maquette relue par la commission pédagogique');
    $maquette->forceFill(['statut' => Maquette::VALIDEE, 'validee_at' => now()])->save();

    expect($this->demandes->decider($this->responsable, $d, true))->toStartWith('Échec de l\'exécution');
    expect($d->refresh()->statut)->toBe(DemandeAutorisation::ECHEC)->and($d->resultat)->toContain('modifié depuis la demande')
        ->and($d->decisions()->count())->toBe(1);
    expect(fn () => DB::transaction(fn () => DB::table('demandes_autorisation')->where('id', $d->id)->update(['entite_id' => 1])))->toThrow(QueryException::class, 'figée')
        ->and(fn () => DB::transaction(fn () => DB::table('demandes_autorisation')->where('id', $d->id)->update(['statut' => 'soumise'])))->toThrow(QueryException::class, 'Transition');
    expect(fn () => DB::transaction(fn () => DB::table('decisions_demandes')->where('demande_id', $d->id)->delete()))->toThrow(QueryException::class, 'ajout seul');

    $autre = $this->demandes->soumettre($this->demandeur, TypesDemande::VALIDER_AUTRE, null, 'Validation de la liste des salles', 'Liste des salles 2026-2027');
    expect(fn () => $this->demandes->retirer($this->responsable, $autre))->toThrow(InvalidArgumentException::class, 'demandeur');
    $this->demandes->retirer($this->demandeur, $autre);
    expect($autre->refresh()->statut)->toBe(DemandeAutorisation::RETIREE);
});

it('signale par DEM_ATTENTE les demandes sans réponse après leur échéance', function (): void {
    $d = $this->demandes->soumettre($this->demandeur, TypesDemande::VALIDER_AUTRE, null, 'Validation de la liste des salles', 'Liste des salles');
    app(Supervision::class)->executer(['DEM_ATTENTE']);
    expect(AnomalieSysteme::query()->where('controle_code', 'DEM_ATTENTE')->count())->toBe(0);

    $this->travel(73)->hours();
    app(Supervision::class)->executer(['DEM_ATTENTE']);
    expect(AnomalieSysteme::query()->where('controle_code', 'DEM_ATTENTE')->sole()->cle)->toBe($d->numero);
});

it('soumet depuis l\'écran, range les demandes par onglet et décide depuis la fiche', function (): void {
    $this->actingAs($this->demandeur);
    Livewire::test(CreateDemande::class)
        ->fillForm(['type' => TypesDemande::VALIDER_AUTRE, 'objet_libre' => 'Liste des salles 2026-2027', 'motif' => 'Liste mise à jour après travaux'])
        ->call('create')->assertHasNoFormErrors();
    $d = DemandeAutorisation::query()->sole();
    Livewire::test(ListDemandes::class, ['activeTab' => 'mes_demandes'])->assertCanSeeTableRecords([$d]);

    $this->actingAs($this->controleur);
    expect(DemandeAutorisationResource::getNavigationBadge())->toBe('1');
    Livewire::test(ListDemandes::class, ['activeTab' => 'a_decider'])->assertCanSeeTableRecords([$d]);
    Livewire::test(ViewDemande::class, ['record' => $d->uuid])->assertActionVisible('approuver')->callAction('approuver', ['commentaire' => 'OK']);
    expect($d->refresh()->statut)->toBe(DemandeAutorisation::EXECUTEE)->and(DemandeAutorisationResource::getNavigationBadge())->toBeNull();

    $this->actingAs(($this->compte)('editor'))->get(DemandeAutorisationResource::getUrl('view', ['record' => $d]))->assertNotFound();
    $this->actingAs($this->demandeur)->get(DemandeAutorisationResource::getUrl('view', ['record' => $d]))->assertOk()->assertSee('Exécutée');
});

it('ferme aux comptes sans consultation les types et leurs listes d\'objets, et limite le débit', function (): void {
    $editeur = ($this->compte)('editor');
    $etape = etapeVerrouillee();

    expect(array_keys(TypesDemande::libellesPour($editeur)))->toBe([TypesDemande::VALIDER_AUTRE, TypesDemande::DEVERROUILLER_COMPTE])
        ->and(TypesDemande::libellesPour($this->demandeur))->toHaveKey(TypesDemande::ROUVRIR_ETAPE)
        ->and(fn () => $this->demandes->soumettre($editeur, TypesDemande::ROUVRIR_ETAPE, $etape->id, 'Je ne vois pas les notes'))->toThrow(InvalidArgumentException::class, 'pas ouvert');

    foreach (range(1, Demandes::SOUMISSIONS_PAR_HEURE) as $n) {
        $this->demandes->soumettre($editeur, TypesDemande::VALIDER_AUTRE, null, 'Validation du point numéro '.$n, 'Point '.$n);
    }
    expect(fn () => $this->demandes->soumettre($editeur, TypesDemande::VALIDER_AUTRE, null, 'Une demande de trop', 'Point en trop'))->toThrow(InvalidArgumentException::class, 'Trop de demandes');
});

it('refuse d\'exécuter sur un objet modifié depuis la demande', function (): void {
    etapeVerrouillee();
    $maquette = Maquette::query()->where('statut', Maquette::BROUILLON)->firstOrFail();
    $d = $this->demandes->soumettre($this->demandeur, TypesDemande::VALIDER_MAQUETTE, $maquette->id, 'Maquette relue par la commission pédagogique');
    MaquetteEcue::query()->whereIn('maquette_ue_id', $maquette->ues()->select('id'))->limit(1)->first()->forceFill(['intitule' => 'Intitulé changé après la demande'])->save();

    $this->demandes->decider($this->responsable, $d, true);
    expect($d->refresh()->statut)->toBe(DemandeAutorisation::ECHEC)->and($d->resultat)->toContain('modifié depuis la demande')
        ->and($maquette->refresh()->statut)->toBe(Maquette::BROUILLON);
});
