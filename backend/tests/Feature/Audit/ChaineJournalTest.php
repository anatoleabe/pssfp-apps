<?php

declare(strict_types=1);

use App\Models\AnomalieSysteme;
use App\Services\Audit\ChaineJournal;
use App\Services\Supervision\Supervision;
use App\Support\AppSettings;
use Illuminate\Support\Facades\DB;

uses()->group('audit', 'supervision');

beforeEach(function (): void {
    $this->chaine = app(ChaineJournal::class);
    $this->chaine->sceller();
    $this->entrees = collect(range(1, 4))->map(fn (int $n) => activity('admin')->event('essai')->withProperties(['n' => $n])->log('Entrée '.$n));
});

/** @return list<string> */
function naturesRuptures(): array
{
    return array_column(app(ChaineJournal::class)->ruptures(), 'nature');
}

it('scelle les nouvelles entrées dans l\'ordre et chaîne chacune à la précédente', function (): void {
    expect($this->chaine->sceller())->toBe(4)->and($this->chaine->sceller())->toBe(0);

    $lignes = DB::table('activity_log')->whereIn('id', $this->entrees->pluck('id'))->orderBy('chaine_rang')->get();
    foreach ($lignes as $i => $l) {
        expect($l->empreinte)->toMatch('/^[0-9a-f]{64}$/');
        if ($i > 0) {
            expect($l->empreinte_precedente)->toBe($lignes[$i - 1]->empreinte)->and($l->chaine_rang)->toBe($lignes[$i - 1]->chaine_rang + 1);
        }
    }
    expect(naturesRuptures())->toBe([])
        ->and(AppSettings::get(ChaineJournal::CLE_DERNIER_SCEAU)['rang'])->toBe((int) $lignes->last()->chaine_rang);
});

it('détecte une entrée modifiée et ouvre une anomalie SEC_JOURNAL', function (): void {
    $this->chaine->sceller();
    $cible = $this->entrees[1];
    DB::table('activity_log')->where('id', $cible->id)->update(['description' => 'Entrée maquillée']);

    expect(app(ChaineJournal::class)->ruptures())->toContain(['id' => $cible->id, 'rang' => (int) DB::table('activity_log')->where('id', $cible->id)->value('chaine_rang'), 'nature' => 'modifiee']);
    app(Supervision::class)->executer(['SEC_JOURNAL']);
    expect(AnomalieSysteme::query()->where('controle_code', 'SEC_JOURNAL')->sole()->detail['resume'])->toContain('entrée n° '.$cible->id)->toContain('modifiée');
});

it('détecte une entrée supprimée au milieu de la chaîne', function (): void {
    $this->chaine->sceller();
    DB::table('activity_log')->where('id', $this->entrees[1]->id)->delete();

    expect(naturesRuptures())->toBe(['maillon_manquant']);
});

it('détecte la suppression des entrées les plus récentes grâce au dernier sceau', function (): void {
    $this->chaine->sceller();
    expect(naturesRuptures())->toBe([]);
    DB::table('activity_log')->where('id', $this->entrees->last()->id)->delete();

    expect(naturesRuptures())->toBe(['fin_supprimee']);

    $this->artisan('audit:verifier-journal', ['--reprendre' => 'Suppression expliquée : restauration de test'])->assertSuccessful();
    expect(naturesRuptures())->toBe([])
        ->and(DB::table('activity_log')->where('event', 'chaine_journal_reprise')->exists())->toBeTrue();
});

it('signale une entrée restée non scellée au-delà de dix minutes', function (): void {
    expect(naturesRuptures())->toBe([]);
    $this->travel(ChaineJournal::DELAI_SCELLEMENT_MINUTES + 1)->minutes();

    expect(naturesRuptures())->toBe(['non_scellee', 'non_scellee', 'non_scellee', 'non_scellee']);
    $this->artisan('audit:verifier-journal')->assertSuccessful();
});
