<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ListAuditeurs;
use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\DeblocageAcces;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Sms\SmsServiceInterface;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses()->group('filament', 'scolarite', 'acces-anticipe');

/* Accès anticipé depuis la fiche et la liste des auditeurs (spec module 4, lot A1 §7). */

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Storage::fake(CandidatureObjectStorage::DISK);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 16)->setTime(9, 0));
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $retenu = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel,
        'email' => Str::random(8).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    $this->awa = $retenu('Abena', '+237699006001');
    $this->bruno = $retenu('Bekono', '+237699006002');
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->compte)('admin'));
    $this->fiche = $this->awa->fresh()->auditeur;
    $this->autre = $this->bruno->fresh()->auditeur;
    $this->mock(SmsServiceInterface::class)->shouldReceive('send')->andReturnNull();
    Mail::fake();

    $this->page = fn () => $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()]);
    $this->preuve = fn (): UploadedFile => UploadedFile::fake()->createWithContent('preuve.pdf', "%PDF-1.4\n%%EOF\n");
    $this->deblocage = fn (array $attributs = []): DeblocageAcces => DeblocageAcces::factory()->create($attributs + [
        'inscription_annuelle_id' => $this->fiche->inscriptionCourante->id, 'echeance' => '2026-12-01',
    ]);
});

it('propose « Débloquer l\'accès » à l\'agent et au responsable de scolarité seulement', function (string $role, bool $visible): void {
    $this->actingAs(($this->compte)($role));

    $visible ? ($this->page)()->assertActionVisible('debloquerAcces') : ($this->page)()->assertActionHidden('debloquerAcces');
})->with([['scolarite_agent', true], ['scolarite_responsable', true], ['admin', true], ['service_financier', false]]);

it('ne propose pas le déblocage sur des droits soldés, un dossier retiré ou un déblocage déjà en cours', function (Closure $situation): void {
    $situation->call($this);
    $this->actingAs(($this->compte)('scolarite_responsable'));

    ($this->page)()->assertActionHidden('debloquerAcces');
})->with([
    // Closures typées : Pest les passe telles quelles, le test les exécute après beforeEach.
    'soldé' => [fn () => Versement::factory()->create(['inscription_annuelle_id' => $this->fiche->inscriptionCourante->id, 'montant' => 185000])],
    'retiré' => [fn () => $this->awa->forceFill(['withdrawn_at' => now()])->save()],
    'exclu' => [fn () => $this->fiche->forceFill(['statut' => 'exclu'])->save()],
    'en cours' => [fn () => ($this->deblocage)()],
]);

it('débloque sur un virement annoncé, avec sa preuve, et affiche l\'encadré de la fiche', function (): void {
    $this->actingAs(($this->compte)('scolarite_agent'));

    ($this->page)()
        ->callAction('debloquerAcces', data: [
            'type' => DeblocageAcces::VIREMENT_ANNONCE, 'echeance' => '2026-12-01',
            'motif' => 'Ordre de virement présenté au guichet', 'justificatif_path' => ($this->preuve)(),
        ])
        ->assertHasNoActionErrors()
        ->assertSee('Accès anticipé')
        ->assertSee('Virement annoncé')
        ->assertSee('01/12/2026')
        ->assertSee('Ordre de virement présenté au guichet')
        ->assertSee('Disponible dans l\'espace de l\'auditeur — accès accordé avant paiement');

    $d = DeblocageAcces::query()->sole();
    expect($d->type)->toBe(DeblocageAcces::VIREMENT_ANNONCE)
        ->and(DeblocageAcces::cheminPieceValide($d->justificatif_path))->toBeTrue()
        ->and(Storage::disk(CandidatureObjectStorage::DISK)->exists((string) $d->justificatif_path))->toBeTrue();
});

it('refuse le moratoire à l\'agent, même en forçant le formulaire', function (): void {
    $this->actingAs(($this->compte)('scolarite_agent'));

    ($this->page)()
        ->callAction('debloquerAcces', data: ['type' => DeblocageAcces::MORATOIRE, 'echeance' => '2027-01-15', 'motif' => 'Délai accordé sur demande écrite'])
        ->assertHasActionErrors(['type']);

    expect(DeblocageAcces::query()->count())->toBe(0);
});

it('laisse le responsable accorder un moratoire sans pièce', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    ($this->page)()
        ->callAction('debloquerAcces', data: ['type' => DeblocageAcces::MORATOIRE, 'echeance' => '2027-01-15', 'motif' => 'Délai accordé sur demande écrite'])
        ->assertHasNoActionErrors();

    expect(DeblocageAcces::query()->sole())->type->toBe(DeblocageAcces::MORATOIRE)->justificatif_path->toBeNull();
});

it('borne l\'échéance proposée selon le cas, dès le formulaire', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    ($this->page)()
        ->callAction('debloquerAcces', data: ['type' => DeblocageAcces::VIREMENT_ANNONCE, 'echeance' => '2027-03-01', 'motif' => 'Ordre de virement présenté', 'justificatif_path' => ($this->preuve)()])
        ->assertHasActionErrors(['echeance']);

    expect(DeblocageAcces::query()->count())->toBe(0);
});

it('garde la fenêtre ouverte et explique un refus du registre', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    // Douze caractères pour le formulaire, quatre une fois les espaces retirés : le registre refuse.
    ($this->page)()
        ->callAction('debloquerAcces', data: ['type' => DeblocageAcces::MORATOIRE, 'echeance' => '2027-01-15', 'motif' => '    bref    '])
        ->assertNotified('Accès non débloqué');

    expect(DeblocageAcces::query()->count())->toBe(0);
});

it('n\'efface jamais, sur un refus, une pièce qui n\'a pas été déposée par ce formulaire', function (): void {
    // Bordereau d'un autre versement, dont le chemin aurait été recopié dans l'état du formulaire.
    $autre = 'scolarite/justificatifs/01JZZZZZZZZZZZZZZZZZZZZZZZ.pdf';
    Storage::disk(CandidatureObjectStorage::DISK)->put($autre, '%PDF-1.4');
    $this->actingAs(($this->compte)('scolarite_responsable'));

    ($this->page)()
        ->callAction('debloquerAcces', data: ['type' => DeblocageAcces::MORATOIRE, 'echeance' => '2027-01-15', 'motif' => '    bref    ',
            'justificatif_path' => [(string) Str::uuid() => $autre]]);

    expect(Storage::disk(CandidatureObjectStorage::DISK)->exists($autre))->toBeTrue()
        ->and(DeblocageAcces::query()->count())->toBe(0);
});

it('refuse une pièce forgée dans l\'état du formulaire, même avec un motif valable', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    ($this->page)()
        ->callAction('debloquerAcces', data: ['type' => DeblocageAcces::MORATOIRE, 'echeance' => '2027-01-15', 'motif' => 'Délai accordé sur demande écrite',
            'justificatif_path' => [(string) Str::uuid() => 'scolarite/justificatifs/01JYYYYYYYYYYYYYYYYYYYYYYY.pdf']])
        ->assertNotified('Accès non débloqué');

    expect(DeblocageAcces::query()->count())->toBe(0);
});

it('ne propose plus à l\'agent de débloquer une inscription dont le responsable a révoqué l\'accès', function (): void {
    ($this->deblocage)(['statut' => DeblocageAcces::REVOQUE]);

    $this->actingAs(($this->compte)('scolarite_agent'));
    ($this->page)()->assertActionHidden('debloquerAcces');
    $this->actingAs(($this->compte)('scolarite_responsable'));
    ($this->page)()->assertActionVisible('debloquerAcces');
});

it('refuse de nouveau à l\'envoi si un collègue a débloqué la fiche entre-temps', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));
    $page = ($this->page)()->mountAction('debloquerAcces')
        ->setActionData(['type' => DeblocageAcces::MORATOIRE, 'echeance' => '2027-01-15', 'motif' => 'Délai accordé sur demande écrite']);

    ($this->deblocage)();
    $page->callMountedAction();

    expect(DeblocageAcces::query()->count())->toBe(1);
});

it('réserve la révocation au responsable, et limite la prolongation de l\'agent', function (): void {
    $d = ($this->deblocage)();

    $this->actingAs(($this->compte)('scolarite_agent'));
    ($this->page)()->assertActionVisible('prolongerDeblocage')->assertActionHidden('revoquerDeblocage')
        ->callAction('prolongerDeblocage', data: ['echeance' => '2026-12-20', 'motif' => 'Banque en retard sur le virement'])
        ->assertHasNoActionErrors();
    expect($d->fresh()->echeance->toDateString())->toBe('2026-12-20');
    ($this->page)()->assertActionHidden('prolongerDeblocage');

    $this->actingAs(($this->compte)('scolarite_responsable'));
    ($this->page)()->assertActionVisible('prolongerDeblocage')
        ->callAction('revoquerDeblocage', data: ['motif' => 'Virement rejeté par la banque'])
        ->assertHasNoActionErrors();
    expect($d->fresh()->statut)->toBe(DeblocageAcces::REVOQUE);
    ($this->page)()->assertActionHidden('revoquerDeblocage')->assertActionVisible('debloquerAcces');
});

it('signale une échéance dépassée et les relances dans l\'encadré, et trace le déblocage dans l\'historique', function (): void {
    ($this->deblocage)(['echeance' => '2026-11-10', 'relances' => 2, 'derniere_relance_le' => now()->subDay()]);
    activity('scolarite')->performedOn(DeblocageAcces::query()->sole())->event('deblocage_accorde')->log('Accès anticipé accordé (virement annoncé)');
    $this->actingAs(($this->compte)('scolarite_agent'));

    ($this->page)()
        ->assertSee('10/11/2026 (dépassée)')
        ->assertSee('2 relance(s)')
        ->assertSee('Accès anticipé accordé (virement annoncé)');
});

it('ouvre la pièce du déblocage par un lien signé, en le journalisant', function (): void {
    ($this->deblocage)();
    $this->actingAs(($this->compte)('scolarite_agent'));

    ($this->page)()->assertActionVisible('voirPieceDeblocage')->callAction('voirPieceDeblocage')->assertRedirect();

    expect(Activity::query()->where('event', 'deblocage_piece_consultee')->count())->toBe(1);
});

it('filtre la liste par accès anticipé : en cours, échu, par cas', function (): void {
    ($this->deblocage)(['echeance' => '2026-11-10']);
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->livewire(ListAuditeurs::class)
        ->filterTable('acces_anticipe', 'en_cours')->assertCanSeeTableRecords([$this->fiche])->assertCanNotSeeTableRecords([$this->autre])
        ->filterTable('acces_anticipe', 'echu')->assertCanSeeTableRecords([$this->fiche])
        ->filterTable('acces_anticipe', DeblocageAcces::VIREMENT_ANNONCE)->assertCanSeeTableRecords([$this->fiche])
        ->filterTable('acces_anticipe', DeblocageAcces::MORATOIRE)->assertCanNotSeeTableRecords([$this->fiche]);

    DeblocageAcces::query()->update(['echeance' => '2026-12-01']);
    $this->livewire(ListAuditeurs::class)
        ->filterTable('acces_anticipe', 'echu')->assertCanNotSeeTableRecords([$this->fiche])
        ->filterTable('acces_anticipe', 'en_cours')->assertCanSeeTableRecords([$this->fiche]);
});

it('affiche la colonne masquable « Accès anticipé » : cas et échéance', function (): void {
    ($this->deblocage)();
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->livewire(ListAuditeurs::class)
        ->assertCanNotRenderTableColumn('acces_anticipe')
        ->set('toggledTableColumns.acces_anticipe', true)
        ->assertCanRenderTableColumn('acces_anticipe')
        ->assertTableColumnStateSet('acces_anticipe', 'Virement annoncé · 01/12/2026', $this->fiche);
});
