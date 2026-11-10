<?php

declare(strict_types=1);

use App\Mail\CopieMessageMail;
use App\Models\EnvoiMessage;
use App\Models\Message;
use App\Models\MessageDestinataire;
use App\Models\PieceMessage;
use App\Models\User;
use App\Services\Messagerie\CopiesExternes;
use App\Services\Messagerie\Messagerie;
use App\Services\Scanner\PhotoScannerInterface;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('messagerie');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake(PieceMessage::DISQUE);
    $this->compte = fn (string $role, array $attributs = []): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role.' '.Str::random(3), ...$attributs]),
        fn (User $u) => $u->assignRole($role),
    );
    $this->messagerie = app(Messagerie::class);
});

/** Fichier déposé, comme le formulaire le transmet au service. */
function deposerPiece(string $nom, string $contenu): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nom, $contenu);
}

/** Un vrai .docx minimal (archive Office), que finfo peut ne voir que comme une archive. */
function docxMinimal(): UploadedFile
{
    $chemin = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($chemin, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><document/>');
    $zip->close();

    return new UploadedFile($chemin, 'compte-rendu.docx', null, null, true);
}

it('envoie à des destinataires dédoublonnés, sans l\'expéditeur, et journalise sans le contenu', function (): void {
    $a = ($this->compte)('admin');
    $b = ($this->compte)('scolarite_responsable');

    $m = $this->messagerie->envoyer($a, [$b->id, $b->id, $a->id], '  Point sur les états de paie ', 'Corps confidentiel');

    expect($m->objet)->toBe('Point sur les états de paie')
        ->and($m->fil)->toBe($m->uuid)
        ->and($m->destinataires()->pluck('user_id')->all())->toBe([$b->id])
        ->and($this->messagerie->nonLus($b))->toBe(1)
        ->and($this->messagerie->nonLus($a))->toBe(0);
    $journal = Activity::query()->where('log_name', 'messagerie')->sole();
    expect($journal->event)->toBe('message_envoye')
        ->and($journal->properties->all())->toBe(['categorie' => 'message', 'destinataires' => 1, 'pieces' => 0, 'canaux' => []])
        ->and(json_encode($journal->toArray()))->not->toContain('confidentiel');
});

it('refuse un message sans destinataire, hors du personnel, ou mal formé', function (): void {
    $a = ($this->compte)('admin');
    $b = ($this->compte)('admin');
    $candidat = ($this->compte)('candidat');

    expect(fn () => $this->messagerie->envoyer($a, [$a->id], 'Objet', 'Corps'))->toThrow(InvalidArgumentException::class, 'sans destinataire')
        ->and(fn () => $this->messagerie->envoyer($a, [$candidat->id], 'Objet', 'Corps'))->toThrow(InvalidArgumentException::class, 'personnel')
        ->and(fn () => $this->messagerie->envoyer($candidat, [$b->id], 'Objet', 'Corps'))->toThrow(InvalidArgumentException::class, 'personnel')
        ->and(fn () => $this->messagerie->envoyer($a, [$b->id], '   ', 'Corps'))->toThrow(InvalidArgumentException::class, 'objet')
        ->and(fn () => $this->messagerie->envoyer($a, [$b->id], str_repeat('x', 201), 'Corps'))->toThrow(InvalidArgumentException::class, 'objet')
        ->and(fn () => $this->messagerie->envoyer($a, [$b->id], 'Objet', ''))->toThrow(InvalidArgumentException::class, 'message')
        ->and(fn () => $this->messagerie->envoyer($a, [$b->id], 'Objet', 'Corps', 'relance'))->toThrow(InvalidArgumentException::class, 'système')
        ->and(fn () => $this->messagerie->envoyer(null, [$b->id], 'Objet', 'Corps'))->toThrow(InvalidArgumentException::class, 'système')
        ->and(fn () => $this->messagerie->envoyer($a, [$b->id], 'Objet', 'Corps', 'inconnue'))->toThrow(InvalidArgumentException::class, 'inconnue')
        ->and(fn () => $this->messagerie->envoyer($a, [$b->id], 'Objet', 'Corps', canaux: ['sms']))->toThrow(InvalidArgumentException::class, 'Canal')
        ->and(Message::query()->count())->toBe(0);
});

it('fait rejoindre le fil à une réponse, adressée à tous les participants sauf soi', function (): void {
    [$a, $b, $c] = [($this->compte)('admin'), ($this->compte)('admin'), ($this->compte)('scolarite_responsable')];
    $intrus = ($this->compte)('admin');
    $m = $this->messagerie->envoyer($a, [$b->id, $c->id], 'Calendrier', 'Merci de relire.');

    $r = $this->messagerie->repondre($b, $m, 'Relu, deux remarques.');
    expect($r->fil)->toBe($m->fil)->and($r->objet)->toBe('RE : Calendrier')->and($r->reponse_a)->toBe($m->id)
        ->and($r->destinataires()->pluck('user_id')->sort()->values()->all())->toBe(collect([$a->id, $c->id])->sort()->values()->all());
    expect($this->messagerie->repondre($a, $r, 'Merci.')->objet)->toBe('RE : Calendrier')
        ->and($this->messagerie->fil($m, $c))->toHaveCount(3)
        ->and(fn () => $this->messagerie->repondre($intrus, $m, 'Je m\'invite.'))->toThrow(InvalidArgumentException::class, 'fil');

    $systeme = $this->messagerie->envoyer(null, [$a->id], 'Relance', 'Anomalie à traiter', Message::RELANCE);
    expect(fn () => $this->messagerie->repondre($a, $systeme, 'Vu.'))->toThrow(InvalidArgumentException::class, 'système');
});

it('marque lu le fil à la lecture, et archive pour le seul destinataire', function (): void {
    [$a, $b] = [($this->compte)('admin'), ($this->compte)('admin')];
    $m = $this->messagerie->envoyer($a, [$b->id], 'Objet', 'Corps');
    $this->messagerie->repondre($a, $m, 'Suite');
    expect($this->messagerie->nonLus($b))->toBe(2);

    expect($this->messagerie->lireFil($m, $b))->toBe(2)
        ->and($this->messagerie->nonLus($b))->toBe(0)
        ->and(MessageDestinataire::query()->where('user_id', $b->id)->whereNull('lu_le')->count())->toBe(0);

    $this->messagerie->archiver($m, $b);
    expect(MessageDestinataire::query()->where('message_id', $m->id)->value('archive_le'))->not->toBeNull()
        ->and(fn () => $this->messagerie->archiver($m, $a))->toThrow(InvalidArgumentException::class, 'destinataire');
    $this->messagerie->archiver($m, $b, false);
    expect(MessageDestinataire::query()->where('message_id', $m->id)->value('archive_le'))->toBeNull();
});

it('contrôle les pièces jointes, les range lui-même, en garde l\'empreinte et ne les donne qu\'aux participants', function (): void {
    [$a, $b, $intrus] = [($this->compte)('admin'), ($this->compte)('admin'), ($this->compte)('admin')];
    $contenu = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    $m = $this->messagerie->envoyer($a, [$b->id], 'Avec pièces', 'Voir les pièces', pieces: [deposerPiece('note.html', $contenu), docxMinimal()]);
    [$pdf, $docx] = $m->pieces()->orderBy('id')->get()->all();
    expect($pdf->nom_fichier)->toBe('note.pdf')->and($pdf->type_mime)->toBe('application/pdf')
        ->and($pdf->chemin)->toStartWith('messagerie/')->toEndWith('.pdf')
        ->and($pdf->sha256)->toBe(hash('sha256', $contenu))
        ->and(Storage::disk(PieceMessage::DISQUE)->get($pdf->chemin))->toBe($contenu)
        ->and($docx->nom_fichier)->toBe('compte-rendu.docx')->and($docx->type_mime)->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect($this->messagerie->telecharger($pdf, $b)->getStatusCode())->toBe(200)
        ->and(fn () => $this->messagerie->telecharger($pdf, $intrus))->toThrow(HttpException::class);

    expect(fn () => $this->messagerie->envoyer($a, [$b->id], 'Objet', 'Corps', pieces: [deposerPiece('script.pdf', 'echo bonjour')]))->toThrow(InvalidArgumentException::class, 'refusée')
        ->and(fn () => $this->messagerie->envoyer($intrus, [$a->id], 'Objet', 'Corps', pieces: [$pdf->chemin]))->toThrow(InvalidArgumentException::class, 'non reconnue')
        ->and(fn () => $this->messagerie->envoyer($a, [$b->id], 'Objet', 'Corps', pieces: array_fill(0, 6, deposerPiece('n.pdf', $contenu))))->toThrow(InvalidArgumentException::class, 'Au plus')
        ->and(Message::query()->count())->toBe(1);
});

it('refuse une pièce que l\'antivirus juge infectée, et n\'en laisse aucune trace', function (): void {
    app()->instance(PhotoScannerInterface::class, new class implements PhotoScannerInterface
    {
        public function isInfected(string $bytes): bool
        {
            return true;
        }
    });
    [$a, $b] = [($this->compte)('admin'), ($this->compte)('admin')];

    expect(fn () => app(Messagerie::class)->envoyer($a, [$b->id], 'Objet', 'Corps', pieces: [deposerPiece('n.pdf', '%PDF-1.4 x')]))
        ->toThrow(InvalidArgumentException::class, 'antivirus')
        ->and(Storage::disk(PieceMessage::DISQUE)->allFiles(PieceMessage::DOSSIER))->toBe([]);
});

it('efface les fichiers rangés si l\'enregistrement échoue, et limite le débit d\'un compte', function (): void {
    [$a, $b] = [($this->compte)('admin'), ($this->compte)('admin')];
    DB::unprepared("CREATE OR REPLACE FUNCTION refuser_piece() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'panne simulée'; END $$;
        CREATE TRIGGER refuser_piece BEFORE INSERT ON messages_pieces FOR EACH ROW EXECUTE FUNCTION refuser_piece();");

    expect(fn () => $this->messagerie->envoyer($a, [$b->id], 'Objet', 'Corps', pieces: [deposerPiece('n.pdf', '%PDF-1.4 x')]))->toThrow(QueryException::class)
        ->and(Storage::disk(PieceMessage::DISQUE)->allFiles(PieceMessage::DOSSIER))->toBe([])
        ->and(Message::query()->count())->toBe(0);
    DB::unprepared('DROP TRIGGER refuser_piece ON messages_pieces; DROP FUNCTION refuser_piece();');

    foreach (range(1, Messagerie::ENVOIS_PAR_HEURE) as $n) {
        $this->messagerie->envoyer($a, [$b->id], 'Message '.$n, 'Corps');
    }
    expect(fn () => $this->messagerie->envoyer($a, [$b->id], 'De trop', 'Corps'))->toThrow(InvalidArgumentException::class, 'Trop de messages');
    $this->messagerie->envoyer(null, [$b->id], 'Le système passe', 'Corps', Message::NOTIFICATION);
});

it('refuse dans la base toute modification ou suppression d\'un message et de ses pièces', function (): void {
    [$a, $b] = [($this->compte)('admin'), ($this->compte)('admin')];
    $m = $this->messagerie->envoyer($a, [$b->id], 'Objet', 'Corps', pieces: [deposerPiece('n.pdf', '%PDF-1.4 x')]);

    expect(fn () => DB::transaction(fn () => DB::table('messages')->where('id', $m->id)->update(['corps' => 'Réécrit'])))->toThrow(QueryException::class, 'ajout seul')
        ->and(fn () => DB::transaction(fn () => DB::table('messages')->where('id', $m->id)->delete()))->toThrow(QueryException::class, 'ajout seul')
        ->and(fn () => DB::transaction(fn () => DB::table('messages_pieces')->where('message_id', $m->id)->delete()))->toThrow(QueryException::class, 'ajout seul');

    $a->delete();
    expect(Message::query()->find($m->id)->expediteur_id)->toBeNull()
        ->and(Message::query()->find($m->id)->corps)->toBe('Corps');
});

it('met en file une copie externe sans le corps, et note les destinataires sans adresse', function (): void {
    $a = ($this->compte)('admin');
    $b = ($this->compte)('admin', ['phone_e164' => '+237690000001']);
    $c = ($this->compte)('admin');

    $this->messagerie->envoyer($a, [$b->id], 'Objet visible', 'Corps secret', canaux: ['email']);
    $systeme = $this->messagerie->envoyer(null, [$b->id, $c->id], 'Relance', 'Détail secret', Message::RELANCE, canaux: ['email', 'sms']);

    $envois = EnvoiMessage::query()->orderBy('id')->get();
    expect($envois)->toHaveCount(5)
        ->and($envois->pluck('texte')->join(' '))->not->toContain('secret')
        ->and($envois->first()->texte)->toContain('Objet visible')->toContain('/admin/messagerie/')
        ->and($envois->where('message_id', $systeme->id)->where('user_id', $c->id)->firstWhere('canal', 'sms')->statut)->toBe(EnvoiMessage::SANS_ADRESSE)
        ->and($envois->where('message_id', $systeme->id)->where('user_id', $b->id)->firstWhere('canal', 'sms')->adresse)->toBe('+237690000001');
});

it('envoie les copies par courriel et par SMS, retente un échec puis l\'abandonne après trois essais', function (): void {
    Mail::fake();
    $sms = new class implements SmsServiceInterface
    {
        public array $envoyes = [];

        public bool $enPanne = false;

        public function send(string $phoneE164, string $message): void
        {
            if ($this->enPanne) {
                throw new RuntimeException('Passerelle indisponible pour +237690000002 (agent@pssfp.local)');
            }
            $this->envoyes[] = [$phoneE164, $message];
        }
    };
    app()->instance(SmsServiceInterface::class, $sms);
    $b = ($this->compte)('admin', ['phone_e164' => '+237690000002']);
    $this->messagerie->envoyer(null, [$b->id], 'Relance bloquante', 'Corps', Message::RELANCE, Message::HAUTE, ['email', 'sms']);

    expect(app(CopiesExternes::class)->traiter())->toBe(['envoyes' => 2, 'echecs' => 0, 'en_attente' => 0]);
    Mail::assertSent(CopieMessageMail::class, fn (CopieMessageMail $mail): bool => $mail->hasTo($b->email) && $mail->objet === 'Relance bloquante');
    expect($sms->envoyes)->toHaveCount(1)->and($sms->envoyes[0][1])->toContain('Relance bloquante')->not->toContain('Corps')
        ->and(EnvoiMessage::query()->where('statut', EnvoiMessage::ENVOYE)->count())->toBe(2)
        ->and(app(CopiesExternes::class)->traiter())->toBe(['envoyes' => 0, 'echecs' => 0, 'en_attente' => 0]);

    $sms->enPanne = true;
    $this->messagerie->envoyer(null, [$b->id], 'Deuxième', 'Corps', Message::NOTIFICATION, canaux: ['sms']);
    expect(app(CopiesExternes::class)->traiter())->toBe(['envoyes' => 0, 'echecs' => 0, 'en_attente' => 1])
        ->and(app(CopiesExternes::class)->traiter())->toBe(['envoyes' => 0, 'echecs' => 0, 'en_attente' => 1])
        ->and(app(CopiesExternes::class)->traiter())->toBe(['envoyes' => 0, 'echecs' => 1, 'en_attente' => 0]);
    $echec = EnvoiMessage::query()->where('statut', EnvoiMessage::ECHEC)->sole();
    expect($echec->tentatives)->toBe(3)->and($echec->derniere_erreur)->toContain('Passerelle indisponible')->toContain('[numéro]')->toContain('[adresse]')->not->toContain('237690000002')
        ->and(app(CopiesExternes::class)->traiter())->toBe(['envoyes' => 0, 'echecs' => 0, 'en_attente' => 0]);
});
