<?php

declare(strict_types=1);

namespace App\Services\Messagerie;

use App\Models\EnvoiMessage;
use App\Models\Message;
use App\Models\MessageDestinataire;
use App\Models\PieceMessage;
use App\Models\User;
use App\Services\Scanner\PhotoScannerInterface;
use App\Support\Journaux;
use finfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

/**
 * Lot 14b-1 (D31) : messagerie interne (port de `envoyer_message`, sql/20_schema_messagerie_demandes.sql).
 * Le corps reste dans la plateforme ; les copies externes ne portent que l'objet et le lien.
 */
final class Messagerie
{
    public const ROUTE_LECTURE = 'filament.admin.resources.messagerie.view';

    /** Messages qu'un compte envoie au plus par heure (le système n'est pas limité). */
    public const ENVOIS_PAR_HEURE = 30;

    public const DESTINATAIRES_MAX = 100;

    public function __construct(private readonly PhotoScannerInterface $antivirus) {}

    /** Comptes du personnel : ceux qu'un rôle du back-office, porteur d'au moins une permission, fait entrer. */
    public static function personnel(): Builder
    {
        return User::query()->whereHas('roles', fn (Builder $query) => $query
            ->whereNotIn('name', User::NON_PANEL_ROLES)->whereHas('permissions'));
    }

    /**
     * Envoie un message. Expéditeur absent : message du système (relance, demande, décision, notification).
     *
     * @param  list<int>  $destinataires  identifiants de comptes du personnel
     * @param  list<string>  $canaux  copies externes : `email`, et `sms` pour le système seulement
     * @param  list<UploadedFile>  $pieces  fichiers déposés ; le service les contrôle puis les range lui-même
     */
    public function envoyer(
        ?User $expediteur,
        array $destinataires,
        string $objet,
        string $corps,
        string $categorie = Message::MESSAGE,
        string $priorite = Message::NORMALE,
        array $canaux = [],
        ?Message $reponseA = null,
        array $pieces = [],
    ): Message {
        $objet = trim($objet);
        $corps = trim($corps);
        $this->verifierEntete($expediteur, $categorie, $priorite, $canaux, $objet, $corps);
        $ids = $this->destinataires($expediteur, $destinataires);
        if ($reponseA !== null && ($reponseA->estDuSysteme() || $expediteur === null || ! $this->peutVoir($expediteur, $reponseA))) {
            throw new InvalidArgumentException('On ne répond qu\'à un fil dont on fait partie, et jamais à un message du système.');
        }
        $cle = 'messagerie:'.($expediteur?->id ?? 'systeme');
        if ($expediteur !== null && RateLimiter::tooManyAttempts($cle, self::ENVOIS_PAR_HEURE)) {
            throw new InvalidArgumentException('Trop de messages envoyés en une heure : réessayez plus tard.');
        }
        $fichiers = $this->verifierPieces($pieces);
        $ranges = [];
        try {
            $message = $this->enregistrer($expediteur, $ids, $objet, $corps, $categorie, $priorite, $canaux, $reponseA, $fichiers, $ranges);
        } catch (Throwable $e) {
            Storage::disk(PieceMessage::DISQUE)->delete($ranges);

            throw $e;
        }
        if ($expediteur !== null) {
            RateLimiter::hit($cle, 3600);
        }

        return $message;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $canaux
     * @param  list<array{fichier: UploadedFile, nom_fichier: string, type_mime: string, taille_octets: int, sha256: string}>  $fichiers
     * @param  list<string>  $ranges  chemins écrits sur le disque, à effacer si la transaction échoue
     */
    private function enregistrer(?User $expediteur, array $ids, string $objet, string $corps, string $categorie, string $priorite,
        array $canaux, ?Message $reponseA, array $fichiers, array &$ranges): Message
    {
        return DB::transaction(function () use ($expediteur, $ids, $objet, $corps, $categorie, $priorite, $canaux, $reponseA, $fichiers, &$ranges): Message {
            $uuid = (string) Str::uuid();
            $message = new Message;
            $message->forceFill([
                'uuid' => $uuid, 'fil' => $reponseA?->fil ?? $uuid, 'expediteur_id' => $expediteur?->id,
                'categorie' => $categorie, 'priorite' => $priorite, 'objet' => $objet, 'corps' => $corps,
                'reponse_a' => $reponseA?->id, 'envoye_le' => now(),
            ])->save();

            MessageDestinataire::query()->insert(array_map(fn (int $id): array => ['message_id' => $message->id, 'user_id' => $id], $ids));
            foreach ($fichiers as $f) {
                $nom = Str::ulid().'.'.PieceMessage::EXTENSIONS[$f['type_mime']];
                $chemin = Storage::disk(PieceMessage::DISQUE)->putFileAs(PieceMessage::DOSSIER, $f['fichier'], $nom);
                if ($chemin === false) {
                    throw new InvalidArgumentException('Pièce jointe non enregistrée : réessayez.');
                }
                $ranges[] = $chemin;
                PieceMessage::query()->create(['message_id' => $message->id, 'chemin' => $chemin]
                    + array_intersect_key($f, array_flip(['nom_fichier', 'type_mime', 'taille_octets', 'sha256'])));
            }
            $this->mettreEnFile($message, $ids, $canaux);

            activity(Journaux::MESSAGERIE)->causedBy($expediteur)->performedOn($message)->event('message_envoye')
                ->withProperties(['categorie' => $categorie, 'destinataires' => count($ids), 'pieces' => count($fichiers), 'canaux' => array_values($canaux)])
                ->log($expediteur === null ? 'Message du système envoyé' : 'Message envoyé');

            return $message;
        });
    }

    /**
     * Répond à tous les participants du fil, sauf à soi-même.
     *
     * @param  list<string>  $canaux
     * @param  list<UploadedFile>  $pieces
     */
    public function repondre(User $auteur, Message $parent, string $corps, array $canaux = [], array $pieces = []): Message
    {
        if ($parent->estDuSysteme() || ! $this->peutVoir($auteur, $parent)) {
            throw new InvalidArgumentException('On ne répond qu\'à un fil dont on fait partie, et jamais à un message du système.');
        }
        $participants = $parent->destinataires()->pluck('user_id')->push($parent->expediteur_id)
            ->filter()->map(fn ($id): int => (int) $id)->reject(fn (int $id): bool => $id === $auteur->id)->unique()->values()->all();
        $objet = Str::startsWith($parent->objet, 'RE : ') ? $parent->objet : Str::limit('RE : '.$parent->objet, Message::OBJET_MAX, '');

        return $this->envoyer($auteur, $participants, $objet, $corps, canaux: $canaux, reponseA: $parent, pieces: $pieces);
    }

    public function peutVoir(User $user, Message $message): bool
    {
        return $message->expediteur_id === $user->id
            || $message->destinataires()->where('user_id', $user->id)->exists();
    }

    /** Messages du fil que l'utilisateur voit, du plus ancien au plus récent. */
    public function fil(Message $message, User $user): Collection
    {
        return Message::query()->where('fil', $message->fil)
            ->where(fn (Builder $q) => $q->where('expediteur_id', $user->id)
                ->orWhereHas('destinataires', fn (Builder $d) => $d->where('user_id', $user->id)))
            ->with(['expediteur:id,name', 'destinataires.user:id,name', 'pieces'])
            ->orderBy('envoye_le')->orderBy('id')->get();
    }

    /** Marque lus, pour l'utilisateur, les messages du fil qui lui sont adressés. */
    public function lireFil(Message $message, User $user): int
    {
        return MessageDestinataire::query()->where('user_id', $user->id)->whereNull('lu_le')
            ->whereIn('message_id', Message::query()->where('fil', $message->fil)->select('id'))
            ->update(['lu_le' => now()]);
    }

    public function archiver(Message $message, User $user, bool $archiver = true): void
    {
        $n = MessageDestinataire::query()->where('message_id', $message->id)->where('user_id', $user->id)
            ->update(['archive_le' => $archiver ? now() : null, 'lu_le' => DB::raw('coalesce(lu_le, now())')]);
        if ($n === 0) {
            throw new InvalidArgumentException('Seul un destinataire classe un message.');
        }
    }

    public function nonLus(User $user): int
    {
        return MessageDestinataire::query()->where('user_id', $user->id)->whereNull('lu_le')->whereNull('archive_le')->count();
    }

    public function telecharger(PieceMessage $piece, User $user): StreamedResponse
    {
        abort_unless($this->peutVoir($user, $piece->message), 403);

        return Storage::disk(PieceMessage::DISQUE)->download($piece->chemin, $piece->nom_fichier);
    }

    public static function lien(Message $message): string
    {
        return route(self::ROUTE_LECTURE, ['record' => $message->uuid]);
    }

    /** @param  list<string>  $canaux */
    private function verifierEntete(?User $expediteur, string $categorie, string $priorite, array $canaux, string $objet, string $corps): void
    {
        if (! array_key_exists($categorie, Message::CATEGORIES) || ! array_key_exists($priorite, Message::PRIORITES)) {
            throw new InvalidArgumentException('Catégorie ou priorité inconnue.');
        }
        if (($expediteur === null) === ($categorie === Message::MESSAGE)) {
            throw new InvalidArgumentException('Les relances, demandes, décisions et notifications viennent du système ; un message vient d\'un compte.');
        }
        $permis = $expediteur === null ? EnvoiMessage::CANAUX : [EnvoiMessage::EMAIL];
        if (array_diff($canaux, $permis) !== []) {
            throw new InvalidArgumentException('Canal de copie externe non permis.');
        }
        if ($objet === '' || mb_strlen($objet) > Message::OBJET_MAX) {
            throw new InvalidArgumentException('L\'objet compte de 1 à '.Message::OBJET_MAX.' caractères.');
        }
        if ($corps === '' || mb_strlen($corps) > Message::CORPS_MAX) {
            throw new InvalidArgumentException('Le message compte de 1 à '.Message::CORPS_MAX.' caractères.');
        }
    }

    /**
     * @param  list<int>  $demandes
     * @return list<int>
     */
    private function destinataires(?User $expediteur, array $demandes): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $demandes),
            fn (int $id): bool => $id > 0 && $id !== $expediteur?->id)));
        if ($ids === []) {
            throw new InvalidArgumentException('Message sans destinataire.');
        }
        if (count($ids) > self::DESTINATAIRES_MAX) {
            throw new InvalidArgumentException('Au plus '.self::DESTINATAIRES_MAX.' destinataires par message.');
        }
        if ($expediteur !== null && ! self::personnel()->whereKey($expediteur->id)->exists()) {
            throw new InvalidArgumentException('Seul le personnel écrit dans la messagerie.');
        }
        if (self::personnel()->whereKey($ids)->count() !== count($ids)) {
            throw new InvalidArgumentException('Un destinataire n\'est pas un compte du personnel.');
        }

        return $ids;
    }

    /**
     * Contrôle les fichiers déposés : jamais un chemin (état Livewire falsifiable), type lu dans le contenu, taille, antivirus.
     *
     * @param  list<mixed>  $pieces
     * @return list<array{fichier: UploadedFile, nom_fichier: string, type_mime: string, taille_octets: int, sha256: string}>
     */
    private function verifierPieces(array $pieces): array
    {
        if (count($pieces) > PieceMessage::NOMBRE_MAX) {
            throw new InvalidArgumentException('Au plus '.PieceMessage::NOMBRE_MAX.' pièces jointes.');
        }
        $fichiers = [];
        foreach ($pieces as $p) {
            $chemin = $p instanceof UploadedFile ? $p->getRealPath() : false;
            if ($chemin === false || ! is_file($chemin)) {
                throw new InvalidArgumentException('Pièce jointe non reconnue : déposez le fichier à nouveau.');
            }
            $taille = (int) filesize($chemin);
            $type = $this->typeDe($chemin);
            if ($taille < 1 || $taille > PieceMessage::TAILLE_MAX || $type === null) {
                throw new InvalidArgumentException('Pièce jointe refusée : PDF, image, Excel ou Word, 10 Mo au plus.');
            }
            $contenu = (string) file_get_contents($chemin);
            if ($this->antivirus->isInfected($contenu)) {
                throw new InvalidArgumentException('Pièce jointe refusée par l\'antivirus.');
            }
            $base = trim(pathinfo(basename(str_replace('\\', '/', $p->getClientOriginalName())), PATHINFO_FILENAME));
            $fichiers[] = [
                'fichier' => $p, 'type_mime' => $type, 'taille_octets' => $taille, 'sha256' => hash('sha256', $contenu),
                // L'extension suit le type lu dans le contenu, jamais le nom donné par le client.
                'nom_fichier' => Str::limit($base !== '' ? $base : 'piece', 240, '').'.'.PieceMessage::EXTENSIONS[$type],
            ];
        }

        return $fichiers;
    }

    /** Type MIME lu dans le contenu ; les formats Office, parfois vus comme une simple archive, sont reconnus à leur structure. */
    private function typeDe(string $chemin): ?string
    {
        $type = (string) (new finfo(FILEINFO_MIME_TYPE))->file($chemin);
        if (array_key_exists($type, PieceMessage::TYPES)) {
            return $type;
        }
        if (! in_array($type, ['application/zip', 'application/octet-stream'], true)) {
            return null;
        }
        $zip = new ZipArchive;
        if ($zip->open($chemin) !== true) {
            return null;
        }
        try {
            if ($zip->locateName('[Content_Types].xml') === false) {
                return null;
            }

            return match (true) {
                $zip->locateName('word/document.xml') !== false => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                $zip->locateName('xl/workbook.xml') !== false => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                default => null,
            };
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $canaux
     */
    private function mettreEnFile(Message $message, array $ids, array $canaux): void
    {
        if ($canaux === []) {
            return;
        }
        $lien = self::lien($message);
        $comptes = User::query()->whereKey($ids)->get(['id', 'email', 'phone_e164']);
        $lignes = [];
        foreach ($comptes as $u) {
            foreach (array_unique($canaux) as $canal) {
                $adresse = $canal === EnvoiMessage::EMAIL ? $u->email : $u->phone_e164;
                $lignes[] = [
                    'message_id' => $message->id, 'user_id' => $u->id, 'canal' => $canal, 'adresse' => $adresse,
                    'texte' => $this->texteExterne($message, $canal, $lien), 'tentatives' => 0,
                    'statut' => blank($adresse) ? EnvoiMessage::SANS_ADRESSE : EnvoiMessage::A_ENVOYER,
                ];
            }
        }
        EnvoiMessage::query()->insert($lignes);
    }

    private function texteExterne(Message $message, string $canal, string $lien): string
    {
        return $canal === EnvoiMessage::SMS
            ? Str::limit('PSSFP : '.Str::limit($message->objet, 60).'. Message à lire dans la plateforme : '.$lien, 480, '')
            : Str::limit('PSSFP — '.$message->objet.'. Un message vous attend dans votre messagerie : '.$lien, 480, '');
    }
}
