<?php

declare(strict_types=1);

use App\Filament\Resources\InscriptionResource;
use App\Filament\Resources\InscriptionResource\Pages\ListInscriptions;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Deliberation;
use App\Models\InscriptionAnnuelle;
use App\Models\Parcours;
use App\Models\ResultatAnnuel;
use App\Models\User;
use App\Services\Scolarite\AnneeEnCours;
use App\Services\Scolarite\PassageEnGroupe;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('scolarite');

/* Lot 18 : passage en groupe du M1 au M2, écran « Inscriptions », changement de l'année en cours. Jeu fictif. */

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('scolarite.annee_en_cours', '2026-2027');
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->p13 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2025-2026'], 'promotion_numero' => 13, 'slug' => 'p13-2025', 'prefix_numero' => 'P13025-']);
    $m1 = BaremeScolarite::factory()->create(['annee_academique' => '2025-2026']);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027']);
    BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']);
    Parcours::query()->create(['code' => 'TC', 'libelle' => 'Tronc commun', 'niveau' => 'M1', 'actif' => true, 'ordre' => 1]);
    Parcours::query()->create(['code' => 'ACFP', 'libelle' => 'Audit et contrôle', 'niveau' => 'M2', 'specialite' => 'Audit et contrôle des finances publiques', 'actif' => true, 'ordre' => 2]);
    $this->agent = ($this->compte)('service_financier');
    $this->responsable = ($this->compte)('scolarite_responsable');

    $this->deliberation = Deliberation::query()->create(['annee_academique' => '2025-2026', 'niveau' => 'M1', 'session' => 'normale']);
    $this->deliberation->forceFill(['statut' => Deliberation::ARRETEE, 'numero_pv' => 'PVD-2025-2026-0001', 'pv_sha256' => str_repeat('a', 64), 'arretee_at' => now()])->save();
    /** Auditeur de la P13, en M1 en 2025-2026, avec la décision de la délibération annuelle. */
    $this->m1 = function (string $decision, ?string $specialite = 'Audit et contrôle des finances publiques') use ($m1): Auditeur {
        $c = Candidature::factory()->forCampagne($this->p13)->submitted()->create([
            'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'email' => Str::random(8).'@example.org',
            'specialite' => $specialite,
        ]);
        $auditeur = Auditeur::query()->create(['candidature_id' => $c->id]);
        $inscription = InscriptionAnnuelle::query()->create([
            'auditeur_id' => $auditeur->id, 'bareme_id' => $m1->id, 'annee_academique' => '2025-2026', 'niveau' => 'M1',
            'montant_inscription' => $m1->montant_inscription, 'montant_tranche1' => $m1->montant_tranche1, 'montant_tranche2' => $m1->montant_tranche2,
        ]);
        ResultatAnnuel::query()->create(['deliberation_id' => $this->deliberation->id, 'inscription_annuelle_id' => $inscription->id,
            'credits_acquis' => $decision === 'AD' ? 60 : 40, 'credits_annee' => 60, 'decision_calculee' => $decision, 'decision' => $decision]);

        return $auditeur;
    };
    $this->passage = app(PassageEnGroupe::class);
});

it('inscrit en M2 les admis du M1 de l\'année précédente, dans l\'option de leur spécialité, et écarte les autres', function (): void {
    $admis = ($this->m1)('AD');
    $sansOption = ($this->m1)('AD', null);
    $ajourne = ($this->m1)('AJ');
    $abandon = ($this->m1)('AD');
    $abandon->forceFill(['statut' => Auditeur::STATUT_ABANDON])->save();

    $apercu = $this->passage->apercu();
    expect($apercu['precedente'])->toBe('2025-2026')
        ->and(collect($apercu['a_inscrire'])->pluck('auditeur.id')->all())->toBe([$admis->id])
        ->and(collect($apercu['sans_option'])->pluck('id')->all())->toBe([$sansOption->id])
        ->and($apercu['inactifs'])->toBe(1)
        ->and(InscriptionAnnuelle::query()->where('annee_academique', '2026-2027')->exists())->toBeFalse();

    $bilan = $this->passage->executer($this->agent);

    $m2 = InscriptionAnnuelle::query()->where('annee_academique', '2026-2027')->sole();
    expect($bilan)->toBe(['inscrits' => 1, 'sans_option' => 1, 'echecs' => []])
        ->and($m2->auditeur_id)->toBe($admis->id)->and($m2->niveau)->toBe('M2')->and($m2->parcours->code)->toBe('ACFP')
        ->and(InscriptionAnnuelle::query()->where('auditeur_id', $ajourne->id)->count())->toBe(1)
        ->and(Activity::query()->where('event', PassageEnGroupe::EVENEMENT)->sole()->properties['inscrits'])->toBe(1);

    // Rejouable : l'admis déjà inscrit est écarté, rien n'est créé en double.
    expect($this->passage->executer($this->agent)['inscrits'])->toBe(0)
        ->and($this->passage->apercu()['deja_inscrits'])->toBe(1)
        ->and(InscriptionAnnuelle::query()->where('annee_academique', '2026-2027')->count())->toBe(1);
});

it('refuse le passage en groupe à un compte sans le droit de réinscrire', function (): void {
    ($this->m1)('AD');

    expect(fn () => $this->passage->executer(($this->compte)('enseignant')))->toThrow(HttpException::class);
    expect(InscriptionAnnuelle::query()->where('annee_academique', '2026-2027')->exists())->toBeFalse();
});

it('change l\'année en cours d\'un an, avec son barème, et le journalise', function (): void {
    expect(fn () => AnneeEnCours::changer('2027-2028', $this->agent))->toThrow(HttpException::class)
        ->and(fn () => AnneeEnCours::changer('2027-2028', $this->responsable))->toThrow(InvalidArgumentException::class, 'Aucun barème')
        ->and(fn () => AnneeEnCours::changer('2028-2029', $this->responsable))->toThrow(InvalidArgumentException::class, 'un an à la fois')
        ->and(fn () => AnneeEnCours::changer('2026-2027', $this->responsable))->toThrow(InvalidArgumentException::class, 'déjà');

    BaremeScolarite::factory()->create(['annee_academique' => '2027-2028']);
    AnneeEnCours::changer('2027-2028', $this->responsable);

    $journal = Activity::query()->where('event', 'annee_en_cours_changee')->sole();
    expect(AnneeEnCours::annee())->toBe('2027-2028')
        ->and($journal->properties['avant'])->toBe('2026-2027')->and($journal->causer_id)->toBe($this->responsable->id);
});

it('montre l\'écran « Inscriptions » à la scolarité, avec l\'année en cours par défaut, pas à un enseignant', function (): void {
    $admis = ($this->m1)('AD');
    $this->passage->executer($this->agent);

    $this->actingAs(($this->compte)('enseignant'))->get(InscriptionResource::getUrl())->assertForbidden();
    $this->actingAs($this->responsable)->get(InscriptionResource::getUrl())->assertOk();

    $m1 = InscriptionAnnuelle::query()->where('annee_academique', '2025-2026')->sole();
    $m2 = InscriptionAnnuelle::query()->where('annee_academique', '2026-2027')->sole();
    Livewire::test(ListInscriptions::class)
        ->assertCanSeeTableRecords([$m2])->assertCanNotSeeTableRecords([$m1])
        ->assertActionVisible('changerAnnee')->assertActionVisible('passerEnM2')
        ->removeTableFilter('annee_academique')->assertCanSeeTableRecords([$m1, $m2]);

    $this->actingAs($this->agent);
    Livewire::test(ListInscriptions::class)->assertActionVisible('passerEnM2')->assertActionHidden('changerAnnee');
    expect($admis->fresh()->inscriptionCourante->niveau)->toBe('M2');
});

it('refuse le passage sans barème de M2, et le retour à l\'année précédente dès qu\'un auditeur est inscrit', function (): void {
    ($this->m1)('AD');
    BaremeScolarite::query()->where('annee_academique', '2026-2027')->where('niveau', 'M2')->delete();
    expect(fn () => $this->passage->apercu())->toThrow(InvalidArgumentException::class, 'Aucun barème de M2');

    BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']);
    $this->passage->executer($this->agent);
    expect(fn () => AnneeEnCours::changer('2025-2026', $this->responsable))->toThrow(InvalidArgumentException::class, 'déjà inscrits en 2026-2027');
});
