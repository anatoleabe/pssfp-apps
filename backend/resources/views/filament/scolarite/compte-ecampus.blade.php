{{-- Compte eCampus réel d'un auditeur, lu à l'ouverture de la fenêtre (19/11/2026). eCampus ne rend jamais le mot de passe. --}}
<div class="space-y-4 text-sm">
    @if ($erreur !== null)
        <p class="rounded-lg bg-danger-50 px-4 py-3 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">{{ $erreur }}</p>
    @elseif ($compte === null)
        <p class="rounded-lg bg-warning-50 px-4 py-3 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
            Aucun compte eCampus ne porte l'identifiant <span class="font-mono font-semibold">{{ $identifiantAttendu }}</span>.
        </p>
    @else
        <dl class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
            @foreach ([
                ['Identifiant (réel, sur eCampus)', $compte['identifiant'], true],
                ['Nom sur eCampus', $compte['nom'], false],
                ['E-mail sur eCampus', $compte['email'], false],
                ['État du compte', $compte['suspendu'] ? 'Suspendu : l\'auditeur ne peut pas se connecter' : 'Actif', false],
                ['Dernière connexion', $compte['derniere_connexion']?->timezone('Africa/Douala')->translatedFormat('j F Y à H\hi') ?? 'Jamais', false],
            ] as [$libelle, $valeur, $mono])
                <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                    <dt class="text-gray-500 dark:text-gray-400">{{ $libelle }}</dt>
                    <dd @class(['text-right font-medium text-gray-950 dark:text-white', 'font-mono text-base' => $mono])>{{ $valeur }}</dd>
                </div>
            @endforeach
        </dl>

        @unless ($compte['correspond'])
            <p class="rounded-lg bg-warning-50 px-4 py-3 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                Ce compte ne porte ni le matricule ni l'e-mail du dossier : vérifiez qu'il s'agit bien de cet auditeur. Le portail n'en changera pas le mot de passe.
            </p>
        @endunless
    @endif

    <p class="text-xs text-gray-500 dark:text-gray-400">
        eCampus ne garde qu'une empreinte du mot de passe : personne ne peut le relire, pas même un administrateur.
        @if ($motDePasseConnu)
            Le mot de passe affiché dans « Identifiants Moodle » est celui attribué ou réinitialisé par le portail ; si l'auditeur l'a changé ou ne parvient pas à se connecter, réinitialisez-le à sa demande.
        @else
            Le portail ne connaît pas le mot de passe de ce compte (compte d'une promotion antérieure) : réinitialisez-le à la demande de l'auditeur.
        @endif
    </p>
</div>
