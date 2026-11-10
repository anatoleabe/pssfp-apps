<?php

declare(strict_types=1);

use App\Models\AnomalieSysteme;
use App\Models\EnvoiMessage;
use App\Models\Message;
use App\Models\RelanceAnomalie;
use App\Models\User;
use App\Services\Supervision\Relances;
use App\Services\Supervision\Supervision;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses()->group('filament', 'supervision', 'messagerie');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed(RolePermissionSeeder::class);
    $this->compte = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role.' '.Str::random(3)]),
        fn (User $u) => $u->assignRole($role),
    );
    $this->relances = app(Relances::class);
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 20)->setTime(6, 0));
});

/** Anomalie d'un contrôle réel du catalogue, avec un détail nominatif qui ne doit jamais sortir dans une relance. */
function anomalieRelance(string $code, string $echeance, string $statut = AnomalieSysteme::OUVERTE): AnomalieSysteme
{
    $a = new AnomalieSysteme;
    $a->forceFill([
        'controle_code' => $code, 'cle' => Str::random(8), 'detail' => ['resume' => 'Cas de Jean Exemple'],
        'premiere_detection' => now()->subDays(12), 'derniere_detection' => now(), 'echeance' => $echeance, 'statut' => $statut,
    ] + ($statut === AnomalieSysteme::JUSTIFIEE ? ['justification' => 'Cumul voulu par la direction', 'traite_par' => User::factory()->create()->id, 'traite_le' => now()] : []))->save();

    return $a;
}

/** @return list<int> */
function niveauxRelances(AnomalieSysteme $a): array
{
    return RelanceAnomalie::query()->where('anomalie_id', $a->id)->orderBy('niveau')->pluck('niveau')->all();
}

it('relance chaque niveau dû selon le retard et la criticité, une seule fois', function (): void {
    foreach (['service_financier', 'scolarite_responsable', 'chef_structure', 'admin'] as $r) {
        ($this->compte)($r);
    }
    // PAI_RAPPROCHEMENT bloquant (+0, +2, +5), PAIE_ETATS_BLOQUES majeur (+0, +5, +15), SEC_COMPTES mineur (+0, +15).
    $aEcheance = anomalieRelance('PAI_RAPPROCHEMENT', '2026-11-20');
    $plus2 = anomalieRelance('PAI_RAPPROCHEMENT', '2026-11-18');
    $plus5 = anomalieRelance('PAI_RAPPROCHEMENT', '2026-11-15');
    $majeurPlus4 = anomalieRelance('PAIE_ETATS_BLOQUES', '2026-11-16');
    $mineurPlus30 = anomalieRelance('SEC_COMPTES', '2026-10-21');
    $pasEncore = anomalieRelance('PAI_RAPPROCHEMENT', '2026-11-21');
    $justifiee = anomalieRelance('PAI_RAPPROCHEMENT', '2026-11-01', AnomalieSysteme::JUSTIFIEE);
    $corrigee = anomalieRelance('PAI_RAPPROCHEMENT', '2026-11-01', AnomalieSysteme::CORRIGEE);

    $bilan = $this->relances->generer();

    expect(niveauxRelances($aEcheance))->toBe([1])
        ->and(niveauxRelances($plus2))->toBe([1, 2])
        ->and(niveauxRelances($plus5))->toBe([1, 2, 3])
        ->and(niveauxRelances($majeurPlus4))->toBe([1])
        ->and(niveauxRelances($mineurPlus30))->toBe([1, 2])
        ->and(niveauxRelances($pasEncore))->toBe([])
        ->and(niveauxRelances($justifiee))->toBe([])
        ->and(niveauxRelances($corrigee))->toBe([])
        ->and($bilan['relances'])->toBe(9)
        ->and($bilan['sans_destinataire'])->toBe(0);

    expect($this->relances->generer())->toBe(['relances' => 0, 'messages' => 0, 'sans_destinataire' => 0]);
    $this->travel(1)->days();
    expect($this->relances->generer()['relances'])->toBe(2)
        ->and(niveauxRelances($aEcheance))->toBe([1])
        ->and(niveauxRelances($pasEncore))->toBe([1]);
});

it('n\'escalade jamais vers le responsable déjà relancé', function (): void {
    expect(Relances::roleVise('service_financier', 1, null))->toBe('service_financier')
        ->and(Relances::roleVise('service_financier', 2, Relances::ESCALADE))->toBe('scolarite_responsable')
        ->and(Relances::roleVise('scolarite_responsable', 2, Relances::ESCALADE))->toBe(Relances::DIRECTION)
        ->and(Relances::roleVise(Relances::DIRECTION, 3, Relances::DIRECTION))->toBe('admin');

    $responsable = ($this->compte)('scolarite_responsable');
    $direction = ($this->compte)('chef_structure');
    $a = anomalieRelance('REQ_NON_COTEES', '2026-11-15');
    $this->relances->generer();

    $niveau2 = RelanceAnomalie::query()->where('anomalie_id', $a->id)->where('niveau', 2)->sole();
    expect($niveau2->destinataire_role)->toBe(Relances::DIRECTION)
        ->and($niveau2->message->destinataires()->pluck('user_id')->all())->toBe([$direction->id])
        ->and(RelanceAnomalie::query()->where('anomalie_id', $a->id)->where('niveau', 1)->sole()->message->destinataires()->pluck('user_id')->all())->toBe([$responsable->id]);
});

it('se replie sur l\'administrateur puis le super administrateur, et signale une relance sans destinataire', function (): void {
    $a = anomalieRelance('PAIE_ETATS_BLOQUES', '2026-11-20');
    $this->relances->generer();
    $r = RelanceAnomalie::query()->where('anomalie_id', $a->id)->sole();
    expect($r->message_id)->toBeNull()->and($r->destinataires)->toBe(0)->and($r->destinataire_role)->toBe('service_financier');

    app(Supervision::class)->executer(['SYS_DESTINATAIRES']);
    expect(AnomalieSysteme::query()->where('controle_code', 'SYS_DESTINATAIRES')->sole()->detail['resume'])->toContain('service_financier');

    $super = ($this->compte)('super_admin');
    $b = anomalieRelance('PAIE_ETATS_BLOQUES', '2026-11-20');
    $this->relances->generer();
    $rb = RelanceAnomalie::query()->where('anomalie_id', $b->id)->sole();
    expect($rb->destinataire_role)->toBe('super_admin')->and($rb->message->destinataires()->pluck('user_id')->all())->toBe([$super->id]);

    $admin = ($this->compte)('admin');
    $c = anomalieRelance('PAIE_ETATS_BLOQUES', '2026-11-20');
    $this->relances->generer();
    $rc = RelanceAnomalie::query()->where('anomalie_id', $c->id)->sole();
    expect($rc->destinataire_role)->toBe('admin')->and($rc->message->destinataires()->pluck('user_id')->all())->toBe([$admin->id])
        ->and($rc->message->corps)->toContain('Aucun compte ne détient le rôle « service_financier »');
});

it('regroupe les relances d\'un passage en un message par niveau et par rôle, sans donnée personnelle', function (): void {
    $financier = ($this->compte)('service_financier');
    $financier->forceFill(['phone_e164' => '+237690000009'])->save();
    ($this->compte)('scolarite_responsable');
    anomalieRelance('PAI_RAPPROCHEMENT', '2026-11-20');
    anomalieRelance('PAI_RAPPROCHEMENT', '2026-11-19');
    anomalieRelance('PAIE_ETATS_BLOQUES', '2026-11-20');
    anomalieRelance('PAI_RAPPROCHEMENT', '2026-11-18');

    expect($this->relances->generer())->toBe(['relances' => 5, 'messages' => 2, 'sans_destinataire' => 0]);

    $niveau1 = Message::query()->where('objet', 'like', 'Relance%')->sole();
    expect($niveau1->objet)->toBe('Relance du contrôle interne : 4 anomalies à traiter')
        ->and($niveau1->categorie)->toBe(Message::RELANCE)->and($niveau1->expediteur_id)->toBeNull()
        ->and($niveau1->priorite)->toBe(Message::NORMALE)
        ->and($niveau1->corps)->toContain('anomalie n°')->toContain('/admin/controle-interne')->not->toContain('Jean Exemple')
        ->and($niveau1->destinataires()->pluck('user_id')->all())->toBe([$financier->id])
        ->and(EnvoiMessage::query()->where('message_id', $niveau1->id)->pluck('canal')->sort()->values()->all())->toBe(['email', 'sms']);

    $niveau2 = Message::query()->where('objet', 'like', 'Escalade%')->sole();
    expect($niveau2->objet)->toBe('Escalade (niveau 2) du contrôle interne : 1 anomalie en retard')
        ->and($niveau2->priorite)->toBe(Message::HAUTE);
});

it('garde les relances en ajout seul', function (): void {
    ($this->compte)('service_financier');
    anomalieRelance('PAI_RAPPROCHEMENT', '2026-11-20');
    $this->relances->generer();
    $id = RelanceAnomalie::query()->value('id');

    expect(fn () => DB::transaction(fn () => DB::table('relances_anomalies')->where('id', $id)->update(['niveau' => 2])))->toThrow(QueryException::class, 'ajout seul')
        ->and(fn () => DB::transaction(fn () => DB::table('relances_anomalies')->where('id', $id)->delete()))->toThrow(QueryException::class, 'ajout seul');
});

it('relance après les contrôles du matin et compte les relances au tableau de bord', function (): void {
    ($this->compte)('admin');
    anomalieRelance('SEC_COMPTES', '2026-11-20');

    $this->artisan('supervision:executer', ['--controle' => ['SYS_CONTROLES']])
        ->expectsOutputToContain('Relances : 1 envoyée(s) en 1 message(s)')->assertSuccessful();
    $ligne = collect(app(Supervision::class)->tableau())->firstWhere('code', 'SEC_COMPTES');
    expect($ligne['relances'])->toBe(1);

    $this->artisan('supervision:executer', ['--controle' => ['SYS_CONTROLES'], '--sans-relances' => true])
        ->doesntExpectOutputToContain('Relances')->assertSuccessful();
});
