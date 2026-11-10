<?php

declare(strict_types=1);

use App\Filament\Resources\MessageResource;
use App\Filament\Resources\MessageResource\Pages\CreateMessage;
use App\Filament\Resources\MessageResource\Pages\ListMessages;
use App\Filament\Resources\MessageResource\Pages\ViewMessage;
use App\Filament\Widgets\ATraiterWidget;
use App\Models\EnvoiMessage;
use App\Models\Message;
use App\Models\User;
use App\Services\Messagerie\Messagerie;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses()->group('filament', 'messagerie');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed(RolePermissionSeeder::class);
    $this->compte = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role.' '.Str::random(3)]),
        fn (User $u) => $u->assignRole($role),
    );
    $this->messagerie = app(Messagerie::class);
});

it('ouvre la messagerie au personnel et la ferme aux candidats', function (): void {
    $this->actingAs(($this->compte)('editor'))->get(MessageResource::getUrl('index'))->assertOk()->assertSee('Messagerie');
    $this->actingAs(($this->compte)('candidat'))->get(MessageResource::getUrl('index'))->assertForbidden();
});

it('range les messages en reçus, non lus, envoyés et archivés', function (): void {
    [$a, $b] = [($this->compte)('admin'), ($this->compte)('editor')];
    $recu = $this->messagerie->envoyer($a, [$b->id], 'Reçu non lu', 'Corps');
    $lu = $this->messagerie->envoyer($a, [$b->id], 'Reçu lu', 'Corps');
    $this->messagerie->lireFil($lu, $b);
    $archive = $this->messagerie->envoyer($a, [$b->id], 'Archivé', 'Corps');
    $this->messagerie->archiver($archive, $b);
    $envoye = $this->messagerie->envoyer($b, [$a->id], 'Envoyé par moi', 'Corps');
    $autre = $this->messagerie->envoyer($a, [($this->compte)('admin')->id], 'Pas pour moi', 'Corps');

    $this->actingAs($b);
    Livewire::test(ListMessages::class)->assertCanSeeTableRecords([$recu, $lu])->assertCanNotSeeTableRecords([$archive, $envoye, $autre]);
    Livewire::test(ListMessages::class, ['activeTab' => 'non_lus'])->assertCanSeeTableRecords([$recu])->assertCanNotSeeTableRecords([$lu, $archive]);
    Livewire::test(ListMessages::class, ['activeTab' => 'envoyes'])->assertCanSeeTableRecords([$envoye])->assertCanNotSeeTableRecords([$recu]);
    Livewire::test(ListMessages::class, ['activeTab' => 'archives'])->assertCanSeeTableRecords([$archive])->assertCanNotSeeTableRecords([$recu, $autre]);
});

it('envoie un message depuis le formulaire, avec copie par courriel', function (): void {
    [$a, $b] = [($this->compte)('admin'), ($this->compte)('scolarite_responsable')];
    $this->actingAs($a);

    Livewire::test(CreateMessage::class)
        ->fillForm(['destinataires' => [$b->id], 'objet' => 'Réunion de jury', 'priorite' => Message::HAUTE, 'corps' => 'Jeudi à 10 h.', 'copie_courriel' => true])
        ->call('create')->assertHasNoFormErrors();

    $m = Message::query()->sole();
    expect($m->expediteur_id)->toBe($a->id)->and($m->priorite)->toBe(Message::HAUTE)
        ->and($m->destinataires()->pluck('user_id')->all())->toBe([$b->id])
        ->and(EnvoiMessage::query()->sole()->canal)->toBe(EnvoiMessage::EMAIL);
});

it('marque lu à l\'ouverture, montre l\'accusé de lecture à l\'expéditeur et refuse le message d\'autrui', function (): void {
    [$a, $b, $intrus] = [($this->compte)('admin'), ($this->compte)('editor'), ($this->compte)('admin')];
    $m = $this->messagerie->envoyer($a, [$b->id], 'Objet du fil', 'Bonjour, voici le corps.');

    $this->actingAs($b)->get(MessageResource::getUrl('view', ['record' => $m]))->assertOk()->assertSee('Bonjour, voici le corps.');
    expect($this->messagerie->nonLus($b))->toBe(0);

    $this->actingAs($a)->get(MessageResource::getUrl('view', ['record' => $m]))->assertOk()->assertSee('Accusés de lecture')->assertSee('lu le');
    $this->actingAs($intrus)->get(MessageResource::getUrl('view', ['record' => $m]))->assertNotFound();
});

it('répond à tous et archive depuis la lecture du fil', function (): void {
    [$a, $b] = [($this->compte)('admin'), ($this->compte)('editor')];
    $m = $this->messagerie->envoyer($a, [$b->id], 'Question', 'Avez-vous reçu le PV ?');
    $this->actingAs($b);

    Livewire::test(ViewMessage::class, ['record' => $m->uuid])
        ->callAction('repondre', ['corps' => 'Oui, reçu.', 'copie_courriel' => false])->assertHasNoActionErrors();
    $reponse = Message::query()->where('reponse_a', $m->id)->sole();
    expect($reponse->fil)->toBe($m->fil)->and($reponse->destinataires()->pluck('user_id')->all())->toBe([$a->id]);

    Livewire::test(ViewMessage::class, ['record' => $m->uuid])->callAction('archiver');
    expect($m->destinataires()->where('user_id', $b->id)->value('archive_le'))->not->toBeNull();
});

it('ne propose pas de répondre à un message du système', function (): void {
    $b = ($this->compte)('admin');
    $m = $this->messagerie->envoyer(null, [$b->id], 'Relance', 'Anomalie à traiter', Message::RELANCE);
    $this->actingAs($b);

    Livewire::test(ViewMessage::class, ['record' => $m->uuid])->assertActionHidden('repondre')->assertActionVisible('archiver');
});

it('signale les messages non lus dans le menu, la barre du haut et l\'encart « À traiter »', function (): void {
    [$a, $b] = [($this->compte)('admin'), ($this->compte)('admin')];
    $this->messagerie->envoyer($a, [$b->id], 'Un', 'Corps');
    $this->messagerie->envoyer($a, [$b->id], 'Deux', 'Corps');
    $this->actingAs($b);

    expect(MessageResource::getNavigationBadge())->toBe('2')
        ->and(collect(ATraiterWidget::elements())->firstWhere('libelle', 'Messages non lus')['nombre'] ?? null)->toBe('2');
    $this->get(MessageResource::getUrl('index'))->assertSee('2 message(s) non lu(s)');
});
