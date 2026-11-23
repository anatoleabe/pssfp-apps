<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\DocumentDelivre;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\User;
use App\Services\Lmd\DocumentsOfficiels;
use App\Services\Lmd\ImportMaquettes;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

beforeEach(function (): void {
    Storage::fake(CandidatureObjectStorage::DISK);
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    Maquette::query()->update(['statut' => Maquette::VALIDEE, 'validee_at' => now()]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    $this->bareme = BaremeScolarite::query()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'montant_inscription' => 185000,
        'montant_tranche1' => 500000, 'montant_tranche2' => 500000, 'tranche1_echeance' => now()->addMonth()->toDateString()]);
    $this->auditeur = Auditeur::factory()->campagneCommune()->create();
    $this->auditeur->candidature->forceFill(['nom' => 'Essai', 'prenom' => 'Claire', 'genre' => 'F', 'civilite' => 'Mme',
        'date_naissance' => '1990-03-20', 'lieu_naissance' => 'Bafia'])->save();
    $this->inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => $this->auditeur->id, 'bareme_id' => $this->bareme->id]);
    $this->documents = app(DocumentsOfficiels::class);
    // Texte du document : la vue reçoit les mêmes données que le PDF scellé.
    $this->texte = function (string $vue, callable $delivrer): string {
        $donnees = [];
        View::composer($vue, function ($v) use (&$donnees): void {
            $donnees = $v->getData();
        });
        $delivrer();

        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(view($vue, $donnees)->render()), ENT_QUOTES));
    };
});

it('rédige le certificat de scolarité selon le modèle officiel, signé du Président du Comité de Pilotage', function (): void {
    $texte = ($this->texte)('pdf.lmd.certificat-scolarite', fn () => $this->documents->delivrerCertificat($this->auditeur->refresh(), $this->agent));

    expect($texte)->toContain('Je soussigné, le Président du Comité de Pilotage')
        ->toContain('certifie que l\'étudiante : ESSAI Claire')
        ->toContain('née le 20 mars 1990 à BAFIA')
        ->toContain('est régulièrement inscrite au Programme Supérieur de Spécialisation en Finances Publiques (PSSFP)')
        ->toContain('année académique 2026-2027, cycle MASTER EN FINANCES PUBLIQUES, niveau 1.')
        ->not->toContain('Chef de la scolarité');
});

it('délivre une seule attestation de recherche valide, avec le thème et l\'encadreur', function (): void {
    $data = ['theme' => 'La qualité de la dépense publique', 'encadreur' => 'Dr Essai Paul'];
    $texte = ($this->texte)('pdf.lmd.attestation-recherche', fn () => $this->documents->delivrerAttestationRecherche($this->auditeur->refresh(), $data, $this->agent));
    $document = DocumentDelivre::query()->where('type', DocumentDelivre::ATTESTATION_RECHERCHE)->sole();

    expect($document->numero)->toBe('AT-2026-000001')->and($document->delivre_par)->toBe($this->agent->id)
        ->and($texte)->toContain('atteste que Mme ESSAI Claire')->toContain('est inscrite au cycle de Master')
        ->toContain('elle effectue ses recherches sur le thème : « La qualité de la dépense publique », sous l\'encadrement de Dr Essai Paul')
        ->and(fn () => $this->documents->delivrerAttestationRecherche($this->auditeur, $data, $this->agent))->toThrow(InvalidArgumentException::class, 'existe déjà')
        ->and(fn () => $this->documents->delivrerAttestationRecherche($this->auditeur, $data, ($this->compte)('service_financier')))->toThrow(HttpException::class);

    $this->documents->revoquer($document, 'Thème modifié par l\'encadreur', ($this->compte)('scolarite_responsable'));
    expect(fn () => $this->documents->delivrerAttestationRecherche($this->auditeur, ['theme' => '', 'encadreur' => 'Dr Essai Paul'], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'Indiquez le thème')
        ->and($this->documents->delivrerAttestationRecherche($this->auditeur, $data, $this->agent)->numero)->toBe('AT-2026-000002');
});

it('délivre autant de lettres de stage que de structures d\'accueil', function (): void {
    $data = ['destinataire' => 'Monsieur le Directeur Général de la Structure d\'essai', 'appel' => 'Monsieur le Directeur Général',
        'nature' => 'stage d\'imprégnation', 'duree' => 'trois (03) mois', 'theme' => 'Le contrôle des marchés'];
    $texte = ($this->texte)('pdf.lmd.lettre-stage', fn () => $this->documents->delivrerLettreStage($this->auditeur->refresh(), $data, $this->agent));
    $seconde = $this->documents->delivrerLettreStage($this->auditeur, [...$data, 'destinataire' => 'Madame la Directrice de l\'Autre structure', 'theme' => null], $this->agent);

    expect($texte)->toContain('MONSIEUR LE DIRECTEUR GÉNÉRAL DE LA STRUCTURE D\'ESSAI')
        ->toContain('mettre à votre disposition Madame ESSAI Claire')->toContain('Auditrice en Master 1 Finances Publiques')
        ->toContain('un stage d\'imprégnation dans votre structure, pour une période de trois (03) mois, sur le thème : « Le contrôle des marchés ».')
        ->and($seconde->numero)->toBe('LS-2026-000002')
        ->and(DocumentDelivre::query()->where('type', DocumentDelivre::LETTRE_STAGE)->whereNull('revoque_at')->count())->toBe(2)
        ->and(fn () => $this->documents->delivrerLettreStage($this->auditeur, [...$data, 'duree' => ''], $this->agent))->toThrow(InvalidArgumentException::class, 'Indiquez la durée');
});

it('refuse les documents administratifs à un auditeur en retard de paiement ou sorti de la formation', function (): void {
    $this->bareme->update(['tranche1_echeance' => now()->subMonth()->toDateString()]);
    $lettre = ['destinataire' => 'Monsieur le Directeur', 'appel' => 'Monsieur le Directeur', 'nature' => 'stage académique', 'duree' => 'deux mois'];
    expect(fn () => $this->documents->delivrerLettreStage($this->auditeur->refresh(), $lettre, $this->agent))->toThrow(InvalidArgumentException::class, 'en retard');

    $this->bareme->update(['tranche1_echeance' => now()->addMonth()->toDateString()]);
    $this->auditeur->forceFill(['statut' => Auditeur::STATUT_ABANDON])->save();
    expect(fn () => $this->documents->delivrerAttestationRecherche($this->auditeur->refresh(), ['theme' => 'Un thème', 'encadreur' => 'Dr Essai'], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'sorti de la formation')
        ->and(DocumentDelivre::query()->count())->toBe(0);
});

it('propose les trois documents sur la fiche à la scolarité seulement', function (): void {
    $this->actingAs($this->agent);
    Livewire::test(ViewAuditeur::class, ['record' => $this->auditeur->getRouteKey()])
        ->assertActionVisible('certificatScolarite')->assertActionVisible('attestationRecherche')
        ->callAction('lettreStage', ['destinataire' => 'Monsieur le Directeur', 'appel' => 'Monsieur le Directeur', 'nature' => 'stage académique', 'duree' => 'deux mois'])
        ->assertHasNoActionErrors();
    expect(DocumentDelivre::query()->where('type', DocumentDelivre::LETTRE_STAGE)->count())->toBe(1);

    $this->actingAs(($this->compte)('service_financier'));
    Livewire::test(ViewAuditeur::class, ['record' => $this->auditeur->getRouteKey()])->assertActionHidden('attestationRecherche')->assertActionHidden('lettreStage');
});
