<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use App\Models\InscriptionPedagogique;
use App\Models\Maquette;
use App\Models\Parcours;
use App\Models\User;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\ValidationMaquette;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    $this->responsable = tap(User::factory()->create(['email' => 'resp-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('scolarite_responsable'));
    $this->valider = fn (string $code) => app(ValidationMaquette::class)->valider(
        Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', $code))->firstOrFail(), $this->responsable);
});

/** Inscription 2026-2027 d'un auditeur de niveau donné, avec la spécialité de son dossier. */
function inscriptionLmd(string $niveau, ?string $specialite = null): InscriptionAnnuelle
{
    $bareme = BaremeScolarite::query()->firstOrCreate(['annee_academique' => '2026-2027', 'niveau' => $niveau],
        ['montant_inscription' => $niveau === 'M1' ? 185000 : 135000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000]);
    $auditeur = Auditeur::factory()->create();
    $auditeur->candidature->forceFill(['specialite' => $specialite])->save();

    return InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id]);
}

it('inscrit un auditeur de M1 à toutes les UE du tronc commun, sans doublon quand on rejoue', function (): void {
    ($this->valider)('TC');
    $inscription = inscriptionLmd('M1');

    $r = app(InscriptionsPedagogiques::class)->inscrire($inscription);
    expect($r)->toBe(['issue' => InscriptionsPedagogiques::INSCRITE, 'parcours' => 'TC', 'ues' => 6])
        ->and($inscription->refresh()->parcours?->code)->toBe('TC');

    expect(app(InscriptionsPedagogiques::class)->inscrire($inscription)['ues'])->toBe(0)
        ->and($inscription->inscriptionsPedagogiques()->count())->toBe(6);
});

it('n\'inscrit pas sur une maquette encore en brouillon', function (): void {
    $r = app(InscriptionsPedagogiques::class)->inscrire(inscriptionLmd('M1'));

    expect($r['issue'])->toBe(InscriptionsPedagogiques::SANS_MAQUETTE);
});

it('inscrit un auditeur de M2 sur l\'option de sa spécialité, accents et casse ignorés', function (): void {
    ($this->valider)('MPP');
    $inscription = inscriptionLmd('M2', 'marches publics et partenariats public-prives');

    $r = app(InscriptionsPedagogiques::class)->inscrire($inscription);

    expect($r['parcours'])->toBe('MPP')->and($r['ues'])->toBe(5);
});

it('signale un auditeur de M2 sans spécialité reconnue, sans l\'inscrire', function (): void {
    ($this->valider)('FFCP');
    $inscription = inscriptionLmd('M2', 'Finances Publiques');

    expect(app(InscriptionsPedagogiques::class)->inscrire($inscription)['issue'])->toBe(InscriptionsPedagogiques::SANS_PARCOURS)
        ->and($inscription->refresh()->parcours_id)->toBeNull();
});

it('inscrit les auditeurs de l\'année à la validation de la maquette de leur parcours', function (): void {
    $a = inscriptionLmd('M2', 'Audit et contrôle des Finances Publiques');
    $b = inscriptionLmd('M2', 'Économie Publique et Gestion Publique');
    $maquette = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'ACFP'))->firstOrFail();
    app(ValidationMaquette::class)->valider($maquette, $this->responsable);

    $bilan = app(InscriptionsPedagogiques::class)->inscrireMaquette($maquette->refresh());

    expect($bilan['inscrites'])->toBe(1)
        ->and($a->inscriptionsPedagogiques()->count())->toBe(5)
        ->and($b->inscriptionsPedagogiques()->count())->toBe(0);
});

it('change le parcours avec un motif et la permission, et refait l\'inscription pédagogique', function (): void {
    ($this->valider)('FFCP');
    ($this->valider)('GTFP');
    $inscription = inscriptionLmd('M2', 'Fiscalité, Finance et Comptabilité Publique');
    app(InscriptionsPedagogiques::class)->inscrire($inscription);
    $gtfp = Parcours::query()->where('code', 'GTFP')->firstOrFail();
    $agent = tap(User::factory()->create(['email' => 'ag-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('scolarite_agent'));

    expect(fn () => app(InscriptionsPedagogiques::class)->changerParcours($inscription, $gtfp, $agent, 'Demande de l\'auditeur'))
        ->toThrow(HttpException::class);
    expect(fn () => app(InscriptionsPedagogiques::class)->changerParcours($inscription, Parcours::query()->where('code', 'TC')->firstOrFail(), $this->responsable, 'Demande de l\'auditeur'))
        ->toThrow(InvalidArgumentException::class, 'niveau');

    $r = app(InscriptionsPedagogiques::class)->changerParcours($inscription, $gtfp, $this->responsable, 'Demande écrite de l\'auditeur du 7/11');

    expect($r['parcours'])->toBe('GTFP')->and($r['ues'])->toBe(5)
        ->and($inscription->refresh()->parcours->code)->toBe('GTFP')
        ->and($inscription->inscriptionsPedagogiques()->count())->toBe(5);
});

it('rend compte par matricules seulement en essai à blanc de la commande', function (): void {
    ($this->valider)('TC');
    inscriptionLmd('M1');
    $sans = inscriptionLmd('M2', 'Inconnue');

    $this->artisan('lmd:inscrire')
        ->expectsOutputToContain('inscriptions à faire : 1')
        ->expectsOutputToContain('sans parcours reconnu : 1 ('.$sans->auditeur->candidature->matricule.')')
        ->assertSuccessful();
    expect(InscriptionPedagogique::query()->count())->toBe(0);

    $this->artisan('lmd:inscrire --appliquer')->expectsOutputToContain('inscriptions faites : 1 (6 UE)')->assertSuccessful();
});
