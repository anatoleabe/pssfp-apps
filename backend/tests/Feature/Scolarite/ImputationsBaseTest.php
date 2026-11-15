<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\ImputationVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\RecuVersement;
use App\Models\User;
use App\Models\Versement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Garde-fous du compte en base (spec D1, §5 et §14.2) : ils tiennent même hors de
 * l'application (console, requête directe, script de reprise). Chaque tentative se
 * fait dans son point de sauvegarde, comme le test d'immuabilité des reçus.
 */

uses()->group('scolarite');

const MIGRATION_COMPTES = '2026_12_01_100000_create_imputations_versements_table.php';

beforeEach(function (): void {
    $this->inscription = InscriptionAnnuelle::factory()->create();
    $this->versement = Versement::factory()->sansImputation()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 500000]);
    $this->imputer = fn (int $montant, array $champs = []): int => DB::table('imputations_versements')->insertGetId($champs + [
        'uuid' => (string) Str::uuid(),
        'auditeur_id' => $this->inscription->auditeur_id,
        'versement_id' => $this->versement->id,
        'inscription_annuelle_id' => $this->inscription->id,
        'montant' => $montant,
        'origine' => 'saisie',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // Chaque tentative dans son point de sauvegarde : l'erreur n'empoisonne pas la transaction du test.
    $this->refuse = function (Closure $geste): void {
        expect(fn () => DB::transaction($geste))->toThrow(QueryException::class);
    };
});

it('rattache le versement à l\'auditeur de son inscription', function (): void {
    expect($this->versement->auditeur_id)->toBe($this->inscription->auditeur_id)
        ->and($this->versement->auditeur->is($this->inscription->auditeur))->toBeTrue();
});

it('refuse un versement qui désigne l\'inscription d\'un autre auditeur', function (): void {
    $autre = Auditeur::factory()->create();

    ($this->refuse)(fn () => Versement::factory()->sansImputation()->create(['inscription_annuelle_id' => $this->inscription->id, 'auditeur_id' => $autre->id]));
});

it('refuse une imputation à cheval sur deux auditeurs', function (): void {
    $ailleurs = InscriptionAnnuelle::factory()->create();

    ($this->refuse)(fn () => ($this->imputer)(1000, ['inscription_annuelle_id' => $ailleurs->id]));
    ($this->refuse)(fn () => ($this->imputer)(1000, ['auditeur_id' => $ailleurs->auditeur_id, 'inscription_annuelle_id' => $ailleurs->id]));
});

it('refuse un montant nul, une origine inconnue, une annulation sans motif', function (): void {
    ($this->refuse)(fn () => ($this->imputer)(0));
    ($this->refuse)(fn () => ($this->imputer)(-5));
    ($this->refuse)(fn () => ($this->imputer)(1000, ['origine' => 'manuelle']));
    ($this->refuse)(fn () => ($this->imputer)(1000, ['annule_at' => now()]));
    ($this->refuse)(fn () => ($this->imputer)(1000, ['annule_at' => now(), 'annule_motif' => ' ab ']));
    ($this->refuse)(fn () => ($this->imputer)(1000, ['annule_motif' => 'Motif sans date']));

    expect(ImputationVersement::query()->count())->toBe(0);
});

it('ne laisse rien modifier ni supprimer, sauf une annulation, une fois', function (): void {
    $id = ($this->imputer)(185000);
    $ligne = fn () => DB::table('imputations_versements')->where('id', $id);
    $autre = Versement::factory()->sansImputation()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 500000]);

    ($this->refuse)(fn () => $ligne()->update(['montant' => 1000]));
    ($this->refuse)(fn () => $ligne()->update(['origine' => 'avance']));
    ($this->refuse)(fn () => $ligne()->update(['versement_id' => $autre->id]));
    ($this->refuse)(fn () => $ligne()->update(['created_at' => now()->subDay()]));
    ($this->refuse)(fn () => $ligne()->delete());

    $ligne()->update(['annule_at' => now(), 'annule_motif' => 'Versement annulé : erreur de saisie']);

    ($this->refuse)(fn () => $ligne()->update(['annule_at' => now()->addMinute(), 'annule_motif' => 'Seconde annulation']));
    ($this->refuse)(fn () => $ligne()->update(['annule_at' => null, 'annule_motif' => null]));
    ($this->refuse)(fn () => $ligne()->delete());
    expect($ligne()->value('montant'))->toBe(185000);
});

it('garde aussi le modèle : seule l\'annulation passe par Eloquent', function (): void {
    $imputation = ImputationVersement::query()->findOrFail(($this->imputer)(185000));

    expect(fn () => $imputation->forceFill(['montant' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $imputation->fresh()->delete())->toThrow(LogicException::class);

    $imputation->fresh()->forceFill(['annule_at' => now(), 'annule_motif' => 'Annulation de contrôle'])->save();

    expect(fn () => $imputation->fresh()->forceFill(['annule_motif' => 'Autre motif de contrôle'])->save())->toThrow(LogicException::class)
        ->and($imputation->fresh()->annule_at)->not->toBeNull();
});

it('refuse, sur l\'instruction fautive, la sur-imputation d\'un versement', function (): void {
    ($this->imputer)(400000);

    ($this->refuse)(fn () => ($this->imputer)(100001));
    ($this->imputer)(100000);
    expect(DB::table('imputations_versements')->sum('montant'))->toBe(500000);
});

it('refuse l\'imputation d\'une inscription au-delà de son dû', function (): void {
    $gros = Versement::factory()->sansImputation()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 2000000]);

    ($this->refuse)(fn () => ($this->imputer)(1185001, ['versement_id' => $gros->id]));
    ($this->imputer)(1185000, ['versement_id' => $gros->id]);
});

it('refuse d\'annuler un versement qui garde des imputations actives, et toute imputation sur un versement annulé', function (): void {
    $id = ($this->imputer)(185000);

    ($this->refuse)(fn () => Versement::query()->whereKey($this->versement->id)->update(['annule_at' => now(), 'annule_motif' => 'Erreur']));
    ($this->refuse)(fn () => Versement::query()->whereKey($this->versement->id)->update(['montant' => 100000]));

    // Dans l'ordre exigé : les imputations d'abord, le versement ensuite.
    DB::table('imputations_versements')->where('id', $id)->update(['annule_at' => now(), 'annule_motif' => 'Versement annulé : erreur']);
    Versement::query()->whereKey($this->versement->id)->update(['annule_at' => now(), 'annule_motif' => 'Erreur']);

    ($this->refuse)(fn () => ($this->imputer)(1000));
});

it('refuse une hausse de remise posée sans désimputation', function (): void {
    ($this->imputer)(500000);
    $this->inscription->forceFill(['remise_montant' => 685000])->save();

    ($this->refuse)(fn () => $this->inscription->fresh()->forceFill(['remise_montant' => 685001])->save());
    ($this->refuse)(fn () => InscriptionAnnuelle::query()->whereKey($this->inscription->id)->update(['montant_tranche2' => 0]));
    expect($this->inscription->fresh()->remise_montant)->toBe(685000);
});

it('garde les versements immuables en base, sauf l\'annulation et le bordereau, une fois chacun', function (): void {
    $ligne = fn () => DB::table('versements')->where('id', $this->versement->id);
    $autre = InscriptionAnnuelle::factory()->create(['auditeur_id' => $this->inscription->auditeur_id,
        'bareme_id' => BaremeScolarite::factory()->create(['annee_academique' => '2099-2100'])->id]);

    ($this->refuse)(fn () => $ligne()->update(['montant' => 600000]));
    ($this->refuse)(fn () => $ligne()->update(['payeur' => 'employeur']));
    ($this->refuse)(fn () => $ligne()->update(['inscription_annuelle_id' => $autre->id]));
    ($this->refuse)(fn () => $ligne()->update(['date_versement' => '2020-01-01']));
    ($this->refuse)(fn () => $ligne()->update(['reference' => 'AUTRE-REF']));
    ($this->refuse)(fn () => $ligne()->delete());
    ($this->refuse)(fn () => DB::statement('TRUNCATE versements CASCADE'));
    ($this->refuse)(fn () => DB::statement('TRUNCATE imputations_versements'));

    $ligne()->update(['justificatif_path' => 'scolarite/justificatifs/01JZZZZZZZZZZZZZZZZZZZZZZZ.pdf', 'updated_at' => now()]);
    ($this->refuse)(fn () => $ligne()->update(['justificatif_path' => 'scolarite/justificatifs/01JYYYYYYYYYYYYYYYYYYYYYYY.pdf']));
    $ligne()->update(['annule_at' => now(), 'annule_par' => null, 'annule_motif' => 'Bordereau rejeté']);
    ($this->refuse)(fn () => $ligne()->update(['annule_at' => null, 'annule_motif' => null]));
    ($this->refuse)(fn () => $ligne()->update(['annule_motif' => 'Autre motif']));

    expect($ligne()->value('montant'))->toBe(500000)
        ->and($ligne()->value('annule_motif'))->toBe('Bordereau rejeté');
});

it('fait prendre au déclencheur de cohérence le verrou de l\'auditeur, pour sérialiser tout écrivain', function (): void {
    $source = (string) DB::scalar("SELECT prosrc FROM pg_proc WHERE proname = 'compte_auditeur_coherent'");

    expect($source)->toMatch('/PERFORM 1 FROM auditeurs a WHERE a\.id = NEW\.auditeur_id FOR UPDATE/');
});

it('élargit l\'objet des reçus à 500 caractères', function (): void {
    expect(Schema::getColumnType('recus_versements', 'objet'))->toBe('varchar')
        ->and(collect(Schema::getColumns('recus_versements'))->firstWhere('name', 'objet')['type'])->toBe('character varying(500)')
        ->and((new RecuVersement)->getTable())->toBe('recus_versements');
});

it('refuse d\'annuler la migration dès que le compte a servi', function (): void {
    ($this->imputer)(185000);
    $migration = require database_path('migrations/'.MIGRATION_COMPTES);

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Le compte a servi : revenez au code précédent sans annuler la migration.')
        ->and(Schema::hasTable('imputations_versements'))->toBeTrue();
});

it('refuse d\'annuler la migration dès qu\'une imputation de reprise a été annulée ou remplacée', function (): void {
    $id = ($this->imputer)(185000, ['origine' => 'reprise']);
    DB::table('imputations_versements')->where('id', $id)->update(['annule_at' => now(), 'annule_motif' => 'Remise portée à 1 000 000 FCFA : bourse']);
    $migration = require database_path('migrations/'.MIGRATION_COMPTES);

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Le compte a servi')
        ->and(Schema::hasTable('imputations_versements'))->toBeTrue();
});

it('se défait entièrement tant que le compte n\'a servi qu\'à la reprise, puis se refait', function (): void {
    ($this->imputer)(185000, ['origine' => 'reprise']);
    $migration = require database_path('migrations/'.MIGRATION_COMPTES);

    $migration->down();

    expect(Schema::hasTable('imputations_versements'))->toBeFalse()
        ->and(Schema::hasColumn('versements', 'auditeur_id'))->toBeFalse()
        ->and(collect(Schema::getColumns('recus_versements'))->firstWhere('name', 'objet')['type'])->toBe('character varying(255)')
        ->and(DB::scalar("SELECT count(*) FROM pg_proc WHERE proname IN ('imputations_immuables', 'compte_auditeur_coherent', 'versements_immuables', 'compte_sans_troncature')"))->toBe(0)
        ->and(DB::scalar("SELECT count(*) FROM pg_trigger WHERE tgname IN ('versements_immuables', 'versements_sans_troncature')"))->toBe(0);

    $migration->up();

    expect(Schema::hasTable('imputations_versements'))->toBeTrue()
        ->and(Versement::query()->whereKey($this->versement->id)->value('auditeur_id'))->toBe($this->inscription->auditeur_id)
        ->and(DB::scalar("SELECT count(*) FROM pg_trigger WHERE tgname IN ('imputations_versements_immuables', 'imputations_coherentes', 'versements_compte_coherent',
            'inscriptions_compte_coherent', 'versements_immuables', 'versements_sans_troncature', 'imputations_versements_sans_troncature')"))->toBe(7);
});

it('refuse de ramener l\'objet des reçus à 255 caractères si un reçu plus long existe', function (): void {
    $migration = require database_path('migrations/'.MIGRATION_COMPTES);
    DB::table('recus_versements')->insert([
        'uuid' => (string) Str::uuid(), 'numero' => 'RCPT-2026-000001', 'annee_numero' => 2026, 'sequence' => 1,
        'versement_id' => $this->versement->id, 'inscription_annuelle_id' => $this->inscription->id, 'montant' => 500000,
        'objet' => str_repeat('a', 300), 'auditeur_nom' => 'ESSAI Test', 'annee_academique' => '2026-2027', 'niveau' => 'M1',
        'mode' => 'virement', 'reference' => 'BOR-1', 'date_versement' => '2026-11-10', 'payeur' => 'auditeur',
        'total_verse' => 500000, 'reste_a_payer' => 685000, 'emis_par' => User::factory()->create()->id,
        'emetteur_nom' => 'Agent', 'emis_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, '255 caractères')
        ->and(Schema::hasTable('imputations_versements'))->toBeTrue();
});
