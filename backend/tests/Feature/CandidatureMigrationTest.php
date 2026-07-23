<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the pays reference table', function (): void {
    expect(Schema::hasTable('pays'))->toBeTrue();
    expect(Schema::hasColumns('pays', ['code_iso', 'nom', 'indicatif']))->toBeTrue();
});

it('creates the regions_cameroun table with quota_admission', function (): void {
    expect(Schema::hasTable('regions_cameroun'))->toBeTrue();
    expect(Schema::hasColumns('regions_cameroun', ['code', 'nom', 'quota_admission', 'chef_lieu', 'order']))
        ->toBeTrue();
});

it('creates the departements_cameroun table with FK on regions', function (): void {
    expect(Schema::hasTable('departements_cameroun'))->toBeTrue();
    expect(Schema::hasColumns('departements_cameroun', ['code', 'nom', 'chef_lieu', 'region_code']))
        ->toBeTrue();
});

it('creates the campagnes_candidature table with status check', function (): void {
    expect(Schema::hasTable('campagnes_candidature'))->toBeTrue();
    expect(Schema::hasColumns('campagnes_candidature', [
        'id', 'slug', 'prefix_numero', 'nom', 'promotion_numero',
        'opens_at', 'closes_at', 'results_at', 'status', 'max_voeux',
    ]))->toBeTrue();
});

it('creates the candidatures table with all profile columns', function (): void {
    expect(Schema::hasTable('candidatures'))->toBeTrue();
    expect(Schema::hasColumns('candidatures', [
        'id', 'uuid', 'numero_dossier', 'campagne_id', 'user_id',
        'phone_e164', 'phone_country', 'email',
        'civilite', 'nom', 'prenom', 'epouse', 'date_naissance', 'lieu_naissance',
        'genre', 'statut_matrimonial',
        'nationalite', 'pays_origine', 'pays_residence', 'region', 'departement',
        'adresse', 'ville_residence',
        'indicatif1', 'telephone1', 'indicatif2', 'telephone2',
        'specialite', 'second_choix', 'type_etude', 'premiere_langue',
        'diplome_obtenu', 'institut', 'specialite_diplome', 'annee_diplome',
        'statut_actuel', 'fonction_actuelle', 'employeur', 'adresse_employeur', 'tel_employeur',
        'engagement_nom', 'moyen_connaissance', 'moyen_connaissance_detail', 'photo_path',
        'statut', 'submitted_at', 'reviewed_at', 'decided_at', 'withdrawn_at',
        'frais_paye', 'mode_paiement', 'reference_paiement', 'date_paiement',
        'recipisse_pdf_path', 'recipisse_hash_sha256',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('enforces UNIQUE on (campagne_id, phone_e164) — not on phone_e164 globally', function (): void {
    $constraints = DB::select(
        "SELECT conname FROM pg_constraint WHERE conrelid = 'candidatures'::regclass AND contype = 'u'"
    );
    $names = array_map(fn ($c) => $c->conname, $constraints);

    expect($names)->toContain('candidatures_campagne_phone_unique');
    expect($names)->toContain('candidatures_numero_dossier_unique');
    expect($names)->not->toContain('candidatures_phone_e164_unique');
});

it('enforces statut CHECK constraint on candidatures', function (): void {
    $constraints = DB::select(
        "SELECT conname FROM pg_constraint WHERE conrelid = 'candidatures'::regclass AND contype = 'c'"
    );
    $names = array_map(fn ($c) => $c->conname, $constraints);

    expect($names)->toContain('candidatures_statut_check');
    expect($names)->toContain('candidatures_type_etude_check');
    expect($names)->toContain('candidatures_premiere_langue_check');
});

it('configures region/departement FK with ON DELETE SET NULL and nationalite with RESTRICT', function (): void {
    $rows = DB::select(<<<'SQL'
        SELECT conname, confdeltype
        FROM pg_constraint
        WHERE conrelid = 'candidatures'::regclass
          AND contype = 'f'
    SQL);

    $byName = collect($rows)->keyBy('conname')->all();

    // confdeltype : 'a' = NO ACTION, 'r' = RESTRICT, 'c' = CASCADE, 'n' = SET NULL, 'd' = SET DEFAULT
    $regionFk = collect($byName)->first(fn ($r) => str_contains($r->conname, 'region') && ! str_contains($r->conname, 'pays'));
    $deptFk = collect($byName)->first(fn ($r) => str_contains($r->conname, 'departement'));
    $natFk = collect($byName)->first(fn ($r) => str_contains($r->conname, 'nationalite'));

    expect($regionFk)->not->toBeNull();
    expect($regionFk->confdeltype)->toBe('n'); // SET NULL
    expect($deptFk->confdeltype)->toBe('n'); // SET NULL
    expect($natFk->confdeltype)->toBe('r'); // RESTRICT
});
