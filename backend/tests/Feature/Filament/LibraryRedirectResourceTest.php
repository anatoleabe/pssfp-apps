<?php

declare(strict_types=1);

use App\Filament\Resources\LibraryRedirectResource;
use App\Filament\Resources\LibraryRedirectResource\Pages\ManageLibraryRedirects;
use App\Models\LibraryRedirect;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Activitylog\Models\Activity;

uses()->group('filament', 'module-3', 'library');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([RolePermissionSeeder::class]);

    $this->manager = User::factory()->create();
    $this->manager->assignRole('library_manager');
});

function redirectUserWith(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('ouvre l\'écran aux détenteurs de library.redirects seulement', function (string $role, bool $allowed): void {
    $response = test()->actingAs(redirectUserWith($role))->get(LibraryRedirectResource::getUrl('index'));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    ['library_manager', true],
    ['admin', true],
    ['super_admin', true],
    ['librarian', false],
    ['library_viewer', false],
    ['library_contributor', false],
    ['editor', false],
]);

it('recherche sur l\'ancienne et la nouvelle adresse', function (): void {
    $a = LibraryRedirect::create(['from_path' => '/index.php/ancien-un', 'to_path' => '/documents/alpha']);
    $b = LibraryRedirect::create(['from_path' => '/index.php/ancien-deux', 'to_path' => '/documents/beta']);

    $this->actingAs($this->manager);

    $this->livewire(ManageLibraryRedirects::class)
        ->searchTable('ancien-un')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
        ->searchTable('beta')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
});

it('trie par visites décroissantes par défaut', function (): void {
    $low = LibraryRedirect::create(['from_path' => '/a', 'to_path' => '/documents/a', 'hits' => 1]);
    $high = LibraryRedirect::create(['from_path' => '/b', 'to_path' => '/documents/b', 'hits' => 50]);

    $this->actingAs($this->manager);

    $this->livewire(ManageLibraryRedirects::class)->assertCanSeeTableRecords([$high, $low], inOrder: true);
});

it('filtre par type de cible et par adresses jamais visitées', function (): void {
    $doc = LibraryRedirect::create(['from_path' => '/a', 'to_path' => '/documents/a', 'hits' => 3]);
    $theme = LibraryRedirect::create(['from_path' => '/b', 'to_path' => '/themes/b']);
    $other = LibraryRedirect::create(['from_path' => '/c', 'to_path' => '/recherche', 'hits' => 2]);

    $this->actingAs($this->manager);

    $this->livewire(ManageLibraryRedirects::class)
        ->filterTable('type_cible', 'documents')->assertCanSeeTableRecords([$doc])->assertCanNotSeeTableRecords([$theme, $other])
        ->filterTable('type_cible', 'autres')->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$doc, $theme])
        ->removeTableFilter('type_cible')
        ->filterTable('jamais_visitee')->assertCanSeeTableRecords([$theme])->assertCanNotSeeTableRecords([$doc, $other]);
});

it('crée une redirection', function (): void {
    $this->actingAs($this->manager);

    $this->livewire(ManageLibraryRedirects::class)
        ->callAction('create', data: ['from_path' => '/index.php/vieux', 'to_path' => '/documents/neuf', 'status_code' => 301])
        ->assertHasNoActionErrors();

    expect(LibraryRedirect::query()->where('from_path', '/index.php/vieux')->firstOrFail()->to_path)->toBe('/documents/neuf');
});

it('refuse une ancienne adresse déjà utilisée ou mal formée', function (): void {
    LibraryRedirect::create(['from_path' => '/dej-la', 'to_path' => '/documents/a']);
    $this->actingAs($this->manager);

    $this->livewire(ManageLibraryRedirects::class)
        ->callAction('create', data: ['from_path' => '/dej-la', 'to_path' => '/documents/b', 'status_code' => 301])
        ->assertHasActionErrors(['from_path'])
        ->callAction('create', data: ['from_path' => 'sans-slash', 'to_path' => '/documents/b', 'status_code' => 301])
        ->assertHasActionErrors(['from_path']);
});

it('refuse une cible dangereuse avec un message clair, sans erreur 500', function (string $target): void {
    $this->actingAs($this->manager);

    $this->livewire(ManageLibraryRedirects::class)
        ->callAction('create', data: ['from_path' => '/piege', 'to_path' => $target, 'status_code' => 301])
        ->assertHasActionErrors(['to_path']);

    expect(LibraryRedirect::query()->count())->toBe(0);
})->with(['//evil.com', 'https://evil.com', '/a/../b', '/\\evil.com', 'documents/a']);

it('modifie la cible sans toucher à l\'ancienne adresse, et journalise', function (): void {
    $redirect = LibraryRedirect::create(['from_path' => '/ancien', 'to_path' => '/documents/a'])->fresh();
    $this->actingAs($this->manager);

    $this->livewire(ManageLibraryRedirects::class)
        ->callTableAction('edit', $redirect, data: ['from_path' => '/autre', 'to_path' => '/documents/b', 'status_code' => 302])
        ->assertHasNoTableActionErrors();

    $redirect->refresh();
    expect($redirect->from_path)->toBe('/ancien')
        ->and($redirect->to_path)->toBe('/documents/b')
        ->and($redirect->status_code)->toBe(302);

    $log = Activity::query()->where('subject_type', LibraryRedirect::class)->where('event', 'updated')->latest('id')->firstOrFail();
    expect($log->properties['attributes'])->toHaveKey('to_path');
});

it('refuse une cible dangereuse à la modification', function (): void {
    $redirect = LibraryRedirect::create(['from_path' => '/ancien', 'to_path' => '/documents/a'])->fresh();
    $this->actingAs($this->manager);

    $this->livewire(ManageLibraryRedirects::class)
        ->callTableAction('edit', $redirect, data: ['to_path' => '//evil.com', 'status_code' => 301])
        ->assertHasTableActionErrors(['to_path']);

    expect($redirect->fresh()->to_path)->toBe('/documents/a');
});

it('supprime une redirection', function (): void {
    $redirect = LibraryRedirect::create(['from_path' => '/ancien', 'to_path' => '/documents/a'])->fresh();
    $this->actingAs($this->manager);

    $this->livewire(ManageLibraryRedirects::class)->callTableAction('delete', $redirect);

    expect(LibraryRedirect::query()->count())->toBe(0);
});

it('ne journalise pas les visites', function (): void {
    $redirect = LibraryRedirect::create(['from_path' => '/ancien', 'to_path' => '/documents/a'])->fresh();
    Activity::query()->delete();

    $redirect->increment('hits');
    $redirect->update(['hits' => 9]);

    expect(Activity::query()->get()->toArray())->toBe([]);
});

it('applique la nouvelle cible dès la requête suivante de l\'API publique', function (): void {
    $redirect = LibraryRedirect::create(['from_path' => '/ancien', 'to_path' => '/documents/a'])->fresh();
    $this->getJson('/v1/library/redirects?path=/ancien')->assertJsonPath('data.to_path', '/documents/a');

    $this->actingAs($this->manager);
    $this->livewire(ManageLibraryRedirects::class)
        ->callTableAction('edit', $redirect, data: ['to_path' => '/documents/b', 'status_code' => 301]);

    $this->getJson('/v1/library/redirects?path=/ancien')->assertJsonPath('data.to_path', '/documents/b');
});
