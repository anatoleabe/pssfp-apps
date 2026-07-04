<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use Database\Seeders\ProdCampagneP14Seeder;

uses()->group('applications', 'seeders');

it('refuses to run without the campaign dates in env', function (): void {
    config()->set('pssfp.campagne_p14.opens_at', null);
    config()->set('pssfp.campagne_p14.closes_at', null);
    config()->set('pssfp.campagne_p14.results_at', null);

    expect(fn () => $this->seed(ProdCampagneP14Seeder::class))
        ->toThrow(RuntimeException::class, 'CAMPAGNE_P14_OPENS_AT');
});

it('refuses incoherent dates (closes before opens)', function (): void {
    config()->set('pssfp.campagne_p14.opens_at', '2026-09-01 00:00:00');
    config()->set('pssfp.campagne_p14.closes_at', '2026-08-01 00:00:00');
    config()->set('pssfp.campagne_p14.results_at', '2026-10-01 00:00:00');

    expect(fn () => $this->seed(ProdCampagneP14Seeder::class))
        ->toThrow(RuntimeException::class, 'incohérentes');
});

it('creates the open P14 campaign and is idempotent on replay', function (): void {
    config()->set('pssfp.campagne_p14.opens_at', '2026-07-10 00:00:00');
    config()->set('pssfp.campagne_p14.closes_at', '2026-08-31 23:59:59');
    config()->set('pssfp.campagne_p14.results_at', '2026-09-30 23:59:59');

    $this->seed(ProdCampagneP14Seeder::class);
    $this->seed(ProdCampagneP14Seeder::class); // rejouable sans doublon

    $campagnes = CampagneCandidature::query()->where('slug', 'p14-2026')->get();
    expect($campagnes)->toHaveCount(1);

    $c = $campagnes->first();
    expect($c->status)->toBe('open')
        ->and($c->prefix_numero)->toBe('P14026-')
        ->and($c->promotion_numero)->toBe(14)
        ->and($c->max_voeux)->toBe(1);
});

it('updates the dates on replay when env changes (prolongation de campagne)', function (): void {
    config()->set('pssfp.campagne_p14.opens_at', '2026-07-10 00:00:00');
    config()->set('pssfp.campagne_p14.closes_at', '2026-08-31 23:59:59');
    config()->set('pssfp.campagne_p14.results_at', '2026-09-30 23:59:59');
    $this->seed(ProdCampagneP14Seeder::class);

    config()->set('pssfp.campagne_p14.closes_at', '2026-09-15 23:59:59');
    $this->seed(ProdCampagneP14Seeder::class);

    $c = CampagneCandidature::query()->where('slug', 'p14-2026')->firstOrFail();
    expect($c->closes_at->toDateTimeString())->toBe('2026-09-15 23:59:59');
});
