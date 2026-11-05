<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\User;
use App\Services\Scolarite\StatutAuditeur;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('scolarite');

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->auditeur = Auditeur::factory()->create();
});

it('naît actif', function (): void {
    expect($this->auditeur->fresh()->statut)->toBe(Auditeur::STATUT_ACTIF);
});

it('change le statut avec motif et date, et journalise l\'ancien et le nouveau', function (): void {
    $resp = ($this->compte)('scolarite_responsable');

    app(StatutAuditeur::class)->changer($this->auditeur, Auditeur::STATUT_ABANDON, 'Démission écrite du 4/11', '2026-11-04', $resp);

    $a = $this->auditeur->fresh();
    $journal = Activity::query()->where('event', 'statut_change')->sole();
    expect($a->statut)->toBe(Auditeur::STATUT_ABANDON)
        ->and($a->statut_motif)->toBe('Démission écrite du 4/11')
        ->and($a->statut_depuis->toDateString())->toBe('2026-11-04')
        ->and($a->statut_par)->toBe($resp->id)
        ->and($journal->properties['ancien'])->toBe('actif')
        ->and($journal->properties['nouveau'])->toBe('abandon')
        ->and($journal->causer_id)->toBe($resp->id);
});

it('refuse un statut inconnu, identique, sans motif ou daté dans le futur', function (string $statut, string $motif, string $date, string $message): void {
    expect(fn () => app(StatutAuditeur::class)->changer($this->auditeur, $statut, $motif, $date, ($this->compte)('scolarite_responsable')))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'inconnu' => ['radie', 'motif', '2026-11-01', 'statut'],
    'identique' => ['actif', 'motif', '2026-11-01', 'déjà'],
    'sans motif' => ['suspendu', '  ', '2026-11-01', 'motif'],
    'futur' => ['suspendu', 'motif', '2099-01-01', 'futur'],
]);

it('réserve le changement de statut au responsable et à l\'administration', function (string $role, bool $autorise): void {
    $appel = fn () => app(StatutAuditeur::class)->changer($this->auditeur, Auditeur::STATUT_SUSPENDU, 'motif', '2026-11-01', ($this->compte)($role));

    $autorise ? expect($appel)->not->toThrow(HttpException::class) : expect($appel)->toThrow(HttpException::class);
})->with([['scolarite_responsable', true], ['admin', true], ['scolarite_agent', false], ['caisse', false]]);

it('refuse en base un statut hors liste', function (): void {
    expect(fn () => $this->auditeur->forceFill(['statut' => 'radie'])->save())->toThrow(QueryException::class);
});

it('donne les nouvelles permissions au responsable, pas à l\'agent', function (string $permission): void {
    expect(($this->compte)('scolarite_responsable')->can($permission))->toBeTrue()
        ->and(($this->compte)('admin')->can($permission))->toBeTrue()
        ->and(($this->compte)('scolarite_agent')->can($permission))->toBeFalse();
})->with(['scolarite.promotion', 'scolarite.statut', 'scolarite.messages']);
