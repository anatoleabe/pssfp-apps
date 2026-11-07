<?php

declare(strict_types=1);

use App\Filament\Resources\SeanceResource;
use App\Models\AttributionEcue;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\EtapeSaisie;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\NoteEcue;
use App\Models\Pointage;
use App\Models\RegleAssiduite;
use App\Models\Seance;
use App\Models\User;
use App\Services\Lmd\Assiduite;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\SaisieNotes;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('lmd');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    Maquette::query()->update(['statut' => Maquette::VALIDEE, 'validee_at' => now()]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->agent = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->service = app(Assiduite::class);
    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory()->campagneCommune(), 'bareme_id' => $bareme->id]);
    app(InscriptionsPedagogiques::class)->inscrire($this->inscription);
    $this->ecue = MaquetteEcue::query()->where('code', 'MPMPP711')->firstOrFail();
    $this->ip = $this->inscription->inscriptionsPedagogiques()->where('maquette_ue_id', $this->ecue->maquette_ue_id)->firstOrFail();
    $this->dossier = $this->inscription->auditeur->candidature;
    $this->dossier->forceFill(['email' => 'awa.essai@example.org', 'nom' => 'MBARGA', 'prenom' => 'Awa', 'user_id' => User::factory()->create()->id])->save();
    $this->seance = fn (string $date): Seance => $this->service->planifier($this->ecue,
        ['date_seance' => $date, 'debut' => '17:00', 'fin' => '20:00', 'modalite' => 'distanciel'], $this->agent);
    $this->csv = function (array $lignes): string {
        $f = tempnam(sys_get_temp_dir(), 'zoom').'.csv';
        file_put_contents($f, "\xEF\xBB\xBFName (original name),User Email,Join time,Leave time,Duration (minutes),Guest\n".implode("\n", $lignes)."\n");

        return $f;
    };
});

it('calcule l\'essai de D12 : 4 séances de 3 h, seuil 75 %, connexions 170, 120, 140 et 0 min = 10/20', function (): void {
    foreach ([['2026-11-16', 170], ['2026-11-17', 120], ['2026-11-18', 140], ['2026-11-19', 0]] as [$date, $minutes]) {
        $s = ($this->seance)($date);
        $lignes = $minutes > 0 ? ['Awa MBARGA,awa.essai@example.org,x,y,'.intdiv($minutes, 2).',No', 'Awa (téléphone),awa.essai@example.org,x,y,'.($minutes - intdiv($minutes, 2)).',No'] : ['Inconnu,inconnu@example.org,x,y,90,Yes'];
        $r = $this->service->importerZoom($s, ($this->csv)($lignes), $this->agent, true);
    }

    expect($r['non_reconnus'])->toBe(['Inconnu'])
        ->and($this->service->bilan($this->ip, $this->ecue, RegleAssiduite::pourAnnee('2026-2027')))
        ->toBe(['tenues' => 4, 'presents' => 2, 'justifies' => 0, 'note' => 10.0]);
});

it('compte une absence justifiée comme présente et ignore une séance annulée', function (): void {
    $a = ($this->seance)('2026-11-16');
    $b = ($this->seance)('2026-11-17');
    $c = ($this->seance)('2026-11-18');
    $this->service->pointer($a, [$this->ip->id], [], null, $this->agent);
    expect(fn () => $this->service->pointer($b, [], [$this->ip->id], null, $this->agent))->toThrow(InvalidArgumentException::class, 'motif');
    $this->service->pointer($b, [], [$this->ip->id], 'Mission de service', $this->agent);
    $this->service->annuler($c, 'Enseignant indisponible', $this->agent);

    expect($this->service->bilan($this->ip, $this->ecue, RegleAssiduite::pourAnnee('2026-2027'))['note'])->toBe(20.0)
        ->and(Pointage::query()->where('statut', 'justifie')->value('motif'))->toBe('Mission de service');
});

it('reporte la note calculée dans l\'étape ouverte, avec historique, et refuse le pointage une fois l\'étape verrouillée', function (): void {
    $this->service->pointer(($this->seance)('2026-11-16'), [$this->ip->id], [], null, $this->agent);
    $this->service->pointer(($this->seance)('2026-11-17'), [], [], null, $this->agent);
    $saisie = app(SaisieNotes::class);
    $etape = $saisie->etape($this->ecue, 'normale', 'assiduite');
    expect(fn () => $this->service->reporter($this->ecue, $this->agent))->toThrow(InvalidArgumentException::class, 'fermée');
    $saisie->ouvrir($etape, $this->responsable);

    expect($this->service->reporter($this->ecue, $this->agent))->toBe(1)
        ->and((float) NoteEcue::query()->where('inscription_pedagogique_id', $this->ip->id)->value('assiduite'))->toBe(10.0);

    $etape->forceFill(['statut' => EtapeSaisie::VERROUILLEE, 'verrouillee_at' => now()])->save();
    expect(fn () => $this->service->pointer(($this->seance)('2026-11-18'), [], [], null, $this->agent))->toThrow(InvalidArgumentException::class, 'verrouillée');
});

it('limite l\'enseignant aux séances de ses ECUE', function (): void {
    $enseignant = ($this->compte)('enseignant');
    expect(fn () => $this->service->planifier($this->ecue, ['date_seance' => '2026-11-16', 'debut' => '17:00', 'fin' => '20:00'], $enseignant))
        ->toThrow(HttpException::class);
    AttributionEcue::query()->create(['maquette_ecue_id' => $this->ecue->id, 'user_id' => $enseignant->id]);
    expect($this->service->planifier($this->ecue, ['date_seance' => '2026-11-16', 'debut' => '17:00', 'fin' => '20:00'], $enseignant)->statut)->toBe('prevue');

    $this->actingAs($enseignant)->get(SeanceResource::getUrl('index'))->assertOk();
    $this->actingAs(($this->compte)('librarian'))->get(SeanceResource::getUrl('index'))->assertForbidden();
});

it('refuse un fichier qui n\'est pas un rapport Zoom', function (): void {
    $f = tempnam(sys_get_temp_dir(), 'zoom');
    file_put_contents($f, "a,b\n1,2\n");

    expect(fn () => $this->service->importerZoom(($this->seance)('2026-11-16'), $f, $this->agent, false))->toThrow(InvalidArgumentException::class, 'rapport des participants');
});

it('donne à l\'auditeur son assiduité par ECUE', function (): void {
    $this->service->pointer(($this->seance)('2026-11-16'), [$this->ip->id], [], null, $this->agent);
    $jeton = $this->dossier->user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;

    $this->withToken($jeton)->getJson('/v1/applications/me/assiduite')->assertOk()
        ->assertJsonPath('data.0.ecue', 'MPMPP711')
        ->assertJsonPath('data.0.presents', 1)
        ->assertJsonPath('data.0.note', 20);
});
