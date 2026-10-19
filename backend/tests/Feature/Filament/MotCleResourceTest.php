<?php

declare(strict_types=1);

use App\Exceptions\MotCleHasChildrenException;
use App\Filament\Resources\MotCleResource;
use App\Filament\Resources\MotCleResource\Pages\ManageMotsCles;
use App\Models\Document;
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
    $manager = User::factory()->create();
    $manager->assignRole('library_manager');
    $this->actingAs($manager);

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

/** Thésaurus de test : Institutions › Institutions internationales › FMI, + Thèmes. */
function thesaurusArbre(): array
{
    $institutions = MotCle::factory()->create(['slug' => 'institutions', 'label' => ['fr' => 'Institutions']]);
    $internationales = MotCle::factory()->create(['slug' => 'institutions-internationales', 'label' => ['fr' => 'Institutions internationales'], 'parent_id' => $institutions->id]);
    $fmi = MotCle::factory()->create(['slug' => 'fmi', 'label' => ['fr' => 'FMI'], 'parent_id' => $internationales->id]);
    $themes = MotCle::factory()->create(['slug' => 'themes', 'label' => ['fr' => 'Thèmes']]);
    $dette = MotCle::factory()->create(['slug' => 'dette', 'label' => ['fr' => 'Dette'], 'parent_id' => $themes->id]);

    return compact('institutions', 'internationales', 'fmi', 'themes', 'dette');
}

it('affiche le chemin complet de chaque mot-clé', function (): void {
    $t = thesaurusArbre();
    $this->actingAs($this->librarian);

    $this->livewire(ManageMotsCles::class)
        ->assertTableColumnStateSet('chemin', 'Institutions › Institutions internationales › FMI', $t['fmi'])
        ->assertTableColumnStateSet('chemin', 'Thèmes › Dette', $t['dette'])
        ->assertTableColumnStateSet('chemin', 'Institutions', $t['institutions']);
});

it('trie les mots-clés par chemin', function (): void {
    $t = thesaurusArbre();
    $this->actingAs($this->librarian);

    $this->livewire(ManageMotsCles::class)
        ->sortTable('chemin')
        ->assertCanSeeTableRecords([$t['institutions'], $t['internationales'], $t['fmi'], $t['themes'], $t['dette']], inOrder: true)
        ->sortTable('chemin', 'desc')
        ->assertCanSeeTableRecords([$t['dette'], $t['themes'], $t['fmi'], $t['internationales'], $t['institutions']], inOrder: true);
});

it('filtre par racine du thésaurus avec ses descendants', function (): void {
    $t = thesaurusArbre();
    $this->actingAs($this->librarian);

    $this->livewire(ManageMotsCles::class)
        ->filterTable('racine', $t['institutions']->id)
        ->assertCanSeeTableRecords([$t['institutions'], $t['internationales'], $t['fmi']])
        ->assertCanNotSeeTableRecords([$t['themes'], $t['dette']]);
});

it('compte les documents publiés, directs puis avec sous-thèmes', function (): void {
    $t = thesaurusArbre();
    $publie = Document::factory()->published()->create();
    $brouillon = Document::factory()->create(['status' => Document::STATUS_DRAFT]);
    $t['internationales']->documents()->attach([$publie->id, $brouillon->id]);
    $t['fmi']->documents()->attach($publie->id);
    $this->actingAs($this->librarian);

    $this->livewire(ManageMotsCles::class)
        ->assertTableColumnStateSet('published_documents_count', 1, $t['internationales'])
        ->assertTableColumnStateSet('published_documents_count', 0, $t['institutions'])
        ->assertTableColumnStateSet('published_with_descendants_count', 1, $t['institutions'])
        ->assertTableColumnStateSet('published_with_descendants_count', 1, $t['internationales']);
});

it('laisse le bibliothécaire créer mais pas modifier ni supprimer', function (): void {
    $t = thesaurusArbre();
    $this->actingAs($this->librarian);

    $this->livewire(ManageMotsCles::class)
        ->assertActionVisible('create')
        ->assertTableActionHidden('edit', $t['dette'])
        ->assertTableActionHidden('delete', $t['dette']);

    expect($this->librarian->can('update', $t['dette']))->toBeFalse()
        ->and($this->librarian->can('delete', $t['dette']))->toBeFalse();
});

it('laisse le responsable de la bibliothèque renommer, déplacer et supprimer', function (): void {
    $t = thesaurusArbre();
    $manager = User::factory()->create();
    $manager->assignRole('library_manager');
    $this->actingAs($manager);

    $this->livewire(ManageMotsCles::class)
        ->callTableAction('edit', $t['dette'], data: ['label' => ['fr' => 'Dette souveraine'], 'slug' => 'dette', 'parent_id' => $t['institutions']->id])
        ->assertHasNoTableActionErrors();

    expect($t['dette']->fresh()->parent_id)->toBe($t['institutions']->id);

    $this->livewire(ManageMotsCles::class)
        ->callTableAction('delete', $t['dette'])
        ->assertHasNoTableActionErrors();

    expect(MotCle::query()->whereKey($t['dette']->id)->exists())->toBeFalse();
});

it('annonce le nombre de documents rattachés avant suppression', function (): void {
    $t = thesaurusArbre();
    $docs = Document::factory()->count(2)->create();
    $t['dette']->documents()->attach($docs->pluck('id'));
    $manager = User::factory()->create();
    $manager->assignRole('library_manager');
    $this->actingAs($manager);

    $action = $this->livewire(ManageMotsCles::class)
        ->mountTableAction('delete', $t['dette'])
        ->instance()->getMountedTableAction();

    expect($action->getModalDescription())->toContain('2 document(s)');
});

it('refuse de supprimer un mot-clé qui a des sous-thèmes', function (): void {
    $t = thesaurusArbre();
    $manager = User::factory()->create();
    $manager->assignRole('library_manager');
    $this->actingAs($manager);

    $this->livewire(ManageMotsCles::class)
        ->callTableAction('delete', $t['themes'])
        ->assertNotified('Suppression impossible');

    expect(MotCle::query()->whereKey($t['themes']->id)->exists())->toBeTrue();
});

it('refuse au niveau du modèle de supprimer un mot-clé qui a des enfants', function (): void {
    $t = thesaurusArbre();

    expect(fn () => $t['themes']->delete())
        ->toThrow(MotCleHasChildrenException::class, "Déplacez ou supprimez d'abord ses sous-thèmes.");

    expect(MotCle::query()->whereKey([$t['themes']->id, $t['dette']->id])->count())->toBe(2)
        ->and($t['dette']->fresh()->parent_id)->toBe($t['themes']->id);
});
