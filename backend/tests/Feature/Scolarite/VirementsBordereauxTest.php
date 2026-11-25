<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\AuditeurResource\RelationManagers\VersementsRelationManager;
use App\Filament\Resources\VirementAnnonceResource\Pages\ListVirementsAnnonces;
use App\Models\AnomalieSysteme;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\ConfirmationVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Models\VirementAnnonce;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\VirementsAnnonces;
use App\Services\Sms\SmsServiceInterface;
use App\Services\Supervision\Supervision;
use App\Support\AppSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Virements en deux temps et remplacement d'un bordereau (spec module-4-scolarite-virements-bordereaux).
 */

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Mail::fake();
    $this->mock(SmsServiceInterface::class, fn ($mock) => $mock->shouldReceive('send')->andReturnNull());
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->administrateur = ($this->compte)('admin');
    $this->financier = ($this->compte)('service_financier');
    $this->admin = ($this->compte)('admin');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 24)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subMonth()->toIso8601String());
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Promotion 14'], 'promotion_numero' => 14]);
    Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => 'Alpha', 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699012345',
        'email' => 'alpha-'.Str::random(6).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    app(AttributionMatricules::class)->attribuer($campagne, '26', $this->admin);
    $this->inscription = Candidature::query()->where('nom', 'Alpha')->firstOrFail()->auditeur->inscriptionCourante;
    $this->scan = fn (): string => Versement::DOSSIER_JUSTIFICATIFS.'/'.Str::ulid().'.pdf';
    $this->annonce = fn (array $autres = []): VirementAnnonce => app(VirementsAnnonces::class)->annoncer($this->inscription, [
        'montant' => 300000, 'date_versement' => '2026-11-20', 'reference' => 'VIR-'.Str::random(6), 'payeur' => 'auditeur',
        'justificatif_path' => ($this->scan)(), ...$autres,
    ], $this->financier);
});

function verse(InscriptionAnnuelle $i): int
{
    return (int) Versement::query()->where('inscription_annuelle_id', $i->id)->whereNull('annule_at')->sum('montant');
}

it('annonce un virement hors compte, sans versement ni reçu, scan obligatoire et référence unique', function (): void {
    $a = ($this->annonce)(['reference' => 'vir-001']);

    expect($a->statut)->toBe(VirementAnnonce::EN_ATTENTE)->and($a->reference)->toBe('VIR-001')
        ->and(verse($this->inscription))->toBe(0)->and(Versement::query()->count())->toBe(0)
        ->and(fn () => ($this->annonce)(['reference' => 'VIR-001']))->toThrow(InvalidArgumentException::class, 'déjà la référence')
        ->and(fn () => app(RegistreVersements::class)->encaisser($this->inscription, ['montant' => 300000, 'date_versement' => '2026-11-20', 'mode' => 'virement',
            'reference' => 'VIR-001', 'payeur' => 'auditeur'], $this->financier))->toThrow(InvalidArgumentException::class, 'Virements à valider')
        ->and(fn () => ($this->annonce)(['justificatif_path' => null]))->toThrow(InvalidArgumentException::class, 'obligatoire')
        ->and(fn () => ($this->annonce)(['date_versement' => '2026-12-01']))->toThrow(InvalidArgumentException::class, 'futur')
        ->and(fn () => app(VirementsAnnonces::class)->annoncer($this->inscription, ['montant' => 1], ($this->compte)('scolarite_agent')))->toThrow(HttpException::class);
});

it('valide à la date de paiement : versement à cette date, scan repris, reçu émis, une seule fois', function (): void {
    $a = ($this->annonce)();

    expect(fn () => app(RegistreVersements::class)->encaisserVirementAnnonce($a, '2026-11-19', $this->financier))->toThrow(InvalidArgumentException::class, 'précéder')
        ->and(fn () => app(RegistreVersements::class)->encaisserVirementAnnonce($a, '2026-11-25', $this->financier))->toThrow(InvalidArgumentException::class, 'futur');

    $resultat = app(RegistreVersements::class)->encaisserVirementAnnonce($a, '2026-11-22', $this->financier);
    $v = $resultat->versement;
    expect($v->date_versement->toDateString())->toBe('2026-11-22')->and($v->mode)->toBe('virement')
        ->and($v->justificatif_path)->toBe($a->refresh()->justificatif_path)
        ->and($resultat->recu)->not->toBeNull()->and($resultat->recu->date_versement->toDateString())->toBe('2026-11-22')
        ->and(verse($this->inscription))->toBe(300000)
        ->and($a->statut)->toBe(VirementAnnonce::VALIDE)->and($a->versement_id)->toBe($v->id)->and($a->date_paiement->toDateString())->toBe('2026-11-22')
        ->and(fn () => app(RegistreVersements::class)->encaisserVirementAnnonce($a, '2026-11-22', $this->financier))->toThrow(InvalidArgumentException::class, 'déjà')
        ->and(Versement::query()->count())->toBe(1);
});

it('abandonne avec un motif, et garde les virements en ajout seul', function (): void {
    $a = ($this->annonce)();
    expect(fn () => app(VirementsAnnonces::class)->abandonner($a, 'court', $this->financier))->toThrow(InvalidArgumentException::class, 'abandonné');
    app(VirementsAnnonces::class)->abandonner($a, 'Le virement n\'est jamais arrivé sur le compte', $this->financier);

    expect($a->refresh()->statut)->toBe(VirementAnnonce::ABANDONNE)
        ->and(fn () => DB::transaction(fn () => DB::table('virements_annonces')->where('id', $a->id)->update(['montant' => 1])))->toThrow(QueryException::class, 'ne change plus')
        ->and(fn () => DB::transaction(fn () => DB::table('virements_annonces')->where('id', $a->id)->delete()))->toThrow(QueryException::class, 'supprime pas');
});

it('valide et abandonne depuis l\'écran Finances, et signale les vieilles annonces au contrôle interne', function (): void {
    $a = ($this->annonce)();
    $b = ($this->annonce)();
    $this->actingAs($this->financier);

    Livewire::test(ListVirementsAnnonces::class)->assertCanSeeTableRecords([$a, $b])
        ->callTableAction('valider', $a, ['date_paiement' => '2026-11-23'])
        ->callTableAction('abandonner', $b, ['motif' => 'Ordre de virement annulé par la banque']);
    expect($a->refresh()->statut)->toBe(VirementAnnonce::VALIDE)->and($b->refresh()->statut)->toBe(VirementAnnonce::ABANDONNE);

    $c = ($this->annonce)();
    $this->travel(VirementsAnnonces::ATTENTE_MAX_JOURS + 1)->days();
    app(Supervision::class)->executer(['VIR_ATTENTE']);
    expect(AnomalieSysteme::query()->where('controle_code', 'VIR_ATTENTE')->pluck('cle')->all())->toBe([$c->uuid]);
});

it('laisse l\'administrateur seul remplacer un bordereau, garde l\'ancien et la confirmation', function (): void {
    $ancien = ($this->scan)();
    $v = app(RegistreVersements::class)->encaisser($this->inscription, ['montant' => 185000, 'date_versement' => '2026-11-20', 'mode' => 'cremincam_agence',
        'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur', 'justificatif_path' => $ancien], $this->financier)->versement;
    confirmerVersement($v);
    $nouveau = ($this->scan)();

    expect(fn () => app(RegistreVersements::class)->remplacerJustificatif($v, $nouveau, 'Scan illisible remplacé', $this->financier))->toThrow(HttpException::class)
        ->and(fn () => app(RegistreVersements::class)->remplacerJustificatif($v, $nouveau, 'court', $this->admin))->toThrow(InvalidArgumentException::class, 'motif');

    app(RegistreVersements::class)->remplacerJustificatif($v, $nouveau, 'Scan illisible remplacé par la RAF', $this->admin);
    expect($v->refresh()->justificatif_path)->toBe($nouveau)
        ->and(DB::table('remplacements_justificatifs')->where('versement_id', $v->id)->value('ancien_path'))->toBe($ancien)
        ->and(ConfirmationVersement::query()->where('versement_id', $v->id)->exists())->toBeTrue()
        ->and(Versement::pieceDejaJointe($ancien))->toBeTrue();

    // Sans remplacement enregistré dans la transaction, la base refuse le changement.
    expect(fn () => DB::transaction(fn () => DB::table('versements')->where('id', $v->id)->update(['justificatif_path' => ($this->scan)()])))
        ->toThrow(QueryException::class, 'immuable');

    $this->actingAs($this->admin);
    Livewire::test(VersementsRelationManager::class, ['ownerRecord' => $this->inscription->auditeur, 'pageClass' => ViewAuditeur::class])
        ->assertTableActionVisible('anciensJustificatifs', $v)->assertTableActionVisible('remplacerJustificatif', $v);
    $this->actingAs($this->financier);
    Livewire::test(VersementsRelationManager::class, ['ownerRecord' => $this->inscription->auditeur, 'pageClass' => ViewAuditeur::class])
        ->assertTableActionHidden('remplacerJustificatif', $v);
});

it('refuse de remplacer le bordereau d\'un versement annulé', function (): void {
    $v = app(RegistreVersements::class)->encaisser($this->inscription, ['montant' => 185000, 'date_versement' => '2026-11-20', 'mode' => 'cremincam_agence',
        'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur', 'justificatif_path' => ($this->scan)()], $this->financier)->versement;
    app(RegistreVersements::class)->annuler($v, 'Saisie en double à annuler', ($this->compte)('admin'));

    expect(fn () => app(RegistreVersements::class)->remplacerJustificatif($v->refresh(), ($this->scan)(), 'Scan illisible remplacé', $this->admin))
        ->toThrow(InvalidArgumentException::class, 'annulé');
});
