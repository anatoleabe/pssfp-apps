<?php

declare(strict_types=1);

use App\Filament\Pages\Scolarite\TableauDeBordFinances;
use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\AuditeurResource\RelationManagers\VersementsRelationManager;
use App\Filament\Resources\RecuVersementResource\Pages\ListRecusVersements;
use App\Filament\Resources\VersementResource\Pages\ListVersements;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\RecuVersement;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * Reçu imprimable aussitôt après l'encaissement (17/11/2026) : aperçu A4 dans un nouvel onglet, bouton sur la
 * notification et dans les listes ; encaissement depuis le tableau de bord financier. Données fictives.
 */

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Mail::fake();
    $this->mock(SmsServiceInterface::class, fn ($mock) => $mock->shouldReceive('send')->andReturnNull());
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->financier = ($this->compte)('service_financier');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());
    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->dossier = Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => 'Essai', 'prenom' => 'Fictif', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+2376990'.random_int(10000, 99999),
        'email' => 'essai-'.Str::random(6).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    app(AttributionMatricules::class)->attribuer($campagne, '26', ($this->compte)('admin'));
    $this->auditeur = $this->dossier->refresh()->auditeur;
    $this->saisie = fn (string $reference): array => [
        'montant' => 100000, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence', 'reference' => $reference, 'payeur' => 'auditeur',
    ];
    $this->recu = fn (): RecuVersement => app(RegistreVersements::class)
        ->encaisser($this->auditeur->inscriptionCourante, ($this->saisie)('BOR-'.Str::random(6)), $this->financier)->recu;
    // Boutons des notifications envoyées pendant la requête Livewire.
    $this->boutons = fn (): array => collect(session('filament.notifications', []))->flatMap(fn (array $n): array => $n['actions'] ?? [])->all();
});

it('affiche le reçu A4 dans le navigateur, pour l\'imprimer, et le journalise', function (): void {
    $recu = ($this->recu)();

    $reponse = $this->actingAs($this->financier)->get(route('filament.admin.recus.imprimer', $recu));

    $reponse->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($reponse->headers->get('Content-Disposition'))->toStartWith('inline;')->toContain($recu->nomFichier())
        ->and($reponse->headers->get('Cache-Control'))->toContain('no-store')
        ->and($reponse->getContent())->toStartWith('%PDF')->toContain('/Count 1');
    $journal = Activity::query()->where('event', 'recu_imprime')->sole();
    expect($journal->causer_id)->toBe($this->financier->id)->and($journal->properties['numero'])->toBe($recu->numero);
});

it('réserve l\'aperçu aux lecteurs des reçus et à une session de l\'administration', function (): void {
    $recu = ($this->recu)();

    $this->get(route('filament.admin.recus.imprimer', $recu))->assertRedirect();
    $this->actingAs(($this->compte)('editor'))->get(route('filament.admin.recus.imprimer', $recu))->assertForbidden();
    $this->actingAs(($this->compte)('scolarite_agent'))->get(route('filament.admin.recus.imprimer', $recu))->assertOk();
    $this->get('/admin/recus/'.Str::uuid().'/imprimer')->assertNotFound();
});

it('propose « Imprimer le reçu » dès l\'encaissement depuis le tableau de bord financier', function (): void {
    $this->actingAs($this->financier);
    $this->livewire(TableauDeBordFinances::class)
        ->assertActionVisible('encaisser')
        ->callAction('encaisser', data: ['auditeur_id' => $this->auditeur->id] + ($this->saisie)('BOR-TDB'))
        ->assertHasNoActionErrors();

    $recu = RecuVersement::query()->sole();
    expect(Versement::query()->sole()->reference)->toBe('BOR-TDB')
        ->and(collect(($this->boutons)())->firstWhere('name', 'imprimerRecu'))
        ->toMatchArray(['url' => route('filament.admin.recus.imprimer', $recu), 'shouldOpenUrlInNewTab' => true]);
});

it('propose « Imprimer le reçu » après l\'encaissement depuis la fiche de l\'auditeur', function (): void {
    $this->actingAs($this->financier);
    $this->livewire(ViewAuditeur::class, ['record' => $this->auditeur->getRouteKey()])
        ->callAction('enregistrerVersement', data: ($this->saisie)('BOR-FICHE'))
        ->assertHasNoActionErrors();

    expect(collect(($this->boutons)())->pluck('name')->all())->toContain('imprimerRecu');
});

it('réserve le bouton du tableau de bord à qui encaisse', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));
    $this->livewire(TableauDeBordFinances::class)->assertActionHidden('encaisser');
});

it('ajoute « Imprimer le reçu » aux listes des versements et des reçus et à la fiche', function (): void {
    $recu = ($this->recu)();
    $versement = Versement::query()->sole();
    $url = route('filament.admin.recus.imprimer', $recu);
    $this->actingAs($this->financier);

    $this->livewire(ListVersements::class)->assertTableActionVisible('imprimerRecu', $versement)->assertTableActionHasUrl('imprimerRecu', $url, $versement);
    $this->livewire(ListRecusVersements::class)->assertTableActionVisible('imprimerRecu', $recu)->assertTableActionHasUrl('imprimerRecu', $url, $recu);
    $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->auditeur, 'pageClass' => ViewAuditeur::class])
        ->assertTableActionVisible('imprimerRecu', $versement)->assertTableActionHasUrl('imprimerRecu', $url, $versement);
});
