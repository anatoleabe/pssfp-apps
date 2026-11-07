<?php

declare(strict_types=1);

use App\Filament\Resources\DeliberationResource;
use App\Filament\Resources\DeliberationResource\Pages\ViewDeliberation;
use App\Models\AcquisUe;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\Deliberation;
use App\Models\EtapeSaisie;
use App\Models\InscriptionAnnuelle;
use App\Models\Jury;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\MaquetteUe;
use App\Models\MembreJury;
use App\Models\MesureJury;
use App\Models\NoteEcue;
use App\Models\ResultatAnnuel;
use App\Models\User;
use App\Services\Lmd\Deliberations;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\PvDeliberation;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    Maquette::query()->update(['statut' => Maquette::VALIDEE, 'validee_at' => now()]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->agent = ($this->compte)('scolarite_agent');
    $this->service = app(Deliberations::class);

    // Jury : président, rapporteur et un membre, chacun avec son compte
    $this->president = ($this->compte)('membre_jury');
    $this->rapporteur = ($this->compte)('membre_jury');
    $this->membre = ($this->compte)('membre_jury');
    $this->jury = Jury::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'nature' => 'deliberation',
        'objet' => 'Délibération M1', 'acte_reference' => 'NS 26/001', 'acte_date' => '2026-11-01', 'acte_signataire' => 'Le Doyen de la FSEG']);
    foreach ([['president', $this->president], ['rapporteur', $this->rapporteur], ['membre', $this->membre]] as [$role, $u]) {
        MembreJury::query()->create(['jury_id' => $this->jury->id, 'role' => $role, 'nom' => 'Membre '.$role, 'user_id' => $u->id]);
    }
    $this->tous = fn (bool $membrePresent = true): array => $this->jury->membres()->get()->mapWithKeys(fn (MembreJury $m): array => [$m->id => [
        'present' => $m->role !== 'membre' || $membrePresent, 'motif_absence' => $m->role === 'membre' && ! $membrePresent ? 'Mission' : null]])->all();
});

/** Inscription 2026-2027, inscrite au parcours ; `$notes` : note de chaque UE par code (défaut pour les autres). */
function auditeurNote(string $niveau, ?string $specialite, float $defaut, array $notes = []): InscriptionAnnuelle
{
    $bareme = BaremeScolarite::query()->firstOrCreate(['annee_academique' => '2026-2027', 'niveau' => $niveau],
        ['montant_inscription' => 185000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000]);
    $auditeur = Auditeur::factory()->campagneCommune()->create();
    $auditeur->candidature->forceFill(['specialite' => $specialite])->save();
    $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id]);
    app(InscriptionsPedagogiques::class)->inscrire($i);
    foreach ($i->inscriptionsPedagogiques()->with('maquetteUe.ue', 'maquetteUe.ecues')->get() as $ip) {
        $note = array_key_exists($ip->maquetteUe->ue->code, $notes) ? $notes[$ip->maquetteUe->ue->code] : $defaut;
        if ($note === null) {
            continue;
        }
        foreach ($ip->maquetteUe->ecues as $ecue) {
            $v = number_format($note, 2, '.', '');
            (new NoteEcue(['inscription_pedagogique_id' => $ip->id, 'maquette_ecue_id' => $ecue->id, 'session' => 'normale']))
                ->forceFill(['cc' => $v, 'assiduite' => $v, 'examen' => $v, 'presence_examen' => 'present'])->save();
        }
    }

    return $i;
}

function verrouillerTout(): void
{
    foreach (MaquetteEcue::query()->pluck('id') as $id) {
        foreach (['cc', 'assiduite', 'examen'] as $etape) {
            EtapeSaisie::query()->updateOrCreate(['maquette_ecue_id' => $id, 'session' => 'normale', 'etape' => $etape], [])
                ->forceFill(['statut' => EtapeSaisie::VERROUILLEE, 'verrouillee_at' => now()])->save();
        }
    }
}

function deliberationM1(object $t, array $extra = []): Deliberation
{
    return $t->service->creer(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'session' => 'normale', 'jury_id' => $t->jury->id, ...$extra], $t->agent);
}

it('liste les préalables bloquants : jury absent, notes non verrouillées', function (): void {
    auditeurNote('M1', null, 14);
    $d = $this->service->creer(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'session' => 'normale'], $this->agent);

    $codes = array_map(fn ($a) => $a->code, $this->service->prealables($d));
    expect($codes)->toContain('jury_non_designe', 'etapes_non_verrouillees');

    verrouillerTout();
    $d->update(['jury_id' => $this->jury->id]);
    expect(array_filter($this->service->prealables($d->refresh()), fn ($a) => $a->estBloquante()))->toBe([]);
});

it('applique strictement les règles : AD à 60 crédits en M1, AJ sinon ; la note saisie reste intacte', function (): void {
    $a = auditeurNote('M1', null, 14);
    $b = auditeurNote('M1', null, 14, ['MPMPP73' => 11.6]);
    verrouillerTout();
    $d = deliberationM1($this);

    $r = $this->service->calculer($d->scenarios()->first(), $this->agent);

    expect($r)->toBe(['effectif' => 2, 'admis' => 1, 'autorises' => 0, 'ajournes' => 1]);
    $resultats = $d->scenarios()->first()->resultats()->get()->keyBy('inscription_annuelle_id');
    expect($resultats[$a->id]->credits_acquis)->toBe(60)
        ->and($resultats[$a->id]->rang)->toBe(1)
        ->and($resultats[$a->id]->mention)->toBe('Bien')
        ->and($resultats[$b->id]->credits_acquis)->toBe(51)
        ->and($resultats[$b->id]->decision)->toBe('AJ');
});

it('décide AS à 44 crédits écrits et AD avec le mémoire en M2', function (): void {
    $sansMemoire = auditeurNote('M2', 'Gouvernance Territoriale et Finances Publiques locales', 13, ['MPGTF102' => null]);
    $avecMemoire = auditeurNote('M2', 'Gouvernance Territoriale et Finances Publiques locales', 13);
    verrouillerTout();
    $jury = $this->jury->replicate()->fill(['niveau' => 'M2']);
    $jury->save();
    $d = $this->service->creer(['annee_academique' => '2026-2027', 'niveau' => 'M2', 'session' => 'normale', 'jury_id' => $jury->id], $this->agent);

    $this->service->calculer($d->scenarios()->first(), $this->agent);

    $decisions = $d->scenarios()->first()->resultats()->pluck('decision', 'inscription_annuelle_id');
    expect($decisions[$sansMemoire->id])->toBe('AS')->and($decisions[$avecMemoire->id])->toBe('AD');
});

it('borne les mesures du jury par les règles de l\'année', function (): void {
    auditeurNote('M1', null, 14);
    verrouillerTout();
    $s = deliberationM1($this)->scenarios()->first();

    expect(fn () => $this->service->ajouterMesure($s, MesureJury::RELEVEMENT, ['plage_min' => 11, 'plage_max' => 11.99, 'cible' => 12], 'Harmonisation des notes', $this->agent))
        ->toThrow(InvalidArgumentException::class, 'dépasse');
    expect(fn () => $this->service->ajouterMesure($s, MesureJury::REPECHAGE, ['credits_min' => 50, 'ecart_max' => 1.5], 'Repêchage de fin de cycle', $this->agent))
        ->toThrow(InvalidArgumentException::class, 'écart');
    expect(fn () => $this->service->ajouterMesure($s, MesureJury::RELEVEMENT, ['plage_min' => 11.5, 'plage_max' => 11.99, 'cible' => 12], 'court', $this->agent))
        ->toThrow(InvalidArgumentException::class, 'motif');
});

it('relève et repêche dans leurs limites, en traçant les bénéficiaires', function (): void {
    $releve = auditeurNote('M1', null, 14, ['MPMPP72' => 11.6]);
    $repeche = auditeurNote('M1', null, 14, ['MPMPP73' => 11.2]);
    verrouillerTout();
    $d = deliberationM1($this);
    $base = $d->scenarios()->first();
    $variante = $this->service->dupliquer($base, 'Avec mesures', $this->agent);
    $this->service->ajouterMesure($variante, MesureJury::RELEVEMENT, ['plage_min' => 11.5, 'plage_max' => 11.99, 'cible' => 12], 'Harmonisation des notes proches du seuil', $this->agent);
    $this->service->ajouterMesure($variante, MesureJury::REPECHAGE, ['credits_min' => 51, 'ecart_max' => 1, 'ue_max' => 1], 'Repêchage pour une UE à moins d\'un point', $this->agent);

    $this->service->calculer($base, $this->agent);
    $r = $this->service->calculer($variante, $this->agent);

    expect($base->refresh()->admis)->toBe(0)->and($r['admis'])->toBe(2);
    $resultats = $variante->resultats()->get()->keyBy('inscription_annuelle_id');
    expect($resultats[$releve->id]->decision_calculee)->toBe('AJ')
        ->and($resultats[$releve->id]->decision)->toBe('AD')
        ->and($resultats[$releve->id]->mesures)->toContain('Relèvement')
        ->and($resultats[$repeche->id]->mesures)->toContain('Repêchage')
        ->and($variante->mesures()->pluck('beneficiaires')->all())->toBe([1, 1])
        ->and((float) NoteEcue::query()->whereHas('inscriptionPedagogique', fn ($q) => $q->where('inscription_annuelle_id', $releve->id))->min('examen'))->toBe(11.6);
});

it('n\'arrête qu\'après la séance avec quorum, par le responsable, sur des notes inchangées, puis fige tout', function (): void {
    $i = auditeurNote('M1', null, 14);
    verrouillerTout();
    $d = deliberationM1($this);
    $s = $d->scenarios()->first();
    $this->service->calculer($s, $this->agent);

    expect(fn () => $this->service->arreter($d, $this->responsable))->toThrow(InvalidArgumentException::class, 'Séance');
    expect($this->service->ouvrirSeance($d, ($this->tous)(false), $this->agent))->toBeTrue();
    expect(fn () => $this->service->arreter($d->refresh(), $this->responsable))->toThrow(InvalidArgumentException::class, 'scénario retenu');
    $this->service->retenir($s, $this->agent);
    expect(fn () => $this->service->arreter($d->refresh(), $this->agent))->toThrow(HttpException::class);

    $this->travel(1)->seconds();
    NoteEcue::query()->first()->forceFill(['cc' => '15.00'])->save();
    expect(fn () => $this->service->arreter($d->refresh(), $this->responsable))->toThrow(InvalidArgumentException::class, 'recalculez');
    $this->travel(1)->seconds();
    $this->service->calculer($s->refresh(), $this->agent);

    expect($this->service->arreter($d->refresh(), $this->responsable))->toBe('PVD-2026-2027-0001');
    $d->refresh();
    expect($d->statut)->toBe(Deliberation::ARRETEE)
        ->and($d->decision_jury)->toBe('sans_deliberation')
        ->and($d->pv_sha256)->toHaveLength(64)
        ->and(ResultatAnnuel::query()->where('deliberation_id', $d->id)->value('decision'))->toBe('AD')
        ->and(AcquisUe::query()->where('auditeur_id', $i->auditeur_id)->sum('credits'))->toBe(60);
    expect(fn () => DB::transaction(fn () => DB::table('resultats_annuels')->update(['decision' => 'AJ'])))->toThrow(QueryException::class, 'figés');
    expect(fn () => $this->service->ajouterMesure($s, MesureJury::REPECHAGE, ['credits_min' => 1, 'ecart_max' => 1], 'Mesure tardive refusée', $this->agent))
        ->toThrow(InvalidArgumentException::class, 'arrêtée');
});

it('refuse la séance sans motif d\'absence et constate l\'absence de quorum sans le président', function (): void {
    auditeurNote('M1', null, 14);
    verrouillerTout();
    $d = deliberationM1($this);
    $presences = ($this->tous)();
    $president = $this->jury->membres()->where('role', 'president')->value('id');

    $presences[$president] = ['present' => false, 'motif_absence' => null];
    expect(fn () => $this->service->ouvrirSeance($d, $presences, $this->agent))->toThrow(InvalidArgumentException::class, 'motif');
    $presences[$president]['motif_absence'] = 'Empêché';
    expect($this->service->ouvrirSeance($d, $presences, $this->agent))->toBeFalse();
});

it('fait signer les présents seulement, puis publie avec la date limite des requêtes', function (): void {
    auditeurNote('M1', null, 14);
    verrouillerTout();
    $d = deliberationM1($this);
    $s = $d->scenarios()->first();
    $this->service->calculer($s, $this->agent);
    $this->service->ouvrirSeance($d, ($this->tous)(false), $this->agent);
    $this->service->retenir($s, $this->agent);
    $this->service->arreter($d->refresh(), $this->responsable);

    expect(fn () => $this->service->signer($d->refresh(), $this->membre))->toThrow(InvalidArgumentException::class, 'absent');
    expect(fn () => $this->service->publier($d, $this->responsable))->toThrow(InvalidArgumentException::class, 'non signé');
    $this->service->signer($d, $this->president);
    $this->service->signer($d, $this->rapporteur);

    $limite = $this->service->publier($d, $this->responsable);
    expect($limite->toDateString())->toBe(now('Africa/Douala')->startOfDay()->addDays(10)->toDateString())
        ->and($d->refresh()->publiee_at)->not->toBeNull();
});

it('rectifie par une délibération liée qui remplace les acquis à son arrêt', function (): void {
    $i = auditeurNote('M1', null, 14, ['MPMPP73' => 11.6]);
    verrouillerTout();
    $d = deliberationM1($this);
    $s = $d->scenarios()->first();
    $this->service->calculer($s, $this->agent);
    $this->service->ouvrirSeance($d, ($this->tous)(), $this->agent);
    $this->service->retenir($s, $this->agent);
    $this->service->arreter($d->refresh(), $this->responsable);
    expect(AcquisUe::query()->where('auditeur_id', $i->auditeur_id)->sum('credits'))->toBe(51);

    expect(fn () => $this->service->rectifier($d->refresh(), 'Erreur', '', $this->responsable))->toThrow(InvalidArgumentException::class);
    $r = $this->service->rectifier($d->refresh(), 'Requête fondée sur la note de MPMPP73', 'NS 26/014', $this->responsable);
    $nouveau = $r->scenarios()->first();
    $this->service->ajouterMesure($nouveau, MesureJury::CAS_PARTICULIER,
        ['inscription_annuelle_id' => $i->id, 'maquette_ue_id' => MaquetteUe::query()->whereHas('ue', fn ($q) => $q->where('code', 'MPMPP73'))->value('id'), 'note' => 12],
        'Requête fondée : erreur de report constatée sur la copie d\'examen', $this->agent);
    $this->service->calculer($nouveau, $this->agent);
    $this->service->ouvrirSeance($r, ($this->tous)(), $this->agent);
    $this->service->retenir($nouveau->refresh(), $this->agent);

    expect($this->service->arreter($r->refresh(), $this->responsable))->toBe('PVD-2026-2027-0002');
    expect($d->refresh()->statut)->toBe(Deliberation::RECTIFIEE)
        ->and($this->service->ecartsRectification($r->refresh()))->toBe(['favorables' => 1, 'defavorables' => 0])
        ->and(AcquisUe::query()->where('auditeur_id', $i->auditeur_id)->sum('credits'))->toBe(60)
        ->and(AcquisUe::query()->where('deliberation_id', $d->id)->count())->toBe(0);
});

it('compte une UE capitalisée une année antérieure', function (): void {
    $i = auditeurNote('M1', null, 14, ['MPMPP73' => null]);
    $ue = MaquetteUe::query()->whereHas('ue', fn ($q) => $q->where('code', 'MPMPP73'))->firstOrFail();
    AcquisUe::query()->create(['auditeur_id' => $i->auditeur_id, 'unite_enseignement_id' => $ue->unite_enseignement_id, 'maquette_ue_id' => $ue->id,
        'niveau' => 'M1', 'credits' => 9, 'note_retenue' => '13.000', 'annee_academique' => '2025-2026', 'session' => 'normale', 'origine' => 'reprise']);
    verrouillerTout();
    $s = deliberationM1($this)->scenarios()->first();

    $this->service->calculer($s, $this->agent);

    $r = $s->resultats()->sole();
    expect($r->credits_acquis)->toBe(60)->and($r->credits_annee)->toBe(51)->and($r->decision)->toBe('AD');
});

it('produit le PV en PDF et en garde l\'empreinte une fois arrêté', function (): void {
    auditeurNote('M1', null, 14);
    verrouillerTout();
    $d = deliberationM1($this);
    $s = $d->scenarios()->first();
    $this->service->calculer($s, $this->agent);

    expect(app(PvDeliberation::class)->pv($d))->toStartWith('%PDF');
    expect($d->refresh()->pv_pdf_sha256)->toBeNull();

    $this->service->ouvrirSeance($d, ($this->tous)(), $this->agent);
    $this->service->retenir($s, $this->agent);
    $this->service->arreter($d->refresh(), $this->responsable);
    $pdf = app(PvDeliberation::class)->pv($d->refresh());
    expect($d->refresh()->pv_pdf_sha256)->toBe(hash('sha256', $pdf))
        ->and(app(PvDeliberation::class)->affichage($d))->toStartWith('%PDF');
});

it('ouvre les délibérations à la scolarité et aux membres de jury, et réserve l\'arrêt au responsable', function (): void {
    auditeurNote('M1', null, 14);
    verrouillerTout();
    $d = deliberationM1($this);

    $this->actingAs(($this->compte)('admission_committee'))->get(DeliberationResource::getUrl('index'))->assertForbidden();
    $this->actingAs($this->president)->get(DeliberationResource::getUrl('view', ['record' => $d]))->assertOk();

    $this->actingAs($this->agent);
    Livewire::test(ViewDeliberation::class, ['record' => $d->getRouteKey()])
        ->assertActionHidden('arreter')
        ->callAction('calculer', ['scenario' => $d->scenarios()->value('id')])
        ->assertHasNoActionErrors();
    expect($d->scenarios()->first()->admis)->toBe(1);
});
