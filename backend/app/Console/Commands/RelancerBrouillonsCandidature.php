<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CampagneCandidature;
use App\Services\CandidatureService;
use App\Services\RelanceCandidatureService;
use Illuminate\Console\Command;

/**
 * Relance par SMS les candidats dont le dossier n'a jamais été soumis.
 *
 * Simulation par défaut : la commande n'envoie RIEN tant que `--envoyer`
 * n'est pas passé explicitement. Une campagne de relance touche de vraies
 * personnes et ne se rattrape pas — le geste doit être délibéré.
 *
 * Exemples :
 *   # Voir qui serait relancé et avec quel texte, sans rien envoyer
 *   php artisan candidatures:relancer-brouillons
 *
 *   # Envoi réel, limité à un dossier (test de bout en bout)
 *   php artisan candidatures:relancer-brouillons --envoyer --limite=1
 *
 *   # Envoi réel aux seuls dossiers complets non soumis
 *   php artisan candidatures:relancer-brouillons --envoyer --cause=ready
 */
final class RelancerBrouillonsCandidature extends Command
{
    protected $signature = 'candidatures:relancer-brouillons
                            {--envoyer : Envoyer réellement les SMS (sinon simulation)}
                            {--cause=* : Restreindre aux causes ready et/ou photo_only}
                            {--limite= : Nombre maximum de candidats à relancer}
                            {--campagne= : Slug de campagne (défaut : la campagne ouverte)}';

    protected $description = 'Relance par SMS les candidats dont le dossier est resté en brouillon';

    public function handle(RelanceCandidatureService $relances): int
    {
        $campagne = $this->resoudreCampagne();
        if ($campagne === null) {
            $this->error('Aucune campagne ouverte, et aucun slug valide fourni via --campagne.');

            return self::FAILURE;
        }

        $causes = $this->resoudreCauses();
        if ($causes === []) {
            $this->error('Causes invalides. Valeurs acceptées : ready, photo_only.');

            return self::FAILURE;
        }

        $envoiReel = (bool) $this->option('envoyer');
        $limite = $this->option('limite') !== null ? max(0, (int) $this->option('limite')) : null;

        // Garde-fou décisif : le provider `fake` réussit sans rien envoyer.
        // Un envoi réel dans cet état marquerait tous les candidats comme
        // relancés, et l'anti-doublon interdirait ensuite le vrai envoi — les
        // candidats ne recevraient jamais rien, sans que personne ne le voie.
        $provider = (string) config('services.sms.provider', 'fake');
        if ($envoiReel && $provider === 'fake') {
            $this->error('SMS_PROVIDER vaut « fake » : aucun SMS ne partirait réellement.');
            $this->line('Les candidats seraient pourtant marqués comme relancés, ce qui');
            $this->line('empêcherait définitivement un envoi ultérieur.');
            $this->newLine();
            $this->line('Configurez la passerelle avant de relancer :');
            $this->line('  SMS_PROVIDER=gateway_api');
            $this->line('  SMS_GATEWAY_BASE_URL=https://.../api');
            $this->line('  SMS_GATEWAY_TOKEN=...');
            $this->line('  SMS_GATEWAY_SENDER_ID=...');

            return self::FAILURE;
        }

        $this->line('Campagne  : '.$campagne->slug);
        $this->line('Causes    : '.implode(', ', $causes));
        $this->line('Limite    : '.($limite ?? 'aucune'));
        $this->newLine();

        if ($envoiReel) {
            $this->warn('MODE ENVOI REEL — des SMS vont partir vers de vrais candidats.');
            if ($this->input->isInteractive() && ! $this->confirm('Confirmer l\'envoi ?', false)) {
                $this->info('Annulé.');

                return self::SUCCESS;
            }
        } else {
            $this->info('MODE SIMULATION — aucun SMS ne sera envoyé. Ajoutez --envoyer pour envoyer.');
        }
        $this->newLine();

        $rapport = $relances->relancer($campagne, $causes, $envoiReel, $limite);

        $this->afficherDetails($rapport['details'], $envoiReel);

        $this->newLine();
        $this->table(['Résultat', 'Nombre'], [
            [$envoiReel ? 'SMS envoyés' : 'SMS qui seraient envoyés',
                $envoiReel ? $rapport['envoyes'] : count($rapport['details'])],
            ['Échecs', $rapport['echecs']],
            ['Ignorés — déjà relancés', $rapport['ignores_deja_relances']],
            ['Ignorés — sans numéro exploitable', $rapport['ignores_sans_numero']],
        ]);

        return $rapport['echecs'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $details
     */
    private function afficherDetails(array $details, bool $envoiReel): void
    {
        if ($details === []) {
            $this->info('Aucun candidat à relancer.');

            return;
        }

        // Numéros masqués : cette sortie finit dans des historiques de terminal
        // et des captures d'écran.
        $this->table(
            ['Dossier', 'Téléphone', 'Cause', $envoiReel ? 'Statut' : 'Message'],
            array_map(fn (array $d): array => [
                $d['dossier'] ?? '—',
                $d['telephone'] ?? '—',
                $d['cause'] ?? '—',
                $envoiReel ? ($d['statut'] ?? '—') : ($d['message'] ?? '—'),
            ], $details),
        );
    }

    private function resoudreCampagne(): ?CampagneCandidature
    {
        $slug = $this->option('campagne');

        if (is_string($slug) && $slug !== '') {
            return CampagneCandidature::where('slug', $slug)->first();
        }

        return CampagneCandidature::currentlyOpen()->orderByDesc('opens_at')->first();
    }

    /**
     * @return list<string>
     */
    private function resoudreCauses(): array
    {
        /** @var list<string> $demandees */
        $demandees = (array) $this->option('cause');

        if ($demandees === []) {
            return RelanceCandidatureService::CAUSES;
        }

        $valides = [CandidatureService::DRAFT_READY, CandidatureService::DRAFT_PHOTO_ONLY];
        $retenues = array_values(array_intersect($demandees, $valides));

        return count($retenues) === count($demandees) ? $retenues : [];
    }
}
