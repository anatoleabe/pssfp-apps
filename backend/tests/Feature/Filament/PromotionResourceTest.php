<?php

declare(strict_types=1);

use App\Filament\Resources\CampagneCandidatureResource\Pages\EditCampagneCandidature;
use App\Filament\Resources\PromotionResource;
use App\Filament\Resources\PromotionResource\Pages\EditPromotion;
use App\Filament\Resources\PromotionResource\Pages\ListPromotions;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;

uses()->group('filament', 'scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->p14 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14, 'max_voeux' => 1]);
    Candidature::factory()->forCampagne($this->p14)->submitted()->create(['statut' => Candidature::STATUT_ACCEPTE]);
    $this->sansRetenu = CampagneCandidature::factory()->create(['promotion_numero' => 15]);
});

it('ouvre les promotions au responsable de scolarité, pas à l\'agent ni aux admissions', function (string $role, bool $ouvert): void {
    $this->actingAs(($this->compte)($role));

    $this->get(PromotionResource::getUrl('index'))->assertStatus($ouvert ? 200 : 403);
    $this->get(PromotionResource::getUrl('edit', ['record' => $this->p14]))->assertStatus($ouvert ? 200 : 403);
})->with([['scolarite_responsable', true], ['admin', true], ['scolarite_agent', false], ['admission_committee', false], ['caisse', false]]);

it('ne liste que les campagnes qui ont des retenus', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    $this->livewire(ListPromotions::class)
        ->assertCanSeeTableRecords([$this->p14])
        ->assertCanNotSeeTableRecords([$this->sansRetenu]);
});

it('enregistre consignes et rentrée sans toucher aux champs de la campagne', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    $this->livewire(EditPromotion::class, ['record' => $this->p14->getRouteKey()])
        ->assertFormFieldDoesNotExist('status')
        ->assertFormFieldDoesNotExist('slug')
        ->fillForm(['consignes_retenus' => 'Présentez-vous à 8 h.', 'parametres_lettres.rentree_lieu' => 'à l\'Auditorium'])
        ->call('save')
        ->assertHasNoFormErrors();

    $c = $this->p14->fresh();
    expect($c->consignes_retenus)->toBe('Présentez-vous à 8 h.')
        ->and($c->parametres_lettres['rentree_lieu'])->toBe('à l\'Auditorium')
        ->and($c->status)->toBe('closed')
        ->and($c->max_voeux)->toBe(1);
});

it('ne propose plus les réglages de la promotion dans la campagne, et ne les efface pas', function (): void {
    $this->p14->update(['consignes_retenus' => 'Consignes existantes', 'parametres_lettres' => ['rentree_lieu' => 'Messa']]);
    $this->actingAs(($this->compte)('super_admin'));

    $this->livewire(EditCampagneCandidature::class, ['record' => $this->p14->getRouteKey()])
        ->assertFormFieldDoesNotExist('consignes_retenus')
        ->assertFormFieldDoesNotExist('parametres_lettres.rentree_lieu')
        ->fillForm(['max_voeux' => 2])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->p14->fresh()->consignes_retenus)->toBe('Consignes existantes')
        ->and($this->p14->fresh()->parametres_lettres['rentree_lieu'])->toBe('Messa');
});

it('garde les paramètres absents du formulaire, et refuse une signature hors de son dossier', function (): void {
    $this->p14->update(['parametres_lettres' => ['frais_inscription_montant' => 185000, 'rentree_lieu' => 'Messa'], 'signature_lettres_path' => null]);
    $this->actingAs(($this->compte)('scolarite_responsable'));

    $this->livewire(EditPromotion::class, ['record' => $this->p14->getRouteKey()])
        ->set('data.signature_lettres_path', ['x' => 'candidatures/piece.pdf'])
        ->fillForm(['parametres_lettres.rentree_lieu' => 'Auditorium'])
        ->call('save')
        ->assertHasNoFormErrors();

    $c = $this->p14->fresh();
    expect($c->parametres_lettres['frais_inscription_montant'])->toBe(185000)
        ->and($c->parametres_lettres['rentree_lieu'])->toBe('Auditorium')
        ->and($c->signature_lettres_path)->toBeNull();
});

it('affiche les erreurs du formulaire en français', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    $this->livewire(EditPromotion::class, ['record' => $this->p14->getRouteKey()])
        ->fillForm(['parametres_lettres.rentree_date' => '2026-11-11', 'parametres_lettres.rentree_date_fin' => '2026-11-10'])
        ->call('save')
        ->assertHasFormErrors(['parametres_lettres.rentree_date_fin'])
        ->assertDontSee('validation.after_or_equal');
});
