<?php

declare(strict_types=1);

use App\Filament\Resources\MotCleResource;
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

it('ouvre le thésaurus au bibliothécaire et le ferme à l\'éditeur', function (): void {
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    $this->actingAs($this->librarian)->get(MotCleResource::getUrl('index'))->assertOk();
    $this->actingAs($editor)->get(MotCleResource::getUrl('index'))->assertForbidden();
});

it('crée un terme rattaché à un parent', function (): void {
    $parent = MotCle::factory()->create(['slug' => 'finances-publiques']);
    $this->actingAs($this->librarian);

    $this->livewire(ManageMotsCles::class)
        ->callAction('create', data: ['label' => ['fr' => 'Dette publique'], 'slug' => 'dette-publique', 'parent_id' => $parent->id])
        ->assertHasNoActionErrors();

    $terme = MotCle::query()->where('slug', 'dette-publique')->firstOrFail();
    expect($terme->getTranslation('label', 'fr'))->toBe('Dette publique')
        ->and($terme->parent_id)->toBe($parent->id);
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

it('affiche le libellé et le libellé du parent dans la liste', function (): void {
    $parent = MotCle::factory()->create(['slug' => 'finances-publiques', 'label' => ['fr' => 'Finances publiques']]);
    $terme = MotCle::factory()->create(['slug' => 'fiscalite', 'label' => ['fr' => 'Fiscalité'], 'parent_id' => $parent->id]);
    $this->actingAs($this->librarian);

    $this->livewire(ManageMotsCles::class)
        ->assertTableColumnStateSet('label.fr', 'Fiscalité', $terme)
        ->assertTableColumnStateSet('parent.label', 'Finances publiques', $terme)
        ->searchTable('fiscal')
        ->assertCanSeeTableRecords([$terme])
        ->assertCanNotSeeTableRecords([$parent]);
});
