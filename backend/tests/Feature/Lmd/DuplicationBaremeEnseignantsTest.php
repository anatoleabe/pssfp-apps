<?php

declare(strict_types=1);

use App\Filament\Resources\BaremeRemunerationResource\Pages\ListBaremesRemuneration;
use App\Models\BaremeRemuneration;
use App\Models\User;
use App\Services\Lmd\DuplicationBaremeEnseignants;
use App\Services\Lmd\EtatsPaie;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed(RolePermissionSeeder::class);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Essai '.$role]), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->service = app(DuplicationBaremeEnseignants::class);
    // Le barème 2026-2027 (5 lignes) vient de la migration de la paie.
    $this->avant = BaremeRemuneration::query()->where('annee_academique', '2026-2027')->orderBy('nature')->get(['nature', 'statut', 'montant_unitaire'])->toArray();
});

it('copie toutes les lignes d\'une année vers la suivante, à l\'état proposé', function (): void {
    $this->actingAs($this->agent);
    Livewire::test(ListBaremesRemuneration::class)
        ->mountAction('dupliquer')->assertActionDataSet(['source' => '2026-2027', 'cible' => '2027-2028'])
        ->callMountedAction()->assertHasNoActionErrors()
        ->assertNotified('5 lignes créées pour 2027-2028 : saisissez leur texte de référence, puis faites-les valider.');

    $copies = BaremeRemuneration::query()->where('annee_academique', '2027-2028')->get()->keyBy('nature');
    expect($copies)->toHaveCount(5)
        ->and($copies->pluck('statut')->unique()->all())->toBe(['propose'])
        ->and($copies->pluck('modifie_par')->unique()->all())->toBe([$this->agent->id])
        ->and($copies['CM']->montant_unitaire)->toBe(17500)->and($copies['CM']->taux_retenue)->toBe(11.0)->and($copies['CM']->unite)->toBe('heure')
        ->and($copies->pluck('reference_texte')->unique()->all())->toBe([null])
        ->and(BaremeRemuneration::query()->where('annee_academique', '2026-2027')->orderBy('nature')->get(['nature', 'statut', 'montant_unitaire'])->toArray())->toBe($this->avant);
    $this->assertDatabaseHas('activity_log', ['event' => 'bareme_duplique', 'causer_id' => $this->agent->id]);

    // Un texte de l'année doit fonder le montant, puis un autre responsable valide.
    $paie = app(EtatsPaie::class);
    expect(fn () => $paie->validerBareme($copies['CM'], $this->responsable))->toThrow(InvalidArgumentException::class, 'référence du texte');
    $paie->modifierBareme($copies['CM'], 17500, 11, 'Note de service 2027 (essai)', $this->agent);
    $paie->validerBareme($copies['CM']->refresh(), $this->responsable, 17500);
    expect($copies['CM']->refresh()->estValide())->toBeTrue();
});

it('refuse une année cible déjà remplie ou mal écrite', function (): void {
    $this->service->dupliquer('2026-2027', '2027-2028', $this->responsable);

    $this->actingAs($this->agent);
    Livewire::test(ListBaremesRemuneration::class)
        ->callAction('dupliquer', ['source' => '2026-2027', 'cible' => '2027-2028'])
        ->assertNotified('Opération refusée');
    expect(BaremeRemuneration::query()->where('annee_academique', '2027-2028')->count())->toBe(5)
        ->and(fn () => $this->service->dupliquer('2026-2027', '2027-2029', $this->agent))->toThrow(InvalidArgumentException::class, 'se suivent')
        ->and(fn () => $this->service->dupliquer('2026-2027', '2025-2026', $this->agent))->toThrow(InvalidArgumentException::class, 'doit suivre')
        ->and(fn () => $this->service->dupliquer('2030-2031', '2031-2032', $this->agent))->toThrow(InvalidArgumentException::class, 'pas de barème');

    // Celui qui a dupliqué ne valide pas lui-même, même après avoir saisi la référence.
    $cm = BaremeRemuneration::query()->where('annee_academique', '2027-2028')->where('nature', 'CM')->sole();
    app(EtatsPaie::class)->modifierBareme($cm, 17500, 11, 'Note de service 2027 (essai)', $this->responsable);
    expect(fn () => app(EtatsPaie::class)->validerBareme($cm->refresh(), $this->responsable))->toThrow(InvalidArgumentException::class, 'un autre responsable');
});

it('réserve la duplication à la préparation de la paie', function (): void {
    $financier = ($this->compte)('service_financier');
    $this->actingAs($financier);
    Livewire::test(ListBaremesRemuneration::class)->assertActionHidden('dupliquer');
    expect(fn () => $this->service->dupliquer('2026-2027', '2027-2028', $financier))->toThrow(HttpException::class)
        ->and(BaremeRemuneration::query()->where('annee_academique', '2027-2028')->exists())->toBeFalse();
});
