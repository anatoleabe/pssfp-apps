<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource;
use App\Filament\Resources\AuditeurResource\Pages\ListAuditeurs;
use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\EtiquetteResource;
use App\Filament\Resources\EtiquetteResource\Pages\ManageEtiquettes;
use App\Models\Auditeur;
use App\Models\Etiquette;
use App\Models\User;
use App\Services\Scolarite\Etiquettes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses()->group('filament', 'scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    [$this->a, $this->b, $this->c] = Auditeur::factory()->campagneCommune()->count(3)->create()->all();
    $this->campus = Etiquette::factory()->create(['nom' => 'Campus B', 'couleur' => 'info']);
    $this->amicale = Etiquette::factory()->create(['nom' => 'Président de l\'amicale', 'couleur' => 'warning']);
    $this->poser = fn (Etiquette $e, Auditeur ...$auditeurs) => app(Etiquettes::class)->poser(collect($auditeurs), $e, $this->agent);
});

it('montre les étiquettes sur la fiche et dans la liste à quiconque voit les auditeurs', function (string $role): void {
    ($this->poser)($this->campus, $this->a);
    $this->actingAs(($this->compte)($role));

    $this->get(AuditeurResource::getUrl('view', ['record' => $this->a]))->assertOk()->assertSee('Campus B');
    $this->livewire(ListAuditeurs::class)
        ->assertTableColumnVisible('etiquettes.nom')
        ->assertSee('Campus B');
})->with(['scolarite_agent', 'service_financier']);

it('réserve les gestes sur les étiquettes à qui en a la permission', function (string $role, bool $permis): void {
    $this->actingAs(($this->compte)($role));

    $fiche = $this->livewire(ViewAuditeur::class, ['record' => $this->a->getRouteKey()]);
    $liste = $this->livewire(ListAuditeurs::class);

    if ($permis) {
        $fiche->assertActionVisible('etiquettes');
        $liste->assertTableBulkActionVisible('ajouterEtiquette')->assertTableBulkActionVisible('retirerEtiquette');
    } else {
        $fiche->assertActionHidden('etiquettes');
        $liste->assertTableBulkActionHidden('ajouterEtiquette')->assertTableBulkActionHidden('retirerEtiquette');
    }
})->with([
    ['scolarite_agent', true],
    ['scolarite_responsable', true],
    ['admin', true],
    ['service_financier', false],
]);

it('fixe les étiquettes depuis la fiche, et l\'historique le montre', function (): void {
    ($this->poser)($this->campus, $this->a);
    $this->actingAs($this->agent);

    $this->livewire(ViewAuditeur::class, ['record' => $this->a->getRouteKey()])
        ->mountAction('etiquettes')
        ->assertActionDataSet(['etiquettes' => [$this->campus->id]])
        ->setActionData(['etiquettes' => [$this->amicale->id]])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Étiquettes enregistrées');

    expect($this->a->etiquettes()->pluck('nom')->all())->toBe(['Président de l\'amicale']);
    $this->livewire(ViewAuditeur::class, ['record' => $this->a->getRouteKey()])
        ->assertSee('Étiquette posée : Président de l\'amicale')
        ->assertSee('Étiquette retirée : Campus B');
});

it('crée une étiquette à la volée depuis la fiche, avec sa couleur, puis la pose', function (): void {
    $this->actingAs($this->agent);

    $this->livewire(ViewAuditeur::class, ['record' => $this->a->getRouteKey()])
        ->mountAction('etiquettes')
        ->callFormComponentAction('etiquettes', 'createOption', data: ['nom' => 'Délégué de promotion', 'couleur' => 'success'], formName: 'mountedActionForm')
        ->assertHasNoFormComponentActionErrors()
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $nouvelle = Etiquette::query()->where('nom', 'Délégué de promotion')->sole();
    expect($nouvelle->couleur)->toBe('success')
        ->and($nouvelle->cree_par)->toBe($this->agent->id)
        ->and($this->a->etiquettes()->pluck('nom')->all())->toBe(['Délégué de promotion'])
        ->and(Activity::query()->where('event', 'etiquette_creee')->where('subject_id', $nouvelle->id)->exists())->toBeTrue();
});

it('refuse à la volée un nom déjà pris, à la casse près', function (): void {
    $this->actingAs($this->agent);

    $this->livewire(ViewAuditeur::class, ['record' => $this->a->getRouteKey()])
        ->mountAction('etiquettes')
        ->callFormComponentAction('etiquettes', 'createOption', data: ['nom' => 'campus b', 'couleur' => 'gray'], formName: 'mountedActionForm')
        ->assertHasFormComponentActionErrors(['nom']);

    expect(Etiquette::query()->count())->toBe(2);
});

it('filtre la liste : au moins une des étiquettes choisies', function (): void {
    ($this->poser)($this->campus, $this->a);
    ($this->poser)($this->amicale, $this->b);
    $this->actingAs($this->agent);

    $this->livewire(ListAuditeurs::class)
        ->filterTable('etiquettes', [$this->campus->id, $this->amicale->id])
        ->assertCanSeeTableRecords([$this->a, $this->b])
        ->assertCanNotSeeTableRecords([$this->c])
        ->filterTable('etiquettes', [$this->campus->id])
        ->assertCanSeeTableRecords([$this->a])
        ->assertCanNotSeeTableRecords([$this->b, $this->c]);
});

it('ajoute puis retire une étiquette sur une sélection', function (): void {
    ($this->poser)($this->campus, $this->a);
    $this->actingAs($this->agent);

    $this->livewire(ListAuditeurs::class)
        ->callTableBulkAction('ajouterEtiquette', [$this->a, $this->b], data: ['etiquette' => $this->campus->id])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('Étiquette « Campus B » posée sur 1 auditeur');
    expect($this->campus->auditeurs()->count())->toBe(2);

    $this->livewire(ListAuditeurs::class)
        ->callTableBulkAction('retirerEtiquette', [$this->a, $this->b, $this->c], data: ['etiquette' => $this->campus->id])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('Étiquette « Campus B » retirée de 2 auditeurs');
    expect($this->campus->auditeurs()->count())->toBe(0);
});

it('ouvre l\'écran des étiquettes aux seuls détenteurs de la permission', function (string $role, bool $ouvert): void {
    $this->actingAs(($this->compte)($role));

    $this->get(EtiquetteResource::getUrl('index'))->assertStatus($ouvert ? 200 : 403);
})->with([
    ['scolarite_agent', true],
    ['scolarite_responsable', true],
    ['admin', true],
    ['service_financier', false],
    ['caisse', false],
    ['editor', false],
]);

it('liste les étiquettes avec leur nombre d\'auditeurs, les renomme et les recolore', function (): void {
    ($this->poser)($this->campus, $this->a, $this->b);
    $this->actingAs($this->agent);

    $this->livewire(ManageEtiquettes::class)
        ->assertCanSeeTableRecords([$this->campus, $this->amicale])
        // Par la clé : l'enregistrement est relu par la requête de la table, compte compris.
        ->assertTableColumnStateSet('auditeurs_count', 2, $this->campus->getKey())
        ->callTableAction('edit', $this->campus, data: ['nom' => 'Campus de Messa', 'couleur' => 'danger'])
        ->assertHasNoTableActionErrors()
        ->callTableAction('edit', $this->amicale, data: ['nom' => 'CAMPUS DE MESSA', 'couleur' => 'gray'])
        ->assertHasTableActionErrors(['nom']);

    expect($this->campus->fresh()->only(['nom', 'couleur']))->toBe(['nom' => 'Campus de Messa', 'couleur' => 'danger'])
        ->and(Activity::query()->where('event', 'etiquette_modifiee')->count())->toBe(1);
});

it('supprime une étiquette après avoir dit combien d\'auditeurs la portent', function (): void {
    ($this->poser)($this->campus, $this->a, $this->b);
    $this->actingAs($this->agent);

    $this->livewire(ManageEtiquettes::class)
        ->mountTableAction('delete', $this->campus)
        ->assertSee('2 auditeurs')
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect(Etiquette::query()->whereKey($this->campus->id)->exists())->toBeFalse()
        ->and($this->a->etiquettes()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'etiquette_supprimee')->sole()->properties['auditeurs'])->toBe(2);
});
