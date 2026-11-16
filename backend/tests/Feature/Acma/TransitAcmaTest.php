<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Acma\ArchiveChiffree;
use App\Services\Acma\ChargementTransit;
use App\Services\Acma\CsvAcma;
use App\Services\Acma\ExtractionAcma;
use App\Services\Acma\SourceAcma;
use App\Support\Journaux;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\Acma\JeuAcmaFictif;
use Tests\Support\Acma\SourceAcmaFactice;
use Tests\Support\Acma\TransitAcma;

uses()->group('acma');

const ACMA_PHRASE_ESSAI = 'phrase-de-passe-fictive-longue';

beforeEach(function (): void {
    // Dérivation de clé allégée pour les tests ; la production garde 600 000 itérations.
    app()->instance(ArchiveChiffree::class, new ArchiveChiffree(1000));
    $this->dossier = sys_get_temp_dir().'/acma-essai-'.Str::random(8);
    mkdir($this->dossier, 0700);
    $this->usi = tap(User::factory()->create(['email' => 'usi-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole(Role::findOrCreate('admin', 'web')));
    putenv('ACMA_PHRASE='.ACMA_PHRASE_ESSAI);
});

afterEach(function (): void {
    putenv('ACMA_PHRASE');
    File::deleteDirectory($this->dossier);
});

/** Extrait le jeu fictif (éventuellement modifié) et rend le chemin de l'archive. */
function extraireJeu(string $dossier, ?array $lignes = null): string
{
    $resultat = app(ExtractionAcma::class)->extraire(SourceAcmaFactice::jeu($lignes), $dossier, ['fichier' => 'acma-fictif.sql', 'sha256' => str_repeat('b', 64), 'date' => '2026-11-15'], ACMA_PHRASE_ESSAI);

    return $resultat['archive'];
}

/** Déchiffre une archive dans un dossier pour l'inspecter. */
function ouvrirArchive(string $archive, string $cible): void
{
    app(ArchiveChiffree::class)->dechiffrer($archive, $cible, ACMA_PHRASE_ESSAI);
}

function transitCompte(string $table): int
{
    return DB::table(ChargementTransit::SCHEMA.'.'.$table)->count();
}

it('n\'extrait aucune table ni colonne exclue, chiffre l\'archive et supprime le dossier en clair', function (): void {
    $archive = extraireJeu($this->dossier);

    expect(glob($this->dossier.'/*'))->toBe([$archive]);
    $brut = file_get_contents($archive);
    expect($brut)->not->toContain('ESSAI')->not->toContain('manifeste')->not->toContain('90EFP001');

    ouvrirArchive($archive, $this->dossier.'/ouvert');
    $fichiers = array_map('basename', glob($this->dossier.'/ouvert/*'));
    sort($fichiers);
    $attendus = array_map(fn (string $t): string => $t.'.csv', ExtractionAcma::TABLES);
    $attendus[] = 'manifeste.json';
    sort($attendus);
    expect($fichiers)->toBe($attendus);

    $tout = implode("\n", array_map('file_get_contents', glob($this->dossier.'/ouvert/*')));
    foreach (['MOTDEPASSE-FTP-FICTIF', 'MOTDEPASSE-CLAIR-FICTIF', 'CLE-PLATEFORME-FICTIVE', 'IDENTIFIANT-FTP-FICTIF', 'ftp.invalid', '600000000', 'ADRESSE FICTIVE'] as $secret) {
        expect($tout)->not->toContain($secret);
    }
    $manifeste = json_decode(file_get_contents($this->dossier.'/ouvert/manifeste.json'), true);
    expect(array_column($manifeste['tables']['etablissement']['colonnes'], 'nom'))->not->toContain('paswdftp')->not->toContain('userftp')->toContain('Lengende 1')
        ->and(array_column($manifeste['tables']['etudiant']['colonnes'], 'nom'))->not->toContain('telephone')->not->toContain('adresse')
        ->and($manifeste['exclusions']['tables'])->toContain('utilisateur')->toContain('tachesutili')
        ->and($manifeste['tables']['du']['lignes'])->toBe(18)
        ->and($manifeste['tables']['notes_hist']['lignes'])->toBe(9)
        ->and($manifeste['source'])->toMatchArray(['fichier' => 'acma-fictif.sql', 'date_export' => '2026-11-15', 'version_serveur' => '11.8.9-MariaDB-factice'])
        ->and($manifeste['tables']['du']['sha256'])->toBe(hash_file('sha256', $this->dossier.'/ouvert/du.csv'));

    // Binaire en hexadécimal ; NULL distinct du texte ; valeurs telles que MySQL les rend.
    $phases = iterator_to_array(CsvAcma::lire($this->dossier.'/ouvert/phasepayement.csv'), false);
    expect($phases[1][1])->toBe(bin2hex("Avance\x00\xff"));
    $etudiants = iterator_to_array(CsvAcma::lire($this->dossier.'/ouvert/etudiant.csv'), false);
    expect($etudiants[2][array_search('datenaissance', $etudiants[0], true)])->toBe('0000-00-00');
});

it('refuse d\'extraire sans source ACMA ni phrase de passe suffisante', function (): void {
    config()->set('database.connections.acma_source.host', null);
    $this->artisan('acma:extraire', ['--sortie' => $this->dossier])->assertFailed();

    expect(fn () => app(ExtractionAcma::class)->extraire(SourceAcmaFactice::jeu(), $this->dossier, ['fichier' => null, 'sha256' => null, 'date' => null], 'courte'))
        ->toThrow(RuntimeException::class, 'au moins 12');
    expect(glob($this->dossier.'/*'))->toBe([]);
});

it('extrait par la commande, puis charge le transit tout en texte', function (): void {
    app()->instance(SourceAcma::class, SourceAcmaFactice::jeu());
    $this->artisan('acma:extraire', ['--sortie' => $this->dossier, '--date-export' => '2026-11-15'])->assertSuccessful();
    $archive = glob($this->dossier.'/*.chiffre')[0];

    $this->artisan('acma:charger', ['archive' => $archive, '--auteur' => $this->usi->email])
        ->expectsOutputToContain('Transit chargé : 21 tables')->assertSuccessful();

    expect(transitCompte('etudiant'))->toBe(20)->and(transitCompte('du'))->toBe(18)->and(transitCompte('notes'))->toBe(16);
    $types = DB::table('information_schema.columns')->where('table_schema', ChargementTransit::SCHEMA)->where('column_name', '<>', '_ligne')
        ->whereNotIn('table_name', ['_chargement'])->distinct()->pluck('data_type')->all();
    expect($types)->toBe(['text']);
    expect(DB::table('acma_transit.etudiant')->where('matricule', '90EFP007')->value('datenaissance'))->toBe('2021-06-00')
        ->and(DB::table('acma_transit.etudiant')->where('matricule', '90efp002 ')->value('datenaissance'))->toBe('0000-00-00')
        ->and(DB::table('acma_transit.notes')->where('idnote', '1014')->value('exam'))->toBe('12.333333333')
        ->and(DB::table('acma_transit.etablissement')->value('Lengende 1'))->toBe("Légende \"fictive\"\nsur deux lignes")
        ->and(DB::table('acma_transit.du')->where('IddroitU', '2')->value('Montant'))->toBe('-100000');
    $chargement = DB::table('acma_transit._chargement')->first();
    expect($chargement->charge_par)->toBe($this->usi->id)
        ->and(json_decode($chargement->manifeste, true)['source']['date_export'])->toBe('2026-11-15');
    expect(DB::table('activity_log')->where('log_name', Journaux::ARCHIVES)->where('event', 'transit_charge')->count())->toBe(1);
    expect(glob(storage_path('app/private/acma/tmp/*')))->toBe([]);
});

it('refuse une archive dont un CSV ou le nombre de lignes diffère du manifeste, et garde le transit précédent', function (): void {
    TransitAcma::construire();
    $avant = transitCompte('du');
    $archive = extraireJeu($this->dossier);

    // CSV altéré : l'empreinte ne correspond plus.
    ouvrirArchive($archive, $this->dossier.'/altere');
    file_put_contents($this->dossier.'/altere/du.csv', CsvAcma::ligne(array_fill(0, 25, '1')), FILE_APPEND);
    unlink($archive);
    app(ArchiveChiffree::class)->chiffrer($this->dossier.'/altere', $this->dossier.'/altere.chiffre', ACMA_PHRASE_ESSAI);
    $this->artisan('acma:charger', ['archive' => $this->dossier.'/altere.chiffre', '--auteur' => $this->usi->email])
        ->expectsOutputToContain('Empreinte du fichier de la table du')->assertFailed();
    expect(transitCompte('du'))->toBe($avant);

    // Nombre de lignes altéré, empreinte recalculée : refusé au chargement, rien n'a changé.
    $manifeste = json_decode(file_get_contents($this->dossier.'/altere/manifeste.json'), true);
    $manifeste['tables']['du']['sha256'] = hash_file('sha256', $this->dossier.'/altere/du.csv');
    file_put_contents($this->dossier.'/altere/manifeste.json', json_encode($manifeste));
    app(ArchiveChiffree::class)->chiffrer($this->dossier.'/altere', $this->dossier.'/lignes.chiffre', ACMA_PHRASE_ESSAI);
    $this->artisan('acma:charger', ['archive' => $this->dossier.'/lignes.chiffre', '--auteur' => $this->usi->email])
        ->expectsOutputToContain('19 lignes lues, 18 annoncées')->assertFailed();
    expect(transitCompte('du'))->toBe($avant)
        ->and(DB::table('acma_transit._chargement')->value('archive'))->toBe('jeu-fictif.chiffre')
        ->and(glob(storage_path('app/private/acma/tmp/*')))->toBe([]);
});

it('refuse une phrase de passe erronée, une archive altérée ou tronquée, et un manifeste qui cite une colonne exclue', function (): void {
    $archive = extraireJeu($this->dossier);
    putenv('ACMA_PHRASE=une-autre-phrase-fictive');
    $this->artisan('acma:charger', ['archive' => $archive, '--auteur' => $this->usi->email])
        ->expectsOutputToContain('phrase de passe erronée ou archive altérée')->assertFailed();
    putenv('ACMA_PHRASE='.ACMA_PHRASE_ESSAI);

    $octets = file_get_contents($archive);
    file_put_contents($this->dossier.'/altere.chiffre', substr_replace($octets, chr(ord($octets[100]) ^ 1), 100, 1));
    expect(fn () => ouvrirArchive($this->dossier.'/altere.chiffre', $this->dossier.'/x'))->toThrow(RuntimeException::class);
    file_put_contents($this->dossier.'/tronque.chiffre', substr($octets, 0, (int) (strlen($octets) / 2)));
    expect(fn () => ouvrirArchive($this->dossier.'/tronque.chiffre', $this->dossier.'/y'))->toThrow(RuntimeException::class);

    $manifeste = ['format' => ExtractionAcma::FORMAT, 'tables' => ['etablissement' => ['fichier' => 'etablissement.csv', 'lignes' => 1, 'sha256' => 'x', 'colonnes' => [['nom' => 'paswdftp', 'type' => 'varchar(200)']]]]];
    expect(fn () => ChargementTransit::verifierManifeste($manifeste))->toThrow(RuntimeException::class, 'Colonnes refusées');
    expect(fn () => ChargementTransit::identifiant('a"; DROP TABLE users; --'))->toThrow(RuntimeException::class);
    expect(glob(storage_path('app/private/acma/tmp/*')))->toBe([]);
});

it('remplace le transit au second chargement', function (): void {
    TransitAcma::construire();
    $lignes = JeuAcmaFictif::lignes();
    array_pop($lignes['du']);
    $this->artisan('acma:charger', ['archive' => extraireJeu($this->dossier, $lignes), '--auteur' => $this->usi->email])->assertSuccessful();

    expect(transitCompte('du'))->toBe(17)->and(DB::table('acma_transit._chargement')->count())->toBe(1);
});

it('exige un auteur super_admin ou admin', function (): void {
    $agent = User::factory()->create();
    $this->artisan('acma:charger', ['archive' => 'x', '--auteur' => $agent->email])->assertFailed();
    $this->artisan('acma:charger', ['archive' => 'x', '--auteur' => 'inconnu@pssfp.local'])->assertFailed();
});

it('inventorie le transit avec des comptes seulement, sans nom ni matricule', function (): void {
    TransitAcma::construire();

    expect(Artisan::call('acma:inventaire'))->toBe(0);
    $sortie = Artisan::output();

    foreach (JeuAcmaFictif::donneesNominatives() as $valeur) {
        expect(mb_strtoupper($sortie))->not->toContain(mb_strtoupper($valeur));
    }
    $valeur = fn (string $libelle): ?string => preg_match('/\|\s*'.preg_quote($libelle, '/').'\s*\|\s*([^|]+?)\s*\|/u', $sortie, $m) === 1 ? $m[1] : null;
    expect($valeur('Lignes d\'inscription'))->toBe('25')
        ->and($valeur('Sans année ni niveau'))->toBe('2')
        ->and($valeur('Doublons'))->toBe('1')
        ->and($valeur('Fiches sans inscription datée'))->toBe('2')
        ->and($valeur('Lignes négatives (colonne scolarité)'))->toBe('2')
        ->and($valeur('… avec contrepartie positive'))->toBe('1')
        ->and($valeur('Lignes au total nul'))->toBe('1')
        ->and($valeur('Dates de reçu dans le futur'))->toBe('1')
        ->and($valeur('Dates de reçu avant 2013'))->toBe('1')
        ->and($valeur('Dates de reçu vides ou invalides'))->toBe('1')
        ->and($valeur('Dates hors de l\'année académique à un an près'))->toBe('3')
        ->and($valeur('Groupes de doublons exacts'))->toBe('1')
        ->and($valeur('Lignes sur un niveau sans inscription'))->toBe('2')
        ->and($valeur('Couples (programme, auditeur) seulement dans l\'historique'))->toBe('3')
        ->and($valeur('… dont le programme existe (reconstituables)'))->toBe('2')
        ->and($valeur('Notes hors de 0 à 20'))->toBe('1')
        ->and($valeur('Rattrapages saisis non appliqués (ok = 0, rattrapage > 0)'))->toBe('1')
        ->and($valeur('Matricules préfixés zz'))->toBe('1')
        ->and($valeur('Matricules normalisés portés par plusieurs fiches'))->toBe('1')
        ->and($valeur('Collisions sans les zéros de tête (groupes)'))->toBe('1')
        ->and($valeur('Dates « né vers »'))->toBe('1')
        ->and($valeur('Notes 2026-2027, UE IPS03'))->toBe('1');
});

it('refuse l\'inventaire sans transit chargé', function (): void {
    ChargementTransit::purger();
    $this->artisan('acma:inventaire')->expectsOutputToContain('Transit ACMA absent')->assertFailed();
});
