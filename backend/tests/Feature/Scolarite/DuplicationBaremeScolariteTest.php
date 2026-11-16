<?php

declare(strict_types=1);

use App\Filament\Resources\BaremeScolariteResource\Pages\ListBaremesScolarite;
use App\Models\BaremeScolarite;
use App\Models\User;
use App\Services\Scolarite\DuplicationBaremeScolarite;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed(RolePermissionSeeder::class);
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 16)->setTime(9, 0));
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Essai '.$role]), fn (User $u) => $u->assignRole($role));
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->m1 = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'montant_inscription' => 185000,
        'inscription_echeance' => 'Avant la rentrée', 'tranche1_echeance' => '2027-01-31', 'tranche2_echeance' => '2027-04-30']);
    $this->service = app(DuplicationBaremeScolarite::class);
});

it('propose l\'autre niveau de la même année, puis l\'année suivante du même niveau', function (): void {
    expect($this->service->proposition($this->m1))->toMatchArray([
        'annee_academique' => '2026-2027', 'niveau' => 'M2', 'montant_inscription' => 185000, 'montant_tranche1' => 500000,
        'inscription_echeance' => 'Avant la rentrée', 'tranche1_echeance' => '2027-01-31', 'tranche2_echeance' => '2027-04-30',
    ]);

    BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']);
    BaremeScolarite::factory()->create(['annee_academique' => '2027-2028', 'niveau' => 'M1']);
    expect($this->service->proposition($this->m1))->toMatchArray([
        'annee_academique' => '2028-2029', 'niveau' => 'M1', 'tranche1_echeance' => '2029-01-31', 'tranche2_echeance' => '2029-04-30',
        'inscription_echeance' => 'Avant la rentrée',
    ]);
});

it('ne propose pas l\'autre niveau d\'une année passée, ni une échéance datée d\'une autre année', function (): void {
    $ancien = BaremeScolarite::factory()->m2()->create(['annee_academique' => '2025-2026', 'inscription_echeance' => 'Avant le 31 octobre 2025']);

    expect($this->service->proposition($ancien))->toMatchArray(['annee_academique' => '2026-2027', 'niveau' => 'M2', 'inscription_echeance' => null]);
});

it('crée le M2 depuis le M1 avec les montants ajustés dans la fenêtre', function (): void {
    $this->actingAs($this->responsable);
    $this->livewire(ListBaremesScolarite::class)
        ->callTableAction('dupliquer', $this->m1, ['montant_inscription' => 135000])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Barème Master 2 2026-2027 créé.');

    $m2 = BaremeScolarite::query()->where('annee_academique', '2026-2027')->where('niveau', 'M2')->sole();
    expect($m2->montant_inscription)->toBe(135000)->and($m2->montant_tranche1)->toBe(500000)
        ->and($m2->tranche2_echeance->toDateString())->toBe('2027-04-30')
        ->and($this->m1->refresh()->montant_inscription)->toBe(185000);
    $this->assertDatabaseHas('activity_log', ['event' => 'bareme_duplique', 'subject_id' => $m2->id, 'causer_id' => $this->responsable->id]);
});

it('refuse un couple année-niveau qui a déjà un barème', function (): void {
    BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']);
    $this->actingAs($this->responsable);
    $this->livewire(ListBaremesScolarite::class)
        ->callTableAction('dupliquer', $this->m1, ['annee_academique' => '2026-2027', 'niveau' => 'M1'])
        ->assertHasTableActionErrors(['annee_academique' => 'unique']);

    expect(fn () => $this->service->dupliquer($this->m1, ['annee_academique' => '2026-2027', 'niveau' => 'M2', 'montant_inscription' => 1,
        'montant_tranche1' => 1, 'montant_tranche2' => 1], $this->responsable))->toThrow(InvalidArgumentException::class, 'existe déjà')
        ->and(BaremeScolarite::query()->count())->toBe(2);
});

it('réserve la duplication à ceux qui gèrent les barèmes', function (): void {
    $agent = ($this->compte)('scolarite_agent');
    $this->actingAs($agent);
    $this->livewire(ListBaremesScolarite::class)->assertForbidden();
    expect(fn () => $this->service->dupliquer($this->m1, $this->service->proposition($this->m1), $agent))->toThrow(HttpException::class);

    $this->actingAs(($this->compte)('admin'));
    $this->livewire(ListBaremesScolarite::class)->assertTableActionVisible('dupliquer', $this->m1);
});
