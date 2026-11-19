<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\AuditeurResource;
use App\Http\Controllers\Controller;
use App\Models\Auditeur;
use App\Models\User;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\Ecampus\EchecEcampus;
use App\Services\Ecampus\MotsDePasseEcampus;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /admin/auditeurs/{uuid}/ecampus : ouvre la fiche du compte eCampus de l'auditeur (19/11/2026). L'administrateur,
 * connecté à eCampus, y trouve « Se connecter en tant que », que Moodle réserve à ses administrateurs et journalise.
 * Le portail ne fait que retrouver le compte : il ne transmet aucun accès.
 */
final class OuvrirCompteEcampusController extends Controller
{
    public static function peutOuvrir(?User $user): bool
    {
        return $user !== null && ComptesEcampus::actif() && $user->can(MotsDePasseEcampus::PERMISSION);
    }

    public function __invoke(Request $request, Auditeur $auditeur, MotsDePasseEcampus $comptes): RedirectResponse
    {
        abort_unless(self::peutOuvrir($request->user()) && $request->user()->can('view', $auditeur), 403);
        $dossier = $auditeur->candidature;
        abort_if($dossier === null, 404);

        try {
            $compte = $comptes->compte($dossier);
        } catch (EchecEcampus $e) {
            return $this->retour($auditeur, $e->getMessage());
        }
        if ($compte === null) {
            return $this->retour($auditeur, 'Aucun compte eCampus ne porte l\'identifiant de cet auditeur.');
        }

        activity('candidatures')->causedBy($request->user())->performedOn($dossier)->event('ecampus_compte_ouvert')
            ->withProperties(['identifiant' => $compte['identifiant']])->log('Compte eCampus de l\'auditeur ouvert depuis l\'administration');

        return redirect()->away(rtrim((string) config('pssfp.foad_url'), '/').'/user/profile.php?id='.$compte['id']);
    }

    private function retour(Auditeur $auditeur, string $message): RedirectResponse
    {
        Notification::make()->title('Compte eCampus introuvable')->body($message)->danger()->persistent()->send();

        return redirect()->to(AuditeurResource::getUrl('view', ['record' => $auditeur]));
    }
}
