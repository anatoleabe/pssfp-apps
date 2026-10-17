<?php

declare(strict_types=1);

use App\Filament\Resources\MotCleResource\Pages\ManageMotsCles;
use App\Models\MotCle;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'module-3');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([RolePermissionSeeder::class]);

    $this->librarian = User::factory()->create();
    $this->librarian->assignRole('librarian');
});

it('refuse un slug mal formé', function (): void {
    $this->actingAs($this->librarian);

    $this->livewire(ManageMotsCles::class)
        ->callAction('create', data: ['label' => ['fr' => 'Dette publique'], 'slug' => 'Dette Publique'])
        ->assertHasActionErrors(['slug']);

    expect(MotCle::query()->count())->toBe(0);
});

it('refuse qu\'un terme soit son propre parent ou celui d\'un ancêtre', function (): void {
    $racine = MotCle::factory()->create(['slug' => 'finances-publiques']);
    $enfant = MotCle::factory()->create(['slug' => 'fiscalite', 'parent_id' => $racine->id]);
    $this->actingAs($this->librarian);

    $this->livewire(ManageMotsCles::class)
        ->callTableAction('edit', $racine, data: ['parent_id' => $racine->id])
        ->assertHasTableActionErrors(['parent_id']);

    $this->livewire(ManageMotsCles::class)
        ->callTableAction('edit', $racine, data: ['parent_id' => $enfant->id])
        ->assertHasTableActionErrors(['parent_id']);

    expect($racine->fresh()->parent_id)->toBeNull();
});
