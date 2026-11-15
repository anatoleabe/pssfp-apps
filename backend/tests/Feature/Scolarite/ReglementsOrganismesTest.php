<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\DeblocageAcces;
use App\Models\EngagementPriseEnCharge;
use App\Models\ImputationVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\ReglementOrganisme;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\CompteAuditeur;
use App\Services\Scolarite\EngagementsPriseEnCharge;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\ReglementsOrganismes;
use App\Services\Scolarite\SuiviEngagement;
use App\Services\Sms\SmsServiceInterface;
use App\Services\Supervision\Supervision;
use App\Support\AppSettings;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('scolarite', 'prises-en-charge');

/*
 * Règlement réparti d'un organisme (spec module 4, lot A2 §9.7) : un versement par auditeur,
 * payeur employeur, imputé par le compte de l'auditeur (D1) sur l'année couverte ; annulé
 * d'un bloc par le responsable.
 */

beforeEach(function (): void {
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 16)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->financier = ($this->compte)('service_financier');
    $campagne = CampagneCandidature::factory()->closed()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14,
        'parametres_lettres' => ['date_lettres' => '2026-11-04'],
    ]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $retenu = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel,
        'email' => Str::lower($nom).'@example.org', 'date_naissance' => '1990-04-12', 'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    $this->awa = $retenu('Abena', '+237699008001');
    $this->bruno = $retenu('Bekono', '+237699008002');
    $this->chantal = $retenu('Chimi', '+237699008003');
    app(AttributionMatricules::class)->attribuer($campagne, '26', ($this->compte)('admin'));
    foreach (['awa', 'bruno', 'chantal'] as $d) {
        $this->{$d}->refresh();
    }
    $this->mock(SmsServiceInterface::class)->shouldReceive('send')->andReturnNull();
    Mail::fake();

    $this->nouvelEngagement = fn (array $saisie = []): EngagementPriseEnCharge => app(EngagementsPriseEnCharge::class)->creer($saisie + [
        'organisme' => 'Ministère de test', 'lettre_reference' => 'LT-2026-001', 'lettre_date' => '2026-11-10',
        'lettre_path' => 'scolarite/justificatifs/01JLETTRE0000000000000000A.pdf', 'contact_email' => 'rh@organisme.example',
        'portee' => EngagementPriseEnCharge::DROITS_INSCRIPTION, 'annee_academique' => '2026-2027', 'echeance' => '2027-01-15',
    ], $this->agent);
    $this->rattacher = fn (EngagementPriseEnCharge $e, array $dossiers) => app(EngagementsPriseEnCharge::class)
        ->rattacher($e, array_map(fn (Candidature $c): Auditeur => $c->fresh()->auditeur, $dossiers), $this->agent);
    $this->engagement = ($this->nouvelEngagement)();
    ($this->rattacher)($this->engagement, [$this->awa, $this->bruno]);

    $this->inscriptionDe = fn (Candidature $c): InscriptionAnnuelle => $c->fresh()->auditeur->inscriptionCourante;
    $this->service = fn (): ReglementsOrganismes => app(ReglementsOrganismes::class);
    $this->regler = fn (array $saisie = [], ?User $par = null, ?EngagementPriseEnCharge $e = null): ReglementOrganisme => ($this->service)()->enregistrer(
        $e ?? $this->engagement,
        $saisie + ['montant' => 370000, 'date_reglement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'ov-77',
            'parts' => ReglementsOrganismes::partsProposees($e ?? $this->engagement)],
        $par ?? $this->agent,
    )->reglement;
    $this->parts = fn (array $montants): array => collect($montants)->mapWithKeys(fn (int $m, string $d): array => [($this->inscriptionDe)($this->{$d})->uuid => $m])->all();
    $this->verifier = fn (): int => $this->artisan('scolarite:verifier-comptes')->run();
    $this->cptCoherence = fn (): array => app(Supervision::class)->controles()['CPT_COHERENCE']->cas();
});

describe('parts proposées', function (): void {
    it('propose à chaque auditeur couvert le reste dû dans la portée', function (): void {
        app(RegistreVersements::class)->enregistrer(($this->inscriptionDe)($this->awa), [
            'montant' => 100000, 'date_versement' => '2026-11-16', 'mode' => 'especes', 'reference' => 'ESP-1', 'payeur' => 'auditeur',
        ], $this->agent);
        $annee = ($this->nouvelEngagement)(['portee' => EngagementPriseEnCharge::SCOLARITE_ANNEE, 'lettre_reference' => 'LT-ANNEE',
            'lettre_path' => 'scolarite/justificatifs/01JLETTRE0000000000000000B.pdf']);
        ($this->rattacher)($annee, [$this->chantal]);

        expect(ReglementsOrganismes::partsProposees($this->engagement))->toBe(($this->parts)(['awa' => 85000, 'bruno' => 185000]))
            ->and(ReglementsOrganismes::partsProposees($annee))->toBe(($this->parts)(['chantal' => 1185000]));
    });

    it('ne propose rien pour un dossier retiré', function (): void {
        $this->awa->forceFill(['withdrawn_at' => now()])->save();

        expect(ReglementsOrganismes::partsProposees($this->engagement))->toBe(($this->parts)(['bruno' => 185000]));
    });
});

describe('enregistrement', function (): void {
    it('crée un versement par auditeur, payeur employeur, référence par matricule, imputé sur l\'année couverte', function (): void {
        $r = ($this->regler)();

        expect($r->fresh())->montant->toBe(370000)->reference->toBe('OV-77')->mode->toBe('virement')
            ->saisi_par->toBe($this->agent->id)->engagement_id->toBe($this->engagement->id)->annule_at->toBeNull();
        $versements = Versement::query()->orderBy('auditeur_id')->get();
        expect($versements)->toHaveCount(2);
        foreach ([$this->awa, $this->bruno] as $i => $dossier) {
            $v = $versements[$i];
            $inscription = ($this->inscriptionDe)($dossier);
            expect($v)->payeur->toBe('employeur')->reglement_organisme_id->toBe($r->id)->montant->toBe(185000)
                ->reference->toBe('OV-77-'.$dossier->matricule)->mode->toBe('virement')->justificatif_path->toBeNull()
                ->inscription_annuelle_id->toBe($inscription->id)
                ->and($v->date_versement->toDateString())->toBe('2026-11-16')
                ->and(ImputationVersement::query()->where('versement_id', $v->id)->whereNull('annule_at')->get(['inscription_annuelle_id', 'montant', 'origine'])->toArray())
                ->toBe([['inscription_annuelle_id' => $inscription->id, 'montant' => 185000, 'origine' => 'saisie']])
                ->and($inscription->situation()->inscriptionSoldee())->toBeTrue()
                ->and(AccesEcampus::etat(Candidature::query()->findOrFail($dossier->id)))->toBe(AccesEcampus::DISPONIBLE);
        }
        $suivi = SuiviEngagement::de($this->engagement->fresh());
        expect(DeblocageAcces::query()->pluck('statut')->unique()->all())->toBe([DeblocageAcces::REGULARISE])
            ->and($suivi->regle)->toBe(370000)->and($suivi->reste)->toBe(0)
            ->and($suivi->etat())->toBe(EngagementPriseEnCharge::SOLDE)
            ->and(Activity::query()->where('event', 'reglement_enregistre')->sole()->properties['parts'])->toHaveCount(2)
            ->and(Activity::query()->where('event', 'versement_enregistre')->get()->pluck('properties.reglement')->unique()->all())->toBe([$r->uuid]);
    });

    it('refuse une somme des parts différente du montant, sans rien écrire', function (): void {
        expect(fn () => ($this->regler)(['montant' => 400000]))
            ->toThrow(InvalidArgumentException::class, 'La somme des parts (370 000 FCFA) doit égaler le montant du règlement (400 000 FCFA).')
            ->and(ReglementOrganisme::query()->count())->toBe(0)
            ->and(Versement::query()->count())->toBe(0);
    });

    it('refuse une part sur un auditeur non couvert, une part négative ou des parts toutes nulles', function (array $parts, int $montant, string $message): void {
        expect(fn () => ($this->regler)(['montant' => $montant, 'parts' => ($this->parts)($parts)]))->toThrow(InvalidArgumentException::class, $message)
            ->and(Versement::query()->count())->toBe(0);
    })->with([
        'non couvert' => [['awa' => 185000, 'chantal' => 185000], 370000, 'pas couvert'],
        'négative' => [['awa' => 400000, 'bruno' => -30000], 370000, 'entier positif'],
        'toutes nulles' => [['awa' => 0, 'bruno' => 0], 1, 'au moins une part'],
    ]);

    it('refuse les doublons de référence, dans le même mode seulement', function (): void {
        ($this->regler)(['montant' => 185000, 'parts' => ($this->parts)(['awa' => 185000, 'bruno' => 0])]);

        expect(fn () => ($this->regler)(['montant' => 185000, 'parts' => ($this->parts)(['bruno' => 185000])]))
            ->toThrow(InvalidArgumentException::class, 'déjà été saisie');

        ($this->regler)(['montant' => 100000, 'mode' => 'cremincam_agence', 'parts' => ($this->parts)(['bruno' => 100000])]);
        expect(ReglementOrganisme::query()->count())->toBe(2);
    });

    it('refuse un règlement déjà saisi comme versement, et un versement qui reprend la référence d\'un règlement', function (): void {
        $registre = app(RegistreVersements::class);
        $registre->enregistrer(($this->inscriptionDe)($this->awa), [
            'montant' => 50000, 'date_versement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'OV-77', 'payeur' => 'employeur',
        ], $this->agent);
        // Reste après ce versement : 135 000 (Awa) + 185 000 (Bruno).
        expect(fn () => ($this->regler)(['montant' => 320000]))->toThrow(InvalidArgumentException::class, 'déjà été saisie comme versement');

        $registre->enregistrer(($this->inscriptionDe)($this->awa), [
            'montant' => 50000, 'date_versement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'OV-88-'.$this->bruno->matricule, 'payeur' => 'auditeur',
        ], $this->agent);
        expect(fn () => ($this->regler)(['reference' => 'OV-88', 'montant' => 270000]))->toThrow(InvalidArgumentException::class, (string) $this->bruno->matricule);

        ($this->regler)(['reference' => 'OV-99', 'montant' => 270000, 'parts' => ($this->parts)(['awa' => 85000, 'bruno' => 185000])]);
        expect(fn () => $registre->enregistrer(($this->inscriptionDe)($this->awa), [
            'montant' => 1000, 'date_versement' => '2026-11-16', 'mode' => 'virement', 'reference' => 'ov-99', 'payeur' => 'auditeur',
        ], $this->agent))->toThrow(InvalidArgumentException::class, 'règlement');
    });

    it('exige la confirmation au-delà du reste couvert : l\'excédent va sur l\'année, puis en avance réservée', function (): void {
        $trop = ($this->parts)(['awa' => 300000, 'bruno' => 185000]);
        expect(fn () => ($this->regler)(['montant' => 485000, 'parts' => $trop]))->toThrow(InvalidArgumentException::class, 'Confirmez')
            ->and(Versement::query()->count())->toBe(0);

        ($this->regler)(['montant' => 485000, 'parts' => $trop, 'confirmer_depassement' => true]);
        $awa = ($this->inscriptionDe)($this->awa)->situation();
        expect($awa->verse)->toBe(300000)->and($awa->paliers[1]->paye)->toBe(115000)
            ->and(CompteAuditeur::pour($this->awa->fresh()->auditeur)->avance())->toBe(0);

        $annee = ($this->nouvelEngagement)(['portee' => EngagementPriseEnCharge::SCOLARITE_ANNEE, 'lettre_reference' => 'LT-ANNEE',
            'lettre_path' => 'scolarite/justificatifs/01JLETTRE0000000000000000B.pdf']);
        ($this->rattacher)($annee, [$this->chantal]);
        ($this->regler)(['montant' => 1300000, 'reference' => 'OV-ANNEE', 'parts' => ($this->parts)(['chantal' => 1300000]), 'confirmer_depassement' => true], null, $annee);

        $compte = CompteAuditeur::pour($this->chantal->fresh()->auditeur);
        expect(($this->inscriptionDe)($this->chantal)->situation()->solde())->toBeTrue()
            ->and($compte->avanceReservee())->toBe(115000)
            ->and($compte->avanceLibre())->toBe(0)
            ->and(($this->verifier)())->toBe(0)
            ->and(($this->cptCoherence)())->toBe([]);
    });

    it('enregistre par le droit de saisir les versements, service financier compris', function (): void {
        expect(($this->regler)([], $this->financier)->saisi_par)->toBe($this->financier->id)
            ->and(fn () => ($this->regler)(['reference' => 'OV-2'], ($this->compte)('receptionniste')))->toThrow(HttpException::class);
    });

    it('refuse une part pour un dossier retiré', function (): void {
        $parts = ReglementsOrganismes::partsProposees($this->engagement);
        $this->awa->forceFill(['withdrawn_at' => now()])->save();

        expect(fn () => ($this->regler)(['parts' => $parts]))->toThrow(InvalidArgumentException::class, 'retiré')
            ->and(Versement::query()->count())->toBe(0);
    });

    it('verrouille l\'engagement, puis les auditeurs par id croissant, avant toute écriture', function (): void {
        ($this->rattacher)($this->engagement, [$this->chantal]);
        $requetes = [];
        DB::listen(function ($q) use (&$requetes): void {
            $requetes[] = [$q->sql, $q->bindings];
        });

        // Parts données dans l'ordre inverse des auditeurs.
        ($this->regler)(['montant' => 555000, 'parts' => array_reverse(ReglementsOrganismes::partsProposees($this->engagement), true)]);

        $index = fn (Closure $test): ?int => collect($requetes)->search(fn (array $r): bool => $test($r[0])) ?: null;
        $verrouEngagement = $index(fn (string $sql): bool => str_contains($sql, 'from "engagements_prise_en_charge"') && str_contains($sql, 'for update'));
        $verrous = collect($requetes)->filter(fn (array $r): bool => str_contains($r[0], 'from "auditeurs"') && str_contains($r[0], 'for update'));
        $premiereEcriture = $index(fn (string $sql): bool => str_starts_with($sql, 'insert into "versements"') || str_starts_with($sql, 'insert into "reglements_organismes"'));
        $ids = $verrous->map(fn (array $r): int => (int) $r[1][0])->unique()->values()->all();
        $attendus = collect([$this->awa, $this->bruno, $this->chantal])->map(fn (Candidature $c): int => $c->fresh()->auditeur->id)->sort()->values()->all();

        expect($verrouEngagement)->not->toBeNull()
            ->and($ids)->toBe($attendus)
            ->and($verrous->keys()->first())->toBeGreaterThan($verrouEngagement)
            ->and($verrous->keys()->take(3)->last())->toBeLessThan($premiereEcriture);
    });

    it('n\'écrit rien si une part échoue en cours d\'écriture', function (): void {
        $refusee = 'OV-77-'.$this->bruno->matricule;
        Versement::creating(function (Versement $v) use ($refusee): void {
            if ($v->reference === $refusee) {
                throw new InvalidArgumentException('Panne simulée');
            }
        });

        expect(fn () => ($this->regler)())->toThrow(InvalidArgumentException::class, 'Panne simulée')
            ->and(ReglementOrganisme::query()->count())->toBe(0)
            ->and(Versement::query()->count())->toBe(0)
            ->and(ImputationVersement::query()->count())->toBe(0);
    });

    it('laisse le compte cohérent : vérification et CPT_COHERENCE à zéro', function (): void {
        ($this->regler)();

        expect(($this->verifier)())->toBe(0)
            ->and(($this->cptCoherence)())->toBe([]);
    });
});

describe('annulation', function (): void {
    it('la réserve au responsable de scolarité', function (): void {
        $r = ($this->regler)();

        expect(fn () => ($this->service)()->annuler($r, 'Saisi sur le mauvais engagement', $this->agent))->toThrow(HttpException::class)
            ->and(fn () => ($this->service)()->annuler($r, 'Saisi sur le mauvais engagement', $this->financier))->toThrow(HttpException::class);
    });

    it('annule d\'un bloc ses versements, leurs imputations et leurs reçus', function (): void {
        $r = ($this->regler)();
        $versementAwa = Versement::query()->where('auditeur_id', $this->awa->fresh()->auditeur->id)->sole();
        $recu = app(RecusVersements::class)->emettre($versementAwa, $this->financier);

        ($this->service)()->annuler($r, 'Virement rejeté par la banque', $this->responsable);

        expect($r->fresh())->annule_at->not->toBeNull()->annule_par->toBe($this->responsable->id)->annule_motif->toBe('Virement rejeté par la banque')
            ->and(Versement::query()->whereNull('annule_at')->count())->toBe(0)
            ->and(Versement::query()->pluck('annule_motif')->unique()->all())->toBe(['Règlement OV-77 annulé : Virement rejeté par la banque'])
            ->and(ImputationVersement::query()->whereNull('annule_at')->count())->toBe(0)
            ->and($recu->fresh()->annulation)->not->toBeNull()
            ->and(Activity::query()->where('event', 'reglement_annule')->count())->toBe(1)
            ->and(Activity::query()->where('event', 'versement_annule')->count())->toBe(2)
            // Le déblocage régularisé reste clos : l'accès se ferme, un montant n'est jamais tenu pour payé.
            ->and(DeblocageAcces::query()->pluck('statut')->unique()->all())->toBe([DeblocageAcces::REGULARISE])
            ->and(AccesEcampus::etat(Candidature::query()->findOrFail($this->awa->id)))->toBe(AccesEcampus::BLOQUE)
            ->and(SuiviEngagement::de($this->engagement->fresh()))->regle->toBe(0)->reste->toBe(370000)
            ->and(($this->verifier)())->toBe(0)
            ->and(($this->cptCoherence)())->toBe([]);

        // La référence se ressaisit, et l'auditeur se rattache de nouveau au même engagement.
        ($this->regler)(['montant' => 185000, 'parts' => ($this->parts)(['awa' => 185000, 'bruno' => 0])]);
        expect(ReglementOrganisme::query()->whereNull('annule_at')->count())->toBe(1)
            ->and(($this->rattacher)($this->engagement, [$this->bruno])->rattaches)->toBe([$this->bruno->matricule]);
    });

    it('refuse tout, avec un message clair, si un versement est imputé sur plusieurs années', function (): void {
        $r = ($this->regler)(['montant' => 1485000, 'parts' => ($this->parts)(['awa' => 185000, 'bruno' => 1300000]), 'confirmer_depassement' => true]);
        $bruno = $this->bruno->fresh()->auditeur;
        $versement = Versement::query()->where('auditeur_id', $bruno->id)->sole();
        // Inscription suivante créée à la main, et part de l'avance réservée imputée dessus (geste à venir en D4).
        $suivante = InscriptionAnnuelle::factory()->create([
            'auditeur_id' => $bruno->id, 'annee_academique' => '2027-2028', 'niveau' => 'M2', 'montant_inscription' => 135000,
            'bareme_id' => BaremeScolarite::factory()->m2()->create(['annee_academique' => '2027-2028'])->id,
        ]);
        DB::table('imputations_versements')->insert([
            'uuid' => (string) Str::uuid(), 'auditeur_id' => $bruno->id, 'versement_id' => $versement->id,
            'inscription_annuelle_id' => $suivante->id, 'montant' => 115000, 'origine' => 'avance', 'created_at' => now(), 'updated_at' => now(),
        ]);

        expect(fn () => ($this->service)()->annuler($r, 'Virement rejeté par la banque', $this->responsable))
            ->toThrow(InvalidArgumentException::class, 'Le règlement ne peut pas être annulé : le versement de '.$this->bruno->matricule.' est imputé sur plusieurs années (2026-2027, 2027-2028)')
            ->and($r->fresh()->annule_at)->toBeNull()
            ->and(Versement::query()->whereNull('annule_at')->count())->toBe(2);
    });

    it('refuse d\'annuler seul un versement issu d\'un règlement', function (): void {
        ($this->regler)();
        $v = Versement::query()->firstOrFail();

        expect(fn () => app(RegistreVersements::class)->annuler($v, 'Erreur de saisie', $this->responsable))
            ->toThrow(InvalidArgumentException::class, 'annulez le règlement entier')
            ->and($v->fresh()->annule_at)->toBeNull();
    });

    it('refuse une seconde annulation et un motif vide', function (): void {
        $r = ($this->regler)();

        expect(fn () => ($this->service)()->annuler($r, '   ', $this->responsable))->toThrow(InvalidArgumentException::class, 'motif');
        ($this->service)()->annuler($r, 'Virement rejeté par la banque', $this->responsable);
        expect(fn () => ($this->service)()->annuler($r, 'Virement rejeté par la banque', $this->responsable))->toThrow(InvalidArgumentException::class, 'déjà annulé');
    });
});

describe('contrôle interne', function (): void {
    it('signale dans CPT_COHERENCE et la vérification un règlement dont un versement a été annulé seul', function (): void {
        $r = ($this->regler)();
        annulerVersement(Versement::query()->firstOrFail(), 'Annulation hors application');

        $cas = ($this->cptCoherence)();
        expect($cas)->toHaveKey('reglement:'.$r->uuid)
            ->and($cas['reglement:'.$r->uuid]['resume'])->toContain('OV-77')->toContain('370 000')->toContain('185 000')
            ->and(($this->verifier)())->not->toBe(0);
    });
});
