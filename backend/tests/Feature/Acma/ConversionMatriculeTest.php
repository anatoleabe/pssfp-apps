<?php

declare(strict_types=1);

use App\Models\AcmaDossier;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Parcours;
use App\Models\RecuVersement;
use App\Models\ReinscriptionAcma;
use App\Models\User;
use App\Services\Acma\ConversionMatriculeAcma;
use App\Services\Acma\Reprise\RepriseAcma;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Sms\SmsServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Acma\TransitAcma;

uses()->group('acma', 'scolarite');

/* Lot 15m (spec de la réinscription des anciens, §8.3 ; Q2 tranchée le 16/11/2026 : le dossier ACMA fait foi). Jeu fictif. */

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('scolarite.annee_en_cours', '2026-2027');
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    Http::fake();
    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($m): void {
        $m->shouldReceive('send')->andReturnUsing(function (string $tel): void {
            $this->sms[] = $tel;
        });
    });
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->admin = ($this->compte)('admin');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->p14 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14, 'slug' => 'p14-2026', 'prefix_numero' => 'P14026-']);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027']);
    Parcours::query()->create(['code' => 'TC', 'libelle' => 'Tronc commun', 'niveau' => 'M1', 'actif' => true, 'ordre' => 1]);
    TransitAcma::construire();
    app(RepriseAcma::class)->executer($this->admin, null, true);
    $this->dossier = fn (string $m): AcmaDossier => AcmaDossier::query()->where('matricule', $m)->firstOrFail();
    $this->retenu = fn (array $champs): Candidature => Candidature::factory()->forCampagne($this->p14)->submitted()->create($champs + ['statut' => Candidature::STATUT_ACCEPTE]);
});

it('convertit un auditeur P14 déjà payé et doté d\'un compte eCampus, sans rien perdre, dupliquer ni couper', function (): void {
    // Retenu P14 numéroté sous une autre identité, puis rectifiée : c'est l'ancien auditeur 90EFP012 de l'archive.
    $c = ($this->retenu)(['nom' => 'AUTRE', 'prenom' => 'Personne', 'date_naissance' => '1999-09-09', 'phone_e164' => '+237699000012', 'email' => 'zeta@exemple.invalid']);
    app(AttributionMatricules::class)->attribuer($this->p14, '26', $this->admin);
    $c->refresh()->forceFill(['nom' => 'ESSAI', 'prenom' => 'Zeta', 'date_naissance' => '1984-04-04', 'ecampus_statut' => ComptesEcampus::CREE])->save();
    $inscription = $c->auditeur->inscriptionCourante;
    verserConfirme($inscription->fresh(), ['montant' => 300000, 'date_versement' => '2026-11-10', 'mode' => 'virement', 'reference' => 'BOR-'.Str::random(8), 'payeur' => 'auditeur'], ($this->compte)('service_financier'));
    $avant = [
        'matricule' => $c->fresh()->matricule, 'rang' => $c->fresh()->rang_retenu, 'mdp' => $c->fresh()->moodle_password,
        'etat' => AccesEcampus::etat($c->fresh()), 'notifie' => $inscription->fresh()->acces_notifie_at?->toIso8601String(),
        'recus' => RecuVersement::query()->orderBy('id')->get(['id', 'numero', 'matricule'])->toArray(),
        'versements' => DB::table('versements')->where('auditeur_id', $c->auditeur->id)->count(),
        'imputations' => DB::table('imputations_versements')->where('auditeur_id', $c->auditeur->id)->whereNull('annule_at')->sum('montant'),
        'sms' => count($this->sms),
    ];
    expect($avant['etat'])->toBe(AccesEcampus::DISPONIBLE)->and($avant['sms'])->toBe(1);

    $apres = app(ConversionMatriculeAcma::class)->convertir(($this->dossier)('90EFP012'), $c, 'Ancien auditeur revenu en P14 : le dossier ACMA fait foi', $this->responsable);

    expect($apres->matricule)->toBe('90EFP012')
        ->and($apres->identifiant_ecampus)->toBe(mb_strtolower($avant['matricule']))
        ->and(IdentifiantsMoodle::identifiant($apres))->toBe(mb_strtolower($avant['matricule']))
        ->and($apres->rang_retenu)->toBe($avant['rang'])->and($apres->moodle_password)->toBe($avant['mdp'])
        ->and($apres->auditeur->inscriptionCourante->id)->toBe($inscription->id)
        ->and(AccesEcampus::etat($apres))->toBe($avant['etat'])
        ->and($inscription->fresh()->acces_notifie_at?->toIso8601String())->toBe($avant['notifie'])
        ->and(RecuVersement::query()->orderBy('id')->get(['id', 'numero', 'matricule'])->toArray())->toBe($avant['recus'])
        ->and(DB::table('versements')->where('auditeur_id', $c->auditeur->id)->count())->toBe($avant['versements'])
        ->and(DB::table('imputations_versements')->where('auditeur_id', $c->auditeur->id)->whereNull('annule_at')->sum('montant'))->toBe($avant['imputations'])
        ->and(count($this->sms))->toBe($avant['sms'])
        ->and($apres->estReinscritAcma())->toBeFalse()->and($apres->peutAvoirRecepisse())->toBeTrue();
    $d = ($this->dossier)('90EFP012');
    expect($d->statut_rapprochement)->toBe('lie_arbitre')->and($d->auditeur_id)->toBe($c->auditeur->id);
    $trace = ReinscriptionAcma::query()->sole();
    expect($trace->mode)->toBe('conversion')->and($trace->ancien_matricule)->toBe($avant['matricule']);
    expect(DB::table('activity_log')->where('event', ConversionMatriculeAcma::EVENEMENT)->count())->toBe(1);

    // Connexion FOAD : l'ancien identifiant reste valable ; le nouveau matricule aussi.
    $compte = tap(User::factory()->create(['phone_e164' => '+237699000012']), fn (User $u) => $u->assignRole('candidat'));
    $c->forceFill(['user_id' => $compte->id])->save();
    $this->postJson('/v1/auth/candidat/login-matricule', ['identifiant' => $avant['matricule'], 'mot_de_passe' => $avant['mdp']])->assertOk();
    $this->postJson('/v1/auth/candidat/login-matricule', ['identifiant' => '90EFP012', 'mot_de_passe' => $avant['mdp']])->assertOk();

    // L'ancien matricule n'est jamais réattribué : le retenu suivant prend le rang suivant.
    $suivant = ($this->retenu)(['nom' => 'NOUVEAU', 'prenom' => 'Retenu', 'date_naissance' => '1995-05-05']);
    app(AttributionMatricules::class)->attribuer($this->p14, '26', $this->admin);
    expect($suivant->fresh()->rang_retenu)->toBe($avant['rang'] + 1)->and($suivant->fresh()->matricule)->not->toBe($avant['matricule']);
});

it('donne le matricule ACMA à un retenu sans matricule au lieu d\'en attribuer un nouveau', function (): void {
    $c = ($this->retenu)(['nom' => 'ESSAI', 'prenom' => 'Eta Theta', 'date_naissance' => '1986-06-06']);
    expect(fn () => app(AttributionMatricules::class)->attribuer($this->p14, '26', $this->admin))->toThrow(RuntimeException::class, 'semble être l\'ancien auditeur 90EFP013');
    expect($c->fresh()->matricule)->toBeNull();

    $apres = app(ConversionMatriculeAcma::class)->convertir(($this->dossier)('90EFP013'), $c, 'Ancienne auditrice retenue en P14', $this->responsable);
    expect($apres->matricule)->toBe('90EFP013')->and($apres->rang_retenu)->toBeNull()->and($apres->identifiant_ecampus)->toBeNull()
        ->and($apres->auditeur->inscriptionCourante->annee_academique)->toBe('2026-2027')
        ->and(ReinscriptionAcma::query()->sole()->mode)->toBe('attribution');
    expect($this->sms)->toBe([]);
});

it('refuse sans identité concordante confirmée, une seconde fois, un dossier lié à un autre, et sans le droit', function (): void {
    $c = ($this->retenu)(['nom' => 'AUTRE', 'prenom' => 'Personne', 'date_naissance' => '1999-09-09']);
    $conversion = app(ConversionMatriculeAcma::class);
    expect(fn () => $conversion->convertir(($this->dossier)('90EFP012'), $c, 'Motif suffisamment long', $this->responsable))->toThrow(InvalidArgumentException::class, 'ne concordent pas');
    expect(fn () => $conversion->convertir(($this->dossier)('90EFP012'), $c, 'Motif suffisamment long', ($this->compte)('scolarite_agent')))->toThrow(HttpException::class);

    $conversion->convertir(($this->dossier)('90EFP012'), $c, 'Identité vérifiée sur la pièce', $this->responsable, true);
    $autre = ($this->retenu)(['nom' => 'ESSAI', 'prenom' => 'Zeta', 'date_naissance' => '1984-04-04']);
    expect(fn () => $conversion->convertir(($this->dossier)('90EFP012'), $autre, 'Motif suffisamment long', $this->responsable, true))->toThrow(InvalidArgumentException::class, 'déjà repris');
    expect(fn () => $conversion->convertir(($this->dossier)('90EFP013'), $c->fresh(), 'Motif suffisamment long', $this->responsable, true))->toThrow(InvalidArgumentException::class, 'déjà repris');
});

it('se défait tant qu\'aucun identifiant n\'est figé, et refuse ensuite', function (): void {
    $migration = require database_path('migrations/2026_12_10_100300_add_identifiant_ecampus_to_candidatures.php');
    $migration->down();
    expect(Schema::hasColumn('candidatures', 'identifiant_ecampus'))->toBeFalse();
    $migration->up();
    ($this->retenu)(['nom' => 'X', 'prenom' => 'Y'])->forceFill(['identifiant_ecampus' => '26efp999'])->save();
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'figés');
});
