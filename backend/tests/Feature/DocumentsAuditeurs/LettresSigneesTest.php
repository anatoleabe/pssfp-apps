<?php

declare(strict_types=1);

use App\Filament\Resources\CandidatureResource\LettresSigneesActions;
use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\DocumentsAuditeurs\LettresAdmissionSignees;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('filament', 'documents-auditeurs');

function pdfEssai(string $nom, string $corps = 'lettre'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nom, "%PDF-1.4\n{$corps}");
}

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);

    Storage::fake(CandidatureObjectStorage::DISK);
    Storage::disk(CandidatureObjectStorage::DISK)
        ->buildTemporaryUrlsUsing(fn (string $chemin, $expiration, array $options): string => 'https://signe.test/'.$chemin.'?'.http_build_query($options));

    $this->agent = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']),
        fn (User $u) => $u->assignRole($role),
    );
    $this->admin = ($this->agent)('admin');
    $this->campagne = CampagneCandidature::factory()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14,
    ]);
    $this->retenu = fn (array $champs = []): Candidature => Candidature::factory()->forCampagne($this->campagne)->submitted()->create($champs + [
        'statut' => Candidature::STATUT_ACCEPTE,
        'user_id' => User::factory()->create(['email' => 'auditeur-'.Str::random(8).'@example.org'])->id,
    ]);
    $this->alice = ($this->retenu)(['nom' => 'Abega', 'prenom' => 'Alice']);
    $this->bruno = ($this->retenu)(['nom' => 'Biya', 'prenom' => 'Bruno']);
    $this->sansMatricule = null;
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin);
    $this->alice->refresh();
    $this->bruno->refresh();
    $this->service = app(LettresAdmissionSignees::class);
});

describe('dépôt en lot', function (): void {
    it('rattache chaque fichier au dossier dont le matricule est dans son nom', function (): void {
        $bilan = $this->service->deposerLot($this->campagne, [
            pdfEssai($this->alice->matricule.'.pdf', 'alice'),
            pdfEssai(mb_strtolower((string) $this->bruno->matricule).'.PDF', 'bruno'),
        ], $this->admin);

        expect($bilan)->toMatchArray(['deposees' => 2, 'deja' => [], 'inconnus' => [], 'invalides' => [], 'manquantes' => 0]);

        $this->alice->refresh();
        $this->bruno->refresh();
        expect($this->alice->lettre_admission_path)->not->toBe($this->bruno->lettre_admission_path)
            ->and($this->alice->lettre_admission_path)->not->toContain((string) $this->alice->matricule)
            ->and($this->alice->lettre_admission_deposee_par)->toBe($this->admin->id)
            ->and($this->alice->lettre_admission_deposee_at)->not->toBeNull()
            ->and(Storage::disk(CandidatureObjectStorage::DISK)->get($this->alice->lettre_admission_path))->toContain('alice')
            ->and(Storage::disk(CandidatureObjectStorage::DISK)->get($this->bruno->lettre_admission_path))->toContain('bruno');
    });

    it('refuse un nom approchant plutôt que de risquer la lettre d\'un autre', function (string $gabarit): void {
        $bilan = $this->service->deposerLot($this->campagne, [pdfEssai(sprintf($gabarit, $this->alice->matricule))], $this->admin);

        expect($bilan['deposees'])->toBe(0)
            ->and($bilan['inconnus'])->toHaveCount(1)
            ->and($this->alice->fresh()->lettre_admission_path)->toBeNull();
    })->with(['%s (1).pdf', 'lettre-%s.pdf', '%s_signee.pdf', '%s.pdf.pdf', '%s .pdf', ' %s.pdf']);

    it('ignore un matricule d\'une autre promotion', function (): void {
        $autre = CampagneCandidature::factory()->create(['promotion_numero' => 15]);
        $etranger = Candidature::factory()->forCampagne($autre)->submitted()->create(['statut' => Candidature::STATUT_ACCEPTE]);
        app(AttributionMatricules::class)->attribuer($autre, '27', $this->admin);

        $bilan = $this->service->deposerLot($this->campagne, [pdfEssai($etranger->fresh()->matricule.'.pdf')], $this->admin);

        expect($bilan['inconnus'])->toHaveCount(1)
            ->and($etranger->fresh()->lettre_admission_path)->toBeNull();
    });

    it('ignore un dossier retiré', function (): void {
        $this->alice->forceFill(['withdrawn_at' => now()])->save();

        $bilan = $this->service->deposerLot($this->campagne, [pdfEssai($this->alice->matricule.'.pdf')], $this->admin);

        expect($bilan['inconnus'])->toHaveCount(1);
    });

    it('refuse un fichier qui n\'est pas un PDF, quel que soit son nom', function (): void {
        $bilan = $this->service->deposerLot($this->campagne, [
            UploadedFile::fake()->createWithContent($this->alice->matricule.'.pdf', '<html>pas un pdf</html>'),
        ], $this->admin);

        expect($bilan['invalides'])->toHaveCount(1)
            ->and($this->alice->fresh()->lettre_admission_path)->toBeNull();
    });

    it('ne remplace pas une lettre déjà déposée, pour pouvoir relancer un lot', function (): void {
        $this->service->deposerLot($this->campagne, [pdfEssai($this->alice->matricule.'.pdf', 'premiere')], $this->admin);
        $chemin = $this->alice->fresh()->lettre_admission_path;

        $bilan = $this->service->deposerLot($this->campagne, [
            pdfEssai($this->alice->matricule.'.pdf', 'seconde'),
            pdfEssai($this->bruno->matricule.'.pdf'),
        ], $this->admin);

        expect($bilan['deposees'])->toBe(1)
            ->and($bilan['deja'])->toBe([$this->alice->matricule.'.pdf'])
            ->and($this->alice->fresh()->lettre_admission_path)->toBe($chemin)
            ->and(Storage::disk(CandidatureObjectStorage::DISK)->get($chemin))->toContain('premiere');
    });

    it('prend la première version quand le lot contient deux fois le même matricule', function (): void {
        $bilan = $this->service->deposerLot($this->campagne, [
            pdfEssai($this->alice->matricule.'.pdf', 'premiere'),
            pdfEssai(mb_strtolower((string) $this->alice->matricule).'.pdf', 'seconde'),
        ], $this->admin);

        expect($bilan['deposees'])->toBe(1)->and($bilan['deja'])->toHaveCount(1);
    });

    it('compte les lettres encore manquantes et journalise sans nom ni chemin', function (): void {
        $bilan = $this->service->deposerLot($this->campagne, [pdfEssai($this->alice->matricule.'.pdf')], $this->admin);

        expect($bilan['manquantes'])->toBe(1);
        $journal = Activity::query()->where('event', 'lettres_admission_deposees')->sole();
        expect($journal->properties['deposees'])->toBe(1)
            ->and((string) json_encode($journal->properties))->not->toContain('lettres-admission/');
    });

    it('refuse un lot trop grand', function (): void {
        $fichiers = array_fill(0, LettresAdmissionSignees::LOT_MAX + 1, pdfEssai('x.pdf'));

        expect(fn () => $this->service->deposerLot($this->campagne, $fichiers, $this->admin))->toThrow(InvalidArgumentException::class);
    });

    it('réserve le dépôt aux agents habilités', function (string $role): void {
        $this->service->deposerLot($this->campagne, [pdfEssai($this->alice->matricule.'.pdf')], ($this->agent)($role));
    })->with(['admission_committee', 'receptionniste', 'librarian'])->throws(HttpException::class);
});

describe('remplacement d\'une lettre', function (): void {
    it('remplace la lettre d\'un dossier et retire l\'ancien fichier', function (): void {
        $this->service->deposerLot($this->campagne, [pdfEssai($this->alice->matricule.'.pdf', 'ancienne')], $this->admin);
        $ancien = $this->alice->fresh()->lettre_admission_path;

        $this->service->deposer($this->alice->fresh(), pdfEssai($this->alice->matricule.'.pdf', 'nouvelle'), $this->admin);

        $this->alice->refresh();
        expect($this->alice->lettre_admission_path)->not->toBe($ancien)
            ->and(Storage::disk(CandidatureObjectStorage::DISK)->get($this->alice->lettre_admission_path))->toContain('nouvelle')
            ->and(Storage::disk(CandidatureObjectStorage::DISK)->exists($ancien))->toBeFalse();
    });

    it('refuse le fichier d\'un autre auditeur sur la ligne d\'un dossier', function (string $gabarit): void {
        $nom = sprintf($gabarit, $this->bruno->matricule);

        expect(fn () => $this->service->deposer($this->alice, pdfEssai($nom), $this->admin))
            ->toThrow(InvalidArgumentException::class, $this->alice->matricule);
        expect($this->alice->fresh()->lettre_admission_path)->toBeNull();
    })->with(['%s.pdf', 'scan.pdf']);

    it('retire une lettre rattachée par erreur, et l\'auditeur ne la voit plus', function (): void {
        $this->service->deposer($this->alice, pdfEssai($this->alice->matricule.'.pdf'), $this->admin);
        $chemin = $this->alice->fresh()->lettre_admission_path;

        $this->service->retirer($this->alice->fresh(), $this->admin);

        $this->alice->refresh();
        expect($this->alice->lettre_admission_path)->toBeNull()
            ->and($this->alice->lettre_admission_deposee_at)->toBeNull()
            ->and($this->alice->lettre_admission_deposee_par)->toBeNull()
            ->and(Storage::disk(CandidatureObjectStorage::DISK)->exists($chemin))->toBeFalse()
            ->and(Activity::query()->where('event', 'lettre_admission_retiree')->count())->toBe(1);
        Sanctum::actingAs($this->alice->user, ['application:read']);
        $this->getJson('/v1/applications/me/lettre-admission')->assertNotFound();
    });

    it('réserve le retrait aux agents habilités', function (): void {
        $this->service->retirer($this->alice, ($this->agent)('admission_committee'));
    })->throws(HttpException::class);

    it('refuse un dossier sans matricule ou non retenu', function (): void {
        $refuse = Candidature::factory()->forCampagne($this->campagne)->submitted()->create(['statut' => Candidature::STATUT_REFUSE]);

        expect(fn () => $this->service->deposer($refuse, pdfEssai('x.pdf'), $this->admin))->toThrow(InvalidArgumentException::class);
    });

    it('refuse un remplacement qui n\'est pas un PDF, sans toucher à l\'existant', function (): void {
        $this->service->deposer($this->alice, pdfEssai($this->alice->matricule.'.pdf', 'bonne'), $this->admin);
        $chemin = $this->alice->fresh()->lettre_admission_path;

        expect(fn () => $this->service->deposer($this->alice->fresh(), UploadedFile::fake()->createWithContent($this->alice->matricule.'.pdf', 'texte'), $this->admin))
            ->toThrow(InvalidArgumentException::class);
        expect($this->alice->fresh()->lettre_admission_path)->toBe($chemin);
    });
});

describe('espace de l\'auditeur', function (): void {
    beforeEach(function (): void {
        $this->service->deposerLot($this->campagne, [
            pdfEssai($this->alice->matricule.'.pdf', 'alice'),
            pdfEssai($this->bruno->matricule.'.pdf', 'bruno'),
        ], $this->admin);
    });

    it('sert sa propre lettre par URL signée, même sans droits d\'inscription payés', function (): void {
        expect($this->alice->fresh()->frais_inscription_payes_at)->toBeNull();
        Sanctum::actingAs($this->alice->user, ['application:read']);

        $reponse = $this->getJson('/v1/applications/me/lettre-admission');

        $reponse->assertRedirect();
        $cible = (string) $reponse->headers->get('Location');
        expect($cible)->toContain($this->alice->fresh()->lettre_admission_path)
            ->and($cible)->not->toContain($this->bruno->fresh()->lettre_admission_path)
            ->and($cible)->toContain('attachment')
            ->and($cible)->not->toContain((string) $this->alice->matricule);
    });

    it('sert la lettre du retenu même si un dossier plus récent du compte n\'en a pas', function (array $champs): void {
        $autre = CampagneCandidature::factory()->create(['promotion_numero' => 15, 'opens_at' => now()->addDay(), 'closes_at' => now()->addYear()]);
        Candidature::factory()->forCampagne($autre)->create($champs + ['user_id' => $this->alice->user_id]);
        Sanctum::actingAs($this->alice->user, ['application:read']);

        $reponse = $this->getJson('/v1/applications/me/lettre-admission')->assertRedirect();

        expect((string) $reponse->headers->get('Location'))->toContain($this->alice->fresh()->lettre_admission_path);
    })->with([
        'refusé' => [['statut' => Candidature::STATUT_REFUSE]],
        'retiré' => [['statut' => Candidature::STATUT_ACCEPTE, 'withdrawn_at' => now()]],
    ]);

    it('ne sert jamais une lettre d\'un dossier retiré du même compte', function (): void {
        $this->alice->forceFill(['withdrawn_at' => now()])->save();
        $autre = CampagneCandidature::factory()->create(['promotion_numero' => 15]);
        Candidature::factory()->forCampagne($autre)->create(['statut' => Candidature::STATUT_ACCEPTE, 'user_id' => $this->alice->user_id]);
        Sanctum::actingAs($this->alice->user, ['application:read']);

        $this->getJson('/v1/applications/me/lettre-admission')->assertNotFound();
    });

    it('n\'expose jamais le chemin du fichier, seulement sa disponibilité', function (): void {
        Sanctum::actingAs($this->alice->user, ['application:read']);

        $reponse = $this->getJson('/v1/applications/me')->assertOk();

        expect($reponse->json('data.lettre_admission_available') ?? $reponse->json('lettre_admission_available'))->toBeTrue()
            ->and($reponse->getContent())->not->toContain('lettres-admission/');
    });

    it('répond 404 sans lettre déposée', function (): void {
        $autre = ($this->retenu)();
        Sanctum::actingAs($autre->user, ['application:read']);

        $this->getJson('/v1/applications/me/lettre-admission')->assertNotFound();
    });

    it('répond 404 à un dossier retiré', function (): void {
        $this->alice->forceFill(['withdrawn_at' => now()])->save();
        Sanctum::actingAs($this->alice->user, ['application:read']);

        $this->getJson('/v1/applications/me/lettre-admission')->assertNotFound();
    });

    it('exige une connexion et le droit de lecture', function (): void {
        $this->getJson('/v1/applications/me/lettre-admission')->assertUnauthorized();

        Sanctum::actingAs($this->alice->user, ['profile:write']);
        $this->getJson('/v1/applications/me/lettre-admission')->assertForbidden();
    });
});

describe('admin', function (): void {
    it('dépose une lettre depuis la ligne du dossier', function (): void {
        $this->actingAs($this->admin);

        $this->livewire(ListCandidatures::class)
            ->callTableAction('deposerLettreSignee', $this->alice, data: ['fichier' => pdfEssai($this->alice->matricule.'.pdf')])
            ->assertHasNoTableActionErrors();

        expect($this->alice->fresh()->lettre_admission_path)->not->toBeNull();
    });

    it('dépose un lot depuis la liste et en donne le bilan', function (): void {
        $this->actingAs($this->admin);

        $this->livewire(ListCandidatures::class)
            ->callAction('deposerLettresSignees', data: [
                'campagne_id' => $this->campagne->id,
                'fichiers' => [pdfEssai($this->alice->matricule.'.pdf'), pdfEssai('inconnu.pdf')],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        expect($this->alice->fresh()->lettre_admission_path)->not->toBeNull();
    });

    it('ne propose que les promotions déjà numérotées, même si une campagne plus récente existe', function (): void {
        $recente = CampagneCandidature::factory()->create(['opens_at' => now()->addYear(), 'closes_at' => now()->addYears(2)]);

        expect(LettresSigneesActions::promotions())->toHaveKey($this->campagne->id)
            ->and(LettresSigneesActions::promotions())->not->toHaveKey($recente->id)
            ->and(array_key_first(LettresSigneesActions::promotions()))->toBe($this->campagne->id);
    });

    it('filtre les retenus sans lettre signée', function (): void {
        $this->service->deposer($this->alice, pdfEssai($this->alice->matricule.'.pdf'), $this->admin);
        $this->actingAs($this->admin);

        $this->livewire(ListCandidatures::class)
            ->filterTable('lettre_signee', false)
            ->assertCanSeeTableRecords([$this->bruno])
            ->assertCanNotSeeTableRecords([$this->alice]);
    });

    it('retire une lettre depuis la ligne du dossier', function (): void {
        $this->service->deposer($this->alice, pdfEssai($this->alice->matricule.'.pdf'), $this->admin);
        $this->actingAs($this->admin);

        $this->livewire(ListCandidatures::class)
            ->assertTableActionHidden('retirerLettreSignee', $this->bruno)
            ->callTableAction('retirerLettreSignee', $this->alice->fresh())
            ->assertHasNoTableActionErrors();

        expect($this->alice->fresh()->lettre_admission_path)->toBeNull();
    });

    it('journalise la consultation d\'une lettre par un agent', function (): void {
        $this->service->deposer($this->alice, pdfEssai($this->alice->matricule.'.pdf'), $this->admin);
        $this->actingAs($this->admin);

        // mountTableAction exécute une action sans fenêtre ; callTableAction la rappellerait après la redirection.
        $this->livewire(ListCandidatures::class)->mountTableAction('voirLettreSignee', $this->alice->fresh());

        expect(Activity::query()->where('event', 'lettre_admission_consultee')->count())->toBe(1);
    });

    it('masque ces actions aux agents sans droit', function (string $role): void {
        $this->actingAs(($this->agent)($role));

        $this->livewire(ListCandidatures::class)
            ->assertActionHidden('deposerLettresSignees')
            ->assertTableActionHidden('deposerLettreSignee', $this->alice);
    })->with(['receptionniste', 'admission_committee']);

    it('réserve ce droit à l\'administration', function (string $role, bool $autorise): void {
        expect(($this->agent)($role)->can(LettresAdmissionSignees::PERMISSION))->toBe($autorise);
    })->with([['super_admin', true], ['admin', true], ['admission_committee', false], ['receptionniste', false], ['librarian', false]]);

    it('ne laisse pas l\'édition du dossier modifier ces champs', function (): void {
        expect($this->alice->isFillable('lettre_admission_path'))->toBeFalse()
            ->and($this->alice->isFillable('lettre_admission_deposee_at'))->toBeFalse()
            ->and($this->alice->isFillable('lettre_admission_deposee_par'))->toBeFalse()
            ->and($this->alice->toArray())->not->toHaveKey('lettre_admission_path');
    });
});
