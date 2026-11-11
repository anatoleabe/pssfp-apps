<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\AnomalieSysteme;
use App\Models\User;
use App\Services\Audit\RevueHabilitations;
use App\Services\Supervision\Supervision;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('audit', 'filament');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed(RolePermissionSeeder::class);
    $this->compte = fn (string ...$roles): User => tap(
        User::factory()->create(['email' => 'u-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.Str::random(4)]),
        fn (User $u) => $u->assignRole($roles),
    );
    $this->revue = app(RevueHabilitations::class);
    $this->admin = ($this->compte)('admin');
});

it('maintient ou retire les rôles revus, motif obligatoire au retrait, et journalise', function (): void {
    $agent = ($this->compte)('editor', 'librarian');

    expect(fn () => $this->revue->reviser($agent, ['editor'], 'court', $this->admin))->toThrow(InvalidArgumentException::class, 'motive');
    $this->revue->reviser($agent, ['editor'], 'A quitté la bibliothèque en septembre', $this->admin);

    expect($agent->refresh()->getRoleNames()->all())->toBe(['editor'])
        ->and(DB::table('revues_habilitations')->where('user_id', $agent->id)->orderBy('role')->pluck('decision', 'role')->all())
        ->toBe(['editor' => RevueHabilitations::MAINTENU, 'librarian' => RevueHabilitations::RETIRE])
        ->and(Activity::query()->where('log_name', 'roles')->pluck('event')->all())->toContain('role_retire_revue', 'habilitations_revues');
});

it('refuse la revue sans droit, sur soi-même, sur un super administrateur par un administrateur, et le retrait du dernier', function (): void {
    $agent = ($this->compte)('editor');
    $super = ($this->compte)('super_admin');

    expect(fn () => $this->revue->reviser($agent, ['editor'], null, ($this->compte)('editor')))->toThrow(HttpException::class)
        ->and(fn () => $this->revue->reviser($this->admin, ['admin'], null, $this->admin))->toThrow(InvalidArgumentException::class, 'propres')
        ->and(fn () => $this->revue->reviser($super, ['super_admin'], null, $this->admin))->toThrow(HttpException::class);

    $autreSuper = ($this->compte)('super_admin');
    $this->revue->reviser($super, [], 'Rôle remplacé par un compte nominatif', $autreSuper);
    expect($super->refresh()->hasRole('super_admin'))->toBeFalse();
});

it('ne laisse jamais retirer le rôle du dernier super administrateur (défense en profondeur, règles d\'accès levées)', function (): void {
    $super = ($this->compte)('super_admin');
    Gate::before(fn () => true);

    expect(fn () => $this->revue->reviser($super, [], 'Plus de super administrateur', $this->admin))->toThrow(InvalidArgumentException::class, 'dernier');
    expect($super->refresh()->hasRole('super_admin'))->toBeTrue();
});

it('ouvre une anomalie SEC_REVUE par rôle jamais revu ou revu il y a plus d\'un an, fermée par la revue', function (): void {
    $agent = ($this->compte)('editor');
    app(Supervision::class)->executer(['SEC_REVUE']);
    expect(AnomalieSysteme::query()->where('controle_code', 'SEC_REVUE')->aTraiter()->pluck('cle')->all())->toContain($agent->id.':editor', $this->admin->id.':admin');

    $this->revue->reviser($agent, ['editor'], null, $this->admin);
    app(Supervision::class)->executer(['SEC_REVUE']);
    expect(AnomalieSysteme::query()->where('controle_code', 'SEC_REVUE')->aTraiter()->pluck('cle')->all())->not->toContain($agent->id.':editor');

    $this->travel(RevueHabilitations::VALIDITE_JOURS + 1)->days();
    app(Supervision::class)->executer(['SEC_REVUE']);
    expect(AnomalieSysteme::query()->where('controle_code', 'SEC_REVUE')->aTraiter()->pluck('cle')->all())->toContain($agent->id.':editor');
});

it('revoit depuis l\'écran des comptes et garde les revues en ajout seul', function (): void {
    $agent = ($this->compte)('editor', 'librarian');
    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)->assertTableActionHidden('revue_habilitations', $this->admin)
        ->callTableAction('revue_habilitations', $agent, ['maintenus' => ['librarian'], 'commentaire' => 'Ne gère plus le site institutionnel']);
    expect($agent->refresh()->getRoleNames()->all())->toBe(['librarian']);

    $id = DB::table('revues_habilitations')->value('id');
    expect(fn () => DB::transaction(fn () => DB::table('revues_habilitations')->where('id', $id)->update(['decision' => 'maintenu', 'commentaire' => 'Réécrit après coup'])))
        ->toThrow(QueryException::class, 'ajout seul');
    $agent->delete();
    expect(DB::table('revues_habilitations')->where('user_id', $agent->id)->count())->toBe(0);
});
