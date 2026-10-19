<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Jobs\ProcessDocumentFile;
use App\Jobs\SendThrottledMail;
use App\Mail\LibraryDocumentPublishedMail;
use App\Mail\LibraryDocumentReturnedMail;
use App\Mail\LibraryDocumentSubmittedMail;
use App\Models\Document;
use App\Models\User;
use App\Services\Library\Import\LegacyImporter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * File de validation de la bibliothèque (Plan 6, Task 3) : onglets par
 * statut avec « À valider » en tête, notifications aux validateurs et aux
 * déposants, et renvoi motivé d'un document en brouillon.
 */
uses()->group('filament', 'module-3', 'library');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Bus::fake([ProcessDocumentFile::class, SendThrottledMail::class]);
    $this->seed([RolePermissionSeeder::class]);

    $this->contributor = reviewQueueUser('library_contributor');
    $this->librarian = reviewQueueUser('librarian');
});

function reviewQueueUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user->fresh();
}

function reviewQueueDocument(?User $owner, string $status, array $attributes = []): Document
{
    return Document::factory()->withFile()->create([
        'uploaded_by' => $owner?->id,
        'status' => $status,
        ...$attributes,
    ]);
}

/**
 * Envois mis en file, lus dans le job (propriétés privées).
 *
 * @return Collection<int, array{to: list<string>, bcc: list<string>, mailable: Mailable}>
 */
function reviewQueueMails(): Collection
{
    return Bus::dispatched(SendThrottledMail::class)->map(function (SendThrottledMail $job): array {
        $read = fn (string $property): mixed => (new ReflectionProperty($job, $property))->getValue($job);

        return ['to' => $read('destinataires'), 'bcc' => $read('copiesCachees'), 'mailable' => $read('mailable')];
    })->values();
}

/** @return list<string> */
function reviewQueueRecipients(): array
{
    return reviewQueueMails()->flatMap(fn (array $m): array => [...$m['to'], ...$m['bcc']])->sort()->values()->all();
}

it('propose les onglets de la file de validation avec le nombre de documents à valider', function (): void {
    reviewQueueDocument($this->contributor, Document::STATUS_IN_REVIEW);
    reviewQueueDocument($this->contributor, Document::STATUS_IN_REVIEW);
    reviewQueueDocument($this->contributor, Document::STATUS_DRAFT);
    $this->actingAs($this->librarian);

    $tabs = $this->livewire(ListDocuments::class)->instance()->getTabs();

    expect(array_keys($tabs))->toBe(['a_valider', 'brouillons', 'publies', 'archives', 'tous'])
        ->and(collect($tabs)->map(fn ($tab) => $tab->getLabel())->values()->all())
        ->toBe(['À valider', 'Brouillons', 'Publiés', 'Archivés', 'Tous'])
        ->and($tabs['a_valider']->getBadge())->toBe(2);
});

it('filtre la liste selon l\'onglet choisi', function (): void {
    $review = reviewQueueDocument($this->contributor, Document::STATUS_IN_REVIEW);
    $draft = reviewQueueDocument($this->contributor, Document::STATUS_DRAFT);
    $published = reviewQueueDocument(null, Document::STATUS_PUBLISHED);
    $archived = reviewQueueDocument(null, Document::STATUS_ARCHIVED);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)
        ->assertSet('activeTab', 'a_valider')
        ->assertCanSeeTableRecords([$review])
        ->assertCanNotSeeTableRecords([$draft, $published, $archived])
        ->set('activeTab', 'brouillons')
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$review, $published, $archived])
        ->set('activeTab', 'publies')
        ->assertCanSeeTableRecords([$published])
        ->assertCanNotSeeTableRecords([$review, $draft, $archived])
        ->set('activeTab', 'archives')
        ->assertCanSeeTableRecords([$archived])
        ->assertCanNotSeeTableRecords([$review, $draft, $published])
        ->set('activeTab', 'tous')
        ->assertCanSeeTableRecords([$review, $draft, $published, $archived]);
});

it('ouvre sur « Tous » quand rien n\'attend de validation', function (): void {
    reviewQueueDocument($this->contributor, Document::STATUS_DRAFT);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->assertSet('activeTab', 'tous');
});

it('ouvre sur « Tous » pour un contributeur, avec un compteur limité à ses dépôts', function (): void {
    reviewQueueDocument($this->contributor, Document::STATUS_IN_REVIEW);
    reviewQueueDocument(reviewQueueUser('library_contributor'), Document::STATUS_IN_REVIEW);
    reviewQueueDocument(reviewQueueUser('library_contributor'), Document::STATUS_IN_REVIEW);
    $this->actingAs($this->contributor);

    $component = $this->livewire(ListDocuments::class)->assertSet('activeTab', 'tous');

    expect($component->instance()->getTabs()['a_valider']->getBadge())->toBe(1);
});

it('garde le filtre « Mes dépôts » à côté des onglets', function (): void {
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->assertTableFilterExists('mes_depots');
});

it('prévient les validateurs quand un document est soumis en revue, sauf l\'auteur de l\'action', function (): void {
    $manager = reviewQueueUser('library_manager');
    reviewQueueUser('librarian', ['email' => LegacyImporter::SYSTEM_USER_EMAIL]);
    reviewQueueUser('editor');
    $draft = reviewQueueDocument($this->contributor, Document::STATUS_DRAFT, ['title' => ['fr' => 'Mémoire de contrôle'], 'type' => 'memoire']);
    $this->actingAs($this->contributor);

    $this->livewire(ListDocuments::class)->callTableAction('soumettre', $draft);

    expect($draft->fresh()->status)->toBe(Document::STATUS_IN_REVIEW)
        ->and(reviewQueueRecipients())->toEqualCanonicalizing([$this->librarian->email, $manager->email])
        ->and(reviewQueueMails()->every(fn (array $m): bool => $m['mailable'] instanceof LibraryDocumentSubmittedMail))->toBeTrue();

    $mail = reviewQueueMails()->first()['mailable'];
    $mail->assertHasSubject('[Bibliothèque PSSFP] Document à valider — Mémoire de contrôle');
    $mail->assertSeeInHtml('Mémoire de contrôle');
    $mail->assertSeeInHtml(Document::TYPES['memoire']);
    $mail->assertSeeInHtml(DocumentResource::getUrl('edit', ['record' => $draft]));
    $mail->assertDontSeeInHtml($this->contributor->email);
});

it('n\'envoie rien à la soumission quand aucun autre validateur n\'existe', function (): void {
    $draft = reviewQueueDocument($this->librarian, Document::STATUS_DRAFT);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableAction('soumettre', $draft);

    expect($draft->fresh()->status)->toBe(Document::STATUS_IN_REVIEW);
    Bus::assertNotDispatched(SendThrottledMail::class);
});

it('prévient le contributeur quand son document est publié', function (): void {
    $review = reviewQueueDocument($this->contributor, Document::STATUS_IN_REVIEW, ['title' => ['fr' => 'Rapport validé']]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableAction('publier', $review);

    expect($review->fresh()->status)->toBe(Document::STATUS_PUBLISHED)
        ->and(reviewQueueRecipients())->toBe([$this->contributor->email]);

    $mail = reviewQueueMails()->first()['mailable'];
    expect($mail)->toBeInstanceOf(LibraryDocumentPublishedMail::class);
    $mail->assertHasSubject('[Bibliothèque PSSFP] Document publié — Rapport validé');
    $mail->assertSeeInHtml('Rapport validé');
    $mail->assertSeeInHtml(DocumentResource::getUrl('edit', ['record' => $review]));
});

it('ne prévient pas le compte d\'import système ni l\'auteur de la publication', function (): void {
    $system = User::factory()->create(['email' => LegacyImporter::SYSTEM_USER_EMAIL]);
    $imported = reviewQueueDocument($system, Document::STATUS_IN_REVIEW);
    $own = reviewQueueDocument($this->librarian, Document::STATUS_IN_REVIEW);
    $orphan = reviewQueueDocument(null, Document::STATUS_IN_REVIEW);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)
        ->callTableAction('publier', $imported)
        ->callTableAction('publier', $own)
        ->callTableAction('publier', $orphan);

    expect($imported->fresh()->status)->toBe(Document::STATUS_PUBLISHED);
    Bus::assertNotDispatched(SendThrottledMail::class);
});

it('envoie un seul mail par déposant lors d\'une publication en lot', function (): void {
    $other = reviewQueueUser('library_contributor');
    $first = reviewQueueDocument($this->contributor, Document::STATUS_IN_REVIEW, ['title' => ['fr' => 'Premier dépôt']]);
    $second = reviewQueueDocument($this->contributor, Document::STATUS_IN_REVIEW, ['title' => ['fr' => 'Second dépôt']]);
    $third = reviewQueueDocument($other, Document::STATUS_IN_REVIEW, ['title' => ['fr' => 'Dépôt d\'un autre']]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableBulkAction('publierLot', collect([$first, $second, $third]));

    expect(reviewQueueMails())->toHaveCount(2)
        ->and(reviewQueueRecipients())->toEqualCanonicalizing([$this->contributor->email, $other->email]);

    $mine = reviewQueueMails()->first(fn (array $m): bool => $m['to'] === [$this->contributor->email])['mailable'];
    $mine->assertSeeInHtml('Premier dépôt');
    $mine->assertSeeInHtml('Second dépôt');
    $mine->assertDontSeeInHtml("Dépôt d'un autre", false);
    $mine->assertDontSeeInHtml('Dépôt d&#039;un autre', false);
    $mine->assertHasSubject('[Bibliothèque PSSFP] 2 documents publiés');
});

it('renvoie un document en revue au brouillon avec un motif tracé et transmis au déposant', function (): void {
    $review = reviewQueueDocument($this->contributor, Document::STATUS_IN_REVIEW, ['title' => ['fr' => 'Mémoire à reprendre']]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)
        ->assertTableActionVisible('renvoyerBrouillon', $review)
        ->callTableAction('renvoyerBrouillon', $review, ['motif' => 'Le résumé manque. <b>[lien](https://exemple.invalid)</b>'])
        ->assertHasNoTableActionErrors();

    expect($review->fresh()->status)->toBe(Document::STATUS_DRAFT);

    $log = Activity::query()->where('event', 'document_returned_to_draft')->sole();
    expect($log->subject_id)->toBe($review->id)
        ->and($log->causer_id)->toBe($this->librarian->id)
        ->and($log->properties['motif'])->toBe('Le résumé manque. <b>[lien](https://exemple.invalid)</b>');

    expect(reviewQueueRecipients())->toBe([$this->contributor->email]);
    $mail = reviewQueueMails()->first()['mailable'];
    expect($mail)->toBeInstanceOf(LibraryDocumentReturnedMail::class);
    $mail->assertHasSubject('[Bibliothèque PSSFP] Document renvoyé en brouillon — Mémoire à reprendre');
    $mail->assertSeeInHtml('Le résumé manque.');
    $mail->assertDontSeeInHtml('<b>', false);
    $mail->assertDontSeeInHtml('href="https://exemple.invalid"', false);
    $mail->assertSeeInHtml(DocumentResource::getUrl('edit', ['record' => $review]));
});

it('exige un motif pour renvoyer en brouillon', function (): void {
    $review = reviewQueueDocument($this->contributor, Document::STATUS_IN_REVIEW);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)
        ->callTableAction('renvoyerBrouillon', $review, ['motif' => ''])
        ->assertHasTableActionErrors(['motif' => 'required'])
        ->callTableAction('renvoyerBrouillon', $review, ['motif' => str_repeat('a', 1001)])
        ->assertHasTableActionErrors(['motif' => 'max']);

    expect($review->fresh()->status)->toBe(Document::STATUS_IN_REVIEW);
    Bus::assertNotDispatched(SendThrottledMail::class);
});

it('réserve le renvoi en brouillon aux validateurs et aux documents en revue', function (): void {
    $review = reviewQueueDocument($this->contributor, Document::STATUS_IN_REVIEW);
    $draft = reviewQueueDocument($this->contributor, Document::STATUS_DRAFT);
    $this->actingAs($this->contributor);

    $this->livewire(ListDocuments::class)
        ->assertTableActionHidden('renvoyerBrouillon', $review)
        ->call('mountTableAction', 'renvoyerBrouillon', $review->getKey())
        ->set('mountedTableActionsData.0.motif', 'Tentative')
        ->call('callMountedTableAction');

    expect($review->fresh()->status)->toBe(Document::STATUS_IN_REVIEW);

    $this->actingAs($this->librarian);
    $this->livewire(ListDocuments::class)
        ->set('activeTab', 'tous')
        ->assertTableActionHidden('renvoyerBrouillon', $draft);

    expect(fn () => DocumentResource::returnToDraft($draft, 'Motif'))->toThrow(HttpException::class);
    Bus::assertNotDispatched(SendThrottledMail::class);
});
