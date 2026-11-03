<?php

declare(strict_types=1);

use App\Filament\Resources\CampagneCandidatureResource\Pages\EditCampagneCandidature;
use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\DocumentsAuditeurs\DocumentsAuditeurs;
use App\Services\DocumentsAuditeurs\DocumentsIncomplets;
use App\Services\DocumentsAuditeurs\FraisInscription;
use App\Services\DocumentsAuditeurs\LettreAdmissionWord;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('filament', 'documents-auditeurs');

/*
 * Documents remis aux auditeurs : accès FOAD et lettres d'admission en Word
 * (une page par auditeur), et pointage des frais d'inscription.
 */

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.foad_url', 'https://foad.pssfp.org');
    Storage::fake(CandidatureObjectStorage::DISK);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);

    Storage::disk(CandidatureObjectStorage::DISK)->put('signatures/president.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $this->campagne = CampagneCandidature::factory()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'],
        'promotion_numero' => 14,
        'signature_lettres_path' => 'signatures/president.png',
        'parametres_lettres' => [
            'date_lettres' => '2026-11-03', 'rentree_date' => '2026-11-17',
            'rentree_lieu' => 'à l\'Auditorium 300 du MINEPAT',
            'frais_tranche1_date' => '2026-12-15', 'frais_tranche2_date' => '2027-02-15',
        ],
    ]);
    $retenu = fn (array $attributs) => Candidature::factory()->forCampagne($this->campagne)->submitted()->create(
        $attributs + ['statut' => Candidature::STATUT_ACCEPTE]);
    $this->alice = $retenu(['nom' => 'Mengue', 'prenom' => 'Alice', 'civilite' => 'Mme']);
    $this->bruno = $retenu(['nom' => 'Ateba', 'prenom' => 'Bruno', 'civilite' => 'M.']);
    $this->refuse = Candidature::factory()->forCampagne($this->campagne)->submitted()->create(['nom' => 'Zang', 'statut' => Candidature::STATUT_REFUSE]);

    $this->agent = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']),
        fn (User $u) => $u->assignRole($role),
    );
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->agent)('admin'));
    $this->alice = $this->alice->fresh();
    $this->bruno = $this->bruno->fresh();
});

/** Texte du document Word, sections comptées. */
function lireWord(string $contenu): array
{
    $fichier = tempnam(sys_get_temp_dir(), 'test-docx-');
    file_put_contents($fichier, $contenu);
    $zip = new ZipArchive;
    $zip->open($fichier);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($fichier);

    return ['texte' => html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml))), 'pages' => substr_count($xml, '<w:sectPr')];
}

it('réunit les accès FOAD de toute la promotion, une page par retenu, dans l\'ordre des matricules', function (): void {
    $fichier = app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ACCES, ($this->agent)('admin'));
    $word = lireWord($fichier['contenu']);

    expect($fichier['nom'])->toBe('acces-foad-p14.docx')
        ->and($fichier['pages'])->toBe(2)
        ->and($word['pages'])->toBe(2)
        ->and($word['texte'])->toContain('ATEBA Bruno')->toContain('MENGUE Alice')
        ->toContain($this->alice->moodle_password)->toContain(mb_strtolower($this->alice->matricule))
        ->toContain('foad.pssfp.org')->toContain('03 novembre 2026')
        ->toContain('VOTRE ESPACE AUDITEUR')->toContain('apply.pssfp.org')->toContain('Matricule')->not->toContain('FMI')
        ->not->toContain('ZANG')
        ->and(strpos($word['texte'], 'ATEBA'))->toBeLessThan(strpos($word['texte'], 'MENGUE'));
});

it('accorde la lettre d\'admission au genre et reprend rentrée, frais et total', function (): void {
    $fichier = app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ADMISSION, ($this->agent)('admin'));
    $texte = lireWord($fichier['contenu'])['texte'];

    expect($texte)->toContain('Madame MENGUE Alice')->toContain('vous êtes admise en première année')
        ->toContain('Monsieur ATEBA Bruno')->toContain('vous êtes admis en première année')
        ->toContain('le 17 novembre 2026 dès 08:00 heures précise')->toContain('à l\'Auditorium 300 du MINEPAT')
        ->toContain("185\u{00A0}000")->toContain("1\u{00A0}185\u{00A0}000")->toContain('15 décembre 2026')
        ->toContain('14e promotion, année académique 2026-2027')
        ->toContain('N° 26/ '.$this->alice->rang_retenu.' L/MINFI/SG/PSSFP/PCP/UAAF')
        ->not->toContain($this->alice->moodle_password);
});

it('refuse de générer tant qu\'une date manque, sans rien journaliser', function (): void {
    $this->campagne->update(['parametres_lettres' => ['date_lettres' => '2026-11-03'], 'signature_lettres_path' => null]);

    try {
        app(DocumentsAuditeurs::class)->generer($this->campagne->fresh(), ParametresLettres::DOCUMENT_ADMISSION, ($this->agent)('admin'));
        $this->fail('Génération attendue en échec.');
    } catch (DocumentsIncomplets $e) {
        expect($e->manquants)->toContain('Date de la rentrée solennelle', 'Lieu de la rentrée', 'Échéance de la 1re tranche')
            ->not->toContain('Date des lettres', 'Montant de la 1re tranche', 'Signature et cachet');
    }
    expect(Activity::query()->where('event', 'documents_auditeurs')->count())->toBe(0);
});

it('réserve les accès FOAD à l\'administration et journalise sans mot de passe', function (): void {
    $comite = ($this->agent)('admission_committee');
    expect(fn () => app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ACCES, $comite))
        ->toThrow(HttpException::class);

    app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ADMISSION, $comite);
    app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ACCES, ($this->agent)('admin'));

    $journal = Activity::query()->where('event', 'documents_auditeurs')->get();
    expect($journal)->toHaveCount(2)
        ->and($journal->pluck('properties.document')->all())->toBe([ParametresLettres::DOCUMENT_ADMISSION, ParametresLettres::DOCUMENT_ACCES])
        ->and(json_encode($journal->pluck('properties')))->not->toContain($this->alice->moodle_password);
});

it('génère pour une sélection ou une seule personne', function (): void {
    $fichier = app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ADMISSION, ($this->agent)('admin'),
        [$this->alice->id, $this->refuse->id]);

    expect($fichier['pages'])->toBe(1)
        ->and($fichier['nom'])->toBe('lettre-admission-'.mb_strtolower($this->alice->matricule).'.docx')
        ->and(lireWord($fichier['contenu'])['texte'])->toContain('MENGUE Alice')->not->toContain('ATEBA');
});

it('télécharge depuis la liste des candidatures et refuse une sélection de deux promotions', function (): void {
    $this->actingAs(($this->agent)('admin'));
    $autre = Candidature::factory()->forCampagne(CampagneCandidature::factory()->create())->submitted()->create();

    $this->livewire(ListCandidatures::class)
        ->callTableAction('lettreAdmission', $this->alice)
        ->assertFileDownloaded('lettre-admission-'.mb_strtolower($this->alice->matricule).'.docx');

    $this->livewire(ListCandidatures::class)
        // Filtre par défaut sur la campagne ouverte : l'autre promotion doit être visible.
        ->removeTableFilter('campagne_id')
        ->callTableBulkAction('lettresAdmissionBulk', [$this->alice, $autre])
        ->assertNotified('Une seule promotion à la fois');
});

it('signale depuis la campagne ce qu\'il reste à saisir', function (): void {
    $this->campagne->update(['parametres_lettres' => ['date_lettres' => '2026-11-03']]);
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(EditCampagneCandidature::class, ['record' => $this->campagne->getRouteKey()])
        ->callAction('lettresAdmission')
        ->assertNotified('Document impossible à générer');
});

it('enregistre les paramètres des lettres depuis la campagne', function (): void {
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(EditCampagneCandidature::class, ['record' => $this->campagne->getRouteKey()])
        ->fillForm(['parametres_lettres.rentree_heure' => '9 heures', 'parametres_lettres.frais_tranche1_montant' => 450000])
        ->call('save')
        ->assertHasNoFormErrors();

    $p = ParametresLettres::pour($this->campagne->fresh());
    expect($p->texte('rentree_heure'))->toBe('9 heures')
        ->and($p->montant('frais_tranche1_montant'))->toBe("450\u{00A0}000")
        ->and($p->totalFrais())->toBe("1\u{00A0}135\u{00A0}000")
        ->and($p->texte('reference_admission'))->toBe(config('documents_auditeurs.defauts.reference_admission'));
});

it('pointe les frais d\'inscription des seuls retenus, et annule le pointage', function (): void {
    $admin = ($this->agent)('admin');
    $this->actingAs($admin);

    $this->livewire(ListCandidatures::class)
        ->callTableBulkAction('fraisInscriptionPayesBulk', [$this->alice, $this->refuse])
        ->assertNotified('Frais d\'inscription pointés');

    expect($this->alice->fresh()->frais_inscription_payes_at)->not->toBeNull()
        ->and($this->alice->fresh()->frais_inscription_payes_by)->toBe($admin->id)
        ->and($this->refuse->fresh()->frais_inscription_payes_at)->toBeNull()
        ->and(Activity::query()->where('event', 'frais_inscription_payes')->count())->toBe(1);

    $this->livewire(ListCandidatures::class)
        ->assertTableActionHidden('fraisInscriptionPayes', $this->alice->fresh())
        ->callTableAction('fraisInscriptionAnnules', $this->alice->fresh());
    expect($this->alice->fresh()->frais_inscription_payes_at)->toBeNull();
});

it('réserve le pointage des frais d\'inscription à l\'administration', function (): void {
    $this->actingAs(($this->agent)('admission_committee'));

    $this->livewire(ListCandidatures::class)
        ->assertTableActionHidden('fraisInscriptionPayes', $this->alice)
        ->assertTableBulkActionHidden('fraisInscriptionPayesBulk');
    expect(fn () => app(FraisInscription::class)->pointer(collect([$this->alice]), true, auth()->user()))
        ->toThrow(HttpException::class);
});

it('n\'inclut ni les retirés, ni les retenus sans matricule, ni un dossier d\'une autre promotion', function (): void {
    $this->bruno->update(['withdrawn_at' => now()]);
    $sansMatricule = Candidature::factory()->forCampagne($this->campagne)->submitted()->create(['nom' => 'Owona', 'statut' => Candidature::STATUT_ACCEPTE]);
    $ailleurs = Candidature::factory()->forCampagne(CampagneCandidature::factory()->create())->submitted()->create(['nom' => 'Biya', 'statut' => Candidature::STATUT_ACCEPTE]);

    $tous = app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ADMISSION, ($this->agent)('admin'));
    $choisis = app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ADMISSION, ($this->agent)('admin'),
        [$this->alice->id, $ailleurs->id, $sansMatricule->id]);

    expect($tous['pages'])->toBe(1)->and($choisis['pages'])->toBe(1)
        ->and(lireWord($tous['contenu'])['texte'])->toContain('MENGUE')->not->toContain('ATEBA')->not->toContain('OWONA')->not->toContain('BIYA');
});

it('exclut des accès FOAD le retenu sans mot de passe Moodle', function (): void {
    $this->bruno->forceFill(['moodle_password' => null])->save();

    $fichier = app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ACCES, ($this->agent)('admin'));

    expect($fichier['pages'])->toBe(1)->and(lireWord($fichier['contenu'])['texte'])->not->toContain('ATEBA');
});

it('demande de redéposer une signature introuvable ou hors de son dossier', function (string $chemin): void {
    $this->campagne->update(['signature_lettres_path' => $chemin]);

    expect(fn () => app(DocumentsAuditeurs::class)->generer($this->campagne->fresh(), ParametresLettres::DOCUMENT_ADMISSION, ($this->agent)('admin')))
        ->toThrow(DocumentsIncomplets::class);
})->with(['signatures/absente.png', 'candidatures/piece-identite.png', 'signatures/../candidatures/x.png']);

it('reprend le modèle officiel de la 14e promotion : rentrée sur deux jours, sans paragraphe sur la convention', function (): void {
    $this->campagne->update(['parametres_lettres' => array_merge($this->campagne->parametres_lettres, [
        'rentree_date' => '2026-11-10', 'rentree_date_fin' => '2026-11-11', 'frais_tranche1_date' => '2026-11-09',
    ])]);

    $texte = lireWord(app(DocumentsAuditeurs::class)->generer($this->campagne->fresh(), ParametresLettres::DOCUMENT_ADMISSION, ($this->agent)('admin'))['contenu'])['texte'];

    expect($texte)->toContain('aura lieu du 10 au 11 novembre 2026 dès 08:00 heures précise')
        ->toContain('09 novembre 2026')->toContain('03 novembre 2026')
        ->not->toContain('mis sur pied');
});

it('écrit une rentrée à cheval sur deux mois et ignore une date de fin qui ne suit pas le début', function (string $debut, ?string $fin, array $attendu): void {
    $this->campagne->update(['parametres_lettres' => array_merge($this->campagne->parametres_lettres, [
        'rentree_date' => $debut, 'rentree_date_fin' => $fin,
    ])]);

    expect(ParametresLettres::pour($this->campagne->fresh())->periodeRentree())->toBe($attendu);
})->with([
    'même mois' => ['2026-11-10', '2026-11-11', ['du', '10 au 11 novembre 2026']],
    'deux mois' => ['2026-11-30', '2026-12-01', ['du', '30 novembre au 1er décembre 2026']],
    'deux années' => ['2026-12-31', '2027-01-02', ['du', '31 décembre 2026 au 02 janvier 2027']],
    'sans fin' => ['2026-11-10', null, ['le', '10 novembre 2026']],
    'fin identique' => ['2026-11-10', '2026-11-10', ['le', '10 novembre 2026']],
    'fin antérieure' => ['2026-11-10', '2026-11-09', ['le', '10 novembre 2026']],
]);

it('génère les deux documents sans scan de signature, pour une signature à la main', function (string $document): void {
    $this->campagne->update(['signature_lettres_path' => null]);

    $contenu = app(DocumentsAuditeurs::class)->generer($this->campagne->fresh(), $document, ($this->agent)('admin'))['contenu'];
    $fichier = tempnam(sys_get_temp_dir(), 'test-docx-');
    file_put_contents($fichier, $contenu);
    $zip = new ZipArchive;
    $zip->open($fichier);
    $documentXml = (string) $zip->getFromName('word/document.xml');
    $relations = (string) $zip->getFromName('word/_rels/document.xml.rels');
    $zip->close();
    unlink($fichier);

    expect($documentXml)->toContain('Le Président du Comité de Pilotage')->not->toContain('${')->not->toContain('name="Signature"')
        ->and($relations)->not->toContain('signature')
        ->and(simplexml_load_string($documentXml))->not->toBeFalse();
})->with([ParametresLettres::DOCUMENT_ADMISSION, ParametresLettres::DOCUMENT_ACCES]);

it('donne à la lettre d\'accès le même en-tête que le modèle officiel', function (): void {
    $contenu = app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ACCES, ($this->agent)('admin'))['contenu'];
    $fichier = tempnam(sys_get_temp_dir(), 'test-docx-');
    file_put_contents($fichier, $contenu);
    $zip = new ZipArchive;
    $zip->open($fichier);
    $documentXml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($fichier);

    expect($documentXml)->toContain('w:ascii="Tahoma"')->toContain('REPUBLIQUE DU CAMEROUN')->toContain('Peace- Work- Fatherland')
        ->toContain('w:tblpPr')->toContain('w:vertAnchor="page"')->not->toContain('RÉPUBLIQUE DU CAMEROUN');
});

it('génère la lettre depuis le modèle officiel : en-tête, polices, une section par auditeur, aucun repère oublié', function (): void {
    $contenu = app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ADMISSION, ($this->agent)('admin'))['contenu'];
    $fichier = tempnam(sys_get_temp_dir(), 'test-docx-');
    file_put_contents($fichier, $contenu);
    $zip = new ZipArchive;
    $zip->open($fichier);
    $document = (string) $zip->getFromName('word/document.xml');
    $pied = (string) $zip->getFromName('word/footer1.xml');
    $relations = (string) $zip->getFromName('word/_rels/document.xml.rels');
    $signature = $zip->getFromName('word/media/signature.png');
    $zip->close();
    unlink($fichier);

    preg_match_all('~<wp:docPr id="(\d+)"~', $document, $images);

    expect($document)->toContain('w:ascii="Tahoma"')->toContain('REPUBLIQUE DU CAMEROUN')->toContain('Paix- Travail- Patrie')
        ->toContain('w:color w:val="FF0000"')->toContain('w:top="566"')
        ->not->toContain('${')
        ->and(substr_count($document, '<w:sectPr'))->toBe(2)
        // Deux lettres : logo et signature chacune, identifiants d'image tous distincts.
        ->and($images[1])->toHaveCount(4)->and(array_unique($images[1]))->toHaveCount(4)
        ->and(simplexml_load_string($document))->not->toBeFalse()
        ->and($pied)->toContain('contact@pssfp.org')->not->toContain('admissions@')->not->toContain('${')
        ->and($relations)->toContain('media/signature.png')
        ->and($signature)->not->toBeFalse();
});

it('reproduit toutes les copies et échappe les caractères spéciaux des paramètres', function (): void {
    $this->campagne->update(['parametres_lettres' => array_merge($this->campagne->parametres_lettres, [
        'copies' => ['Doyen & Vice-Doyen', 'Intéressé(e)', 'Scolarité', 'Archives'],
    ])]);

    $word = lireWord(app(DocumentsAuditeurs::class)->generer($this->campagne->fresh(), ParametresLettres::DOCUMENT_ADMISSION, ($this->agent)('admin'))['contenu']);

    expect($word['texte'])->toContain('– Doyen & Vice-Doyen')->toContain('– Scolarité')->toContain('– Archives');
});

it('refuse de produire une lettre avec une signature qui n\'est pas une image', function (): void {
    $p = ParametresLettres::pour($this->campagne);
    $texte = tempnam(sys_get_temp_dir(), 'pas-une-image-');
    file_put_contents($texte, 'texte');

    try {
        app(LettreAdmissionWord::class)->generer($p, [], $texte);
        $this->fail('La signature invalide aurait dû être refusée.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Signature illisible');
    } finally {
        unlink($texte);
    }
});

it('garde contact@pssfp.org comme adresse officielle dans le pied des lettres d\'accès', function (): void {
    expect(config('documents_auditeurs.pied_de_page'))->toContain('contact@pssfp.org')->not->toContain('admissions@');
});

it('accorde au genre quand la civilité manque, et écrit le 1er du mois', function (): void {
    $this->alice->update(['civilite' => null, 'genre' => 'F']);
    $this->bruno->update(['civilite' => 'Mlle']);
    $this->campagne->update(['parametres_lettres' => array_merge($this->campagne->parametres_lettres, ['rentree_date' => '2026-12-01'])]);

    $texte = lireWord(app(DocumentsAuditeurs::class)->generer($this->campagne->fresh(), ParametresLettres::DOCUMENT_ADMISSION, ($this->agent)('admin'))['contenu'])['texte'];

    expect($texte)->toContain('Madame MENGUE Alice')->toContain('Mademoiselle ATEBA Bruno')
        ->toContain('vous êtes admise')->not->toContain('vous êtes admis ')
        ->toContain('1er décembre 2026');
});

it('cache au comité d\'admission tout ce qui produit des mots de passe', function (): void {
    $this->actingAs(($this->agent)('admission_committee'));

    $this->livewire(ListCandidatures::class)
        ->assertTableActionHidden('lettreAccesFoad', $this->alice)
        ->assertTableActionVisible('lettreAdmission', $this->alice)
        ->assertTableBulkActionHidden('accesFoadBulk');
});

it('inscrit l\'export des accès FOAD sur la fiche de chaque auditeur', function (): void {
    $admin = ($this->agent)('admin');
    app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ACCES, $admin);

    $traces = Activity::query()->where('event', 'acces_foad_exporte')->get();
    expect($traces->pluck('subject_id')->sort()->values()->all())->toBe(collect([$this->alice->id, $this->bruno->id])->sort()->values()->all())
        ->and($traces->pluck('causer_id')->unique()->all())->toBe([$admin->id]);
});
