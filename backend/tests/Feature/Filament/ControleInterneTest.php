<?php

declare(strict_types=1);

use App\Filament\Resources\AnomalieSystemeResource;
use App\Filament\Widgets\ATraiterWidget;
use App\Models\AnomalieSysteme;
use App\Models\EtatPaie;
use App\Models\User;
use App\Services\Supervision\Controle;
use App\Services\Supervision\Controles\PaieEtatsBloques;
use App\Services\Supervision\Controles\SecComptes;
use App\Services\Supervision\Supervision;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('filament', 'supervision');

/** Double d'un contrôle : ses cas se règlent dans le test. */
function controleFactice(string $code): Controle
{
    return new class($code) implements Controle
    {
        /** @var array<string, array<string, scalar|null>> */
        public array $cas = [];

        public function __construct(private readonly string $code) {}

        public function code(): string
        {
            return $this->code;
        }

        public function libelle(): string
        {
            return 'Contrôle factice';
        }

        public function domaine(): string
        {
            return 'Test';
        }

        public function criticite(): string
        {
            return self::MAJEUR;
        }

        public function delaiJours(): int
        {
            return 2;
        }

        public function cas(): array
        {
            return $this->cas;
        }
    };
}

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed(RolePermissionSeeder::class);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->supervision = app(Supervision::class);
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
});

it('ouvre, met à jour, ferme seule et rouvre les anomalies d\'un contrôle', function (): void {
    $faux = controleFactice('PAIE_ETATS_BLOQUES');
    app()->instance(PaieEtatsBloques::class, $faux);

    $faux->cas = ['A' => ['resume' => 'Cas A'], 'B' => ['resume' => 'Cas B']];
    expect($this->supervision->executer(['PAIE_ETATS_BLOQUES'])['PAIE_ETATS_BLOQUES'])->toMatchArray(['trouves' => 2, 'nouveaux' => 2, 'corriges' => 0]);
    $a = AnomalieSysteme::query()->where('cle', 'A')->firstOrFail();
    expect($a->echeance->toDateString())->toBe('2026-11-12');

    $this->travel(1)->days();
    $faux->cas = ['A' => ['resume' => 'Cas A, détail à jour']];
    expect($this->supervision->executer(['PAIE_ETATS_BLOQUES'])['PAIE_ETATS_BLOQUES'])->toMatchArray(['nouveaux' => 0, 'corriges' => 1]);
    expect($a->refresh()->detail['resume'])->toBe('Cas A, détail à jour')
        ->and(AnomalieSysteme::query()->where('cle', 'B')->firstOrFail())->statut->toBe(AnomalieSysteme::CORRIGEE)->fermee_auto->toBeTrue();

    $faux->cas = ['A' => ['resume' => 'Cas A'], 'B' => ['resume' => 'Cas B revenu']];
    $this->supervision->executer(['PAIE_ETATS_BLOQUES']);
    expect(AnomalieSysteme::query()->where('cle', 'B')->pluck('statut')->sort()->values()->all())->toBe([AnomalieSysteme::CORRIGEE, AnomalieSysteme::OUVERTE]);
});

it('garde justifiée une anomalie acceptée tant qu\'elle est détectée, et la ferme quand elle disparaît', function (): void {
    $faux = controleFactice('PAIE_ETATS_BLOQUES');
    app()->instance(PaieEtatsBloques::class, $faux);
    $faux->cas = ['A' => ['resume' => 'Cas A']];
    $this->supervision->executer(['PAIE_ETATS_BLOQUES']);
    $a = AnomalieSysteme::query()->firstOrFail();
    $admin = ($this->compte)('admin');

    expect(fn () => $this->supervision->justifier($a, 'court', $admin))->toThrow(InvalidArgumentException::class, 'au moins');
    $this->supervision->prendreEnCharge($a, $admin);
    $this->supervision->justifier($a, 'Cumul voulu, validé par la direction', $admin);
    $this->supervision->executer(['PAIE_ETATS_BLOQUES']);
    expect($a->refresh()->statut)->toBe(AnomalieSysteme::JUSTIFIEE)
        ->and(AnomalieSysteme::query()->count())->toBe(1);

    $faux->cas = [];
    $this->supervision->executer(['PAIE_ETATS_BLOQUES']);
    expect($a->refresh()->statut)->toBe(AnomalieSysteme::CORRIGEE)
        ->and(Activity::query()->where('log_name', 'supervision')->pluck('event')->sort()->values()->all())->toBe(['anomalie_justifiee', 'anomalie_prise_en_charge']);
});

it('n\'arrête pas les autres contrôles quand l\'un échoue, et le signale dans SYS_CONTROLES', function (): void {
    app()->instance(SecComptes::class, new class implements Controle
    {
        public function code(): string
        {
            return 'SEC_COMPTES';
        }

        public function libelle(): string
        {
            return 'En panne';
        }

        public function domaine(): string
        {
            return 'Test';
        }

        public function criticite(): string
        {
            return self::MINEUR;
        }

        public function delaiJours(): int
        {
            return 1;
        }

        public function cas(): array
        {
            throw new RuntimeException('Base indisponible');
        }
    });

    $bilan = $this->supervision->executer();

    expect($bilan['SEC_COMPTES']['erreur'])->toContain('Base indisponible')
        ->and($bilan['SEC_SEPARATION']['erreur'])->toBeNull()
        ->and(AnomalieSysteme::query()->where('controle_code', 'SYS_CONTROLES')->value('cle'))->toBe('SEC_COMPTES');
});

it('détecte les états de paie bloqués, les droits incompatibles et les comptes inactifs', function (): void {
    $etat = EtatPaie::query()->forceCreate(['numero' => 'EP-2026-09', 'periode_du' => '2026-09-01', 'periode_au' => '2026-09-30', 'objet' => 'Paie', 'statut' => EtatPaie::BROUILLON]);
    $etat->forceFill(['updated_at' => now()->subDays(20)])->saveQuietly();
    $admin = ($this->compte)('admin');
    $editeur = ($this->compte)('editor');
    $ancien = ($this->compte)('receptionniste');
    $ancien->forceFill(['created_at' => now()->subDays(200)])->saveQuietly();
    $actif = ($this->compte)('librarian');
    $actif->forceFill(['created_at' => now()->subDays(200)])->saveQuietly();
    activity('connexions')->causedBy($actif)->event('connexion')->log('Connexion au back-office');

    $this->supervision->executer(['PAIE_ETATS_BLOQUES', 'SEC_SEPARATION', 'SEC_COMPTES']);
    $par = fn (string $code): array => AnomalieSysteme::query()->where('controle_code', $code)->pluck('cle')->all();

    expect($par('PAIE_ETATS_BLOQUES'))->toBe(['EP-2026-09'])
        ->and($par('SEC_SEPARATION'))->toContain($admin->id.'|update_role|journal.view')
        ->and(collect($par('SEC_SEPARATION'))->filter(fn (string $c): bool => str_starts_with($c, $editeur->id.'|'))->all())->toBe([])
        ->and($par('SEC_COMPTES'))->toBe([(string) $ancien->id]);
});

it('réserve le traitement à supervision.traiter et la consultation à supervision.consulter', function (string $role, int $statut): void {
    $this->actingAs(($this->compte)($role))->get(AnomalieSystemeResource::getUrl('index'))->assertStatus($statut);
})->with([['super_admin', 200], ['admin', 200], ['scolarite_responsable', 200], ['scolarite_agent', 403], ['editor', 403], ['service_financier', 403]]);

it('refuse le traitement au responsable de scolarité, qui consulte seulement', function (): void {
    $faux = controleFactice('PAIE_ETATS_BLOQUES');
    app()->instance(PaieEtatsBloques::class, $faux);
    $faux->cas = ['A' => ['resume' => 'Cas A']];
    $this->supervision->executer(['PAIE_ETATS_BLOQUES']);

    expect(fn () => $this->supervision->prendreEnCharge(AnomalieSysteme::query()->firstOrFail(), ($this->compte)('scolarite_responsable')))
        ->toThrow(HttpException::class);
});

it('signale les anomalies en retard dans la pastille, l\'encart « À traiter » et le tableau de bord', function (): void {
    $faux = controleFactice('PAIE_ETATS_BLOQUES');
    app()->instance(PaieEtatsBloques::class, $faux);
    $faux->cas = ['A' => ['resume' => 'Cas A en retard']];
    $this->supervision->executer(['PAIE_ETATS_BLOQUES']);
    $this->travel(5)->days();
    $this->actingAs(($this->compte)('admin'));

    expect(AnomalieSystemeResource::getNavigationBadge())->toBe('1')
        ->and(collect(ATraiterWidget::elements())->firstWhere('libelle', 'Anomalies du contrôle interne en retard'))->toMatchArray(['nombre' => '1', 'groupe' => 'Administration']);
    $this->get(AnomalieSystemeResource::getUrl('index'))->assertOk()->assertSee('Tableau de bord des contrôles')->assertSee('Cas A en retard');
});
