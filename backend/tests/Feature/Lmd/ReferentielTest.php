<?php

declare(strict_types=1);

use App\Models\InscriptionAnnuelle;
use App\Models\InscriptionPedagogique;
use App\Models\Maquette;
use App\Models\MaquetteUe;
use App\Models\Parcours;
use App\Models\RegleEvaluation;
use App\Models\User;
use App\Services\Lmd\ControleMaquette;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\ReconductionMaquette;
use App\Services\Lmd\ValidationMaquette;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->document = json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true);
    $this->responsable = tap(User::factory()->create(['email' => 'resp-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('scolarite_responsable'));
    $this->agent = tap(User::factory()->create(['email' => 'agent-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('scolarite_agent'));
});

function importer2026(bool $appliquer = true): array
{
    $document = json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true);

    return app(ImportMaquettes::class)->importer($document, $appliquer);
}

it('n\'écrit rien en essai à blanc', function (): void {
    $bilan = importer2026(false);

    expect($bilan['creees'])->toBe(['TC', 'FFCP', 'GTFP', 'ACFP', 'EPGP', 'MPP'])
        ->and(Maquette::query()->count())->toBe(0)
        ->and(Parcours::query()->count())->toBe(0);
});

it('importe les six maquettes 2026-2027 en brouillon, de 60 crédits, sans anomalie bloquante', function (): void {
    importer2026();

    $maquettes = Maquette::query()->with('parcours')->get();
    expect($maquettes)->toHaveCount(6)
        ->and($maquettes->pluck('statut')->unique()->all())->toBe([Maquette::BROUILLON]);
    foreach ($maquettes as $m) {
        expect($m->totalCredits())->toBe(60)
            ->and(app(ControleMaquette::class)->bloquantes($m))->toBe([]);
    }
    // Pondérations des règles de l'année, mémoire du M2 en UE de soutenance de 16 crédits.
    $ffcp = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'FFCP'))->firstOrFail();
    $memoire = $ffcp->ues()->where('partie', MaquetteUe::SOUTENANCE)->with('ue', 'ecues')->sole();
    expect($memoire->credits)->toBe(16)
        ->and($memoire->ue->code)->toBe('MPFFC102')
        ->and($memoire->ecues->pluck('code')->all())->toBe(['MPFFC1016'])
        ->and((float) $memoire->pexam)->toBe(0.55)
        ->and(Parcours::query()->where('code', 'MPP')->value('specialite'))->toBe('Marchés Publics et Partenariats Public-Privés')
        ->and(RegleEvaluation::query()->where('annee_academique', '2026-2027')->value('mode_rattrapage'))->toBe(RegleEvaluation::RATTRAPAGE_REMPLACE_MOYENNE);
});

it('remplace un brouillon à l\'import suivant mais ne touche jamais une maquette validée', function (): void {
    importer2026();
    $tc = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'TC'))->firstOrFail();
    app(ValidationMaquette::class)->valider($tc, $this->responsable);
    $ues = $tc->ues()->pluck('id')->all();

    $bilan = importer2026();

    expect($bilan['ignorees'])->toBe(['TC' => 'maquette déjà validée'])
        ->and($bilan['remplacees'])->toHaveCount(5)
        ->and($tc->ues()->pluck('id')->all())->toBe($ues)
        ->and(Maquette::query()->count())->toBe(6);
});

it('signale les contrôles bloquants et refuse alors la validation', function (): void {
    importer2026();
    $m = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'GTFP'))->firstOrFail();
    $ligne = $m->ues()->firstOrFail();
    $ligne->update(['credits' => $ligne->credits + 1]);
    $m->ues()->where('partie', MaquetteUe::SOUTENANCE)->update(['partie' => MaquetteUe::ECRITE]);

    $codes = array_map(fn ($a): string => $a->code, app(ControleMaquette::class)->bloquantes($m->refresh()));
    expect($codes)->toContain('credits_ecue', 'sans_soutenance');

    expect(fn () => app(ValidationMaquette::class)->valider($m, $this->responsable))
        ->toThrow(InvalidArgumentException::class, 'Validation impossible');
    expect($m->refresh()->statut)->toBe(Maquette::BROUILLON);
});

it('refuse en base une pondération qui ne fait pas 100 %', function (): void {
    importer2026();
    $ligne = MaquetteUe::query()->firstOrFail();

    expect(fn () => DB::table('maquette_ues')->where('id', $ligne->id)->update(['pexam' => 0.60]))->toThrow(QueryException::class);
});

it('réserve la validation au titulaire de lmd.maquette_valider et la journalise', function (): void {
    importer2026();
    $tc = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'TC'))->firstOrFail();

    expect(fn () => app(ValidationMaquette::class)->valider($tc, $this->agent))->toThrow(HttpException::class);

    app(ValidationMaquette::class)->valider($tc, $this->responsable);
    $tc->refresh();
    expect($tc->statut)->toBe(Maquette::VALIDEE)
        ->and($tc->validee_par)->toBe($this->responsable->id)
        ->and($this->responsable->can('update', $tc))->toBeFalse()
        ->and(Activity::query()->where('event', 'maquette_validee')->where('subject_id', $tc->id)->exists())->toBeTrue();
});

it('rouvre une maquette validée avec un motif, tant qu\'aucun auditeur n\'y est inscrit', function (): void {
    importer2026();
    $tc = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'TC'))->firstOrFail();
    app(ValidationMaquette::class)->valider($tc, $this->responsable);

    expect(fn () => app(ValidationMaquette::class)->rouvrir($tc, $this->responsable, 'court'))->toThrow(InvalidArgumentException::class, 'motif');

    $inscription = InscriptionAnnuelle::factory()->create();
    InscriptionPedagogique::query()->create(['inscription_annuelle_id' => $inscription->id, 'maquette_ue_id' => $tc->ues()->value('id')]);
    expect(fn () => app(ValidationMaquette::class)->rouvrir($tc, $this->responsable, 'Correction d\'un intitulé d\'ECUE'))
        ->toThrow(InvalidArgumentException::class, 'inscrits');

    InscriptionPedagogique::query()->delete();
    app(ValidationMaquette::class)->rouvrir($tc, $this->responsable, 'Correction d\'un intitulé d\'ECUE');
    expect($tc->refresh()->statut)->toBe(Maquette::BROUILLON)->and($tc->validee_at)->toBeNull();
});

it('reconduit une maquette sur l\'année suivante, en brouillon, avec ses UE et ECUE', function (): void {
    importer2026();
    $tc = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'TC'))->with('ecues')->firstOrFail();

    $copie = app(ReconductionMaquette::class)->reconduire($tc, '2027-2028', $this->agent);

    expect($copie->statut)->toBe(Maquette::BROUILLON)
        ->and($copie->totalCredits())->toBe(60)
        ->and($copie->ecues()->count())->toBe($tc->ecues->count());
    expect(fn () => app(ReconductionMaquette::class)->reconduire($tc, '2027-2028', $this->agent))
        ->toThrow(InvalidArgumentException::class, 'déjà une maquette');
});

it('journalise chaque modification des règles d\'évaluation', function (): void {
    $regle = RegleEvaluation::pourAnnee('2026-2027');
    $regle->update(['mode_rattrapage' => RegleEvaluation::RATTRAPAGE_REMPLACE_EXAMEN]);

    $entree = Activity::query()->where('subject_type', $regle->getMorphClass())->where('event', 'updated')->latest('id')->firstOrFail();
    expect($entree->properties['old']['mode_rattrapage'])->toBe(RegleEvaluation::RATTRAPAGE_REMPLACE_MOYENNE)
        ->and($entree->properties['attributes']['mode_rattrapage'])->toBe(RegleEvaluation::RATTRAPAGE_REMPLACE_EXAMEN)
        ->and($regle->grade(12.0)['grade'])->toBe('B-')
        ->and($regle->grade(11.99)['points'])->toBe(2.3);
});
