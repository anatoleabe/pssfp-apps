{{-- Identifiants FOAD d'un auditeur, consultés par l'administration (consultation journalisée). --}}
<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Plateforme : <a href="{{ $foad }}" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-600 underline">{{ $foad }}</a>
    </p>

    <dl class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
        @foreach ([['Identifiant', $identifiant], ['Mot de passe', $motDePasse]] as [$libelle, $valeur])
            <div class="flex items-center justify-between gap-4 px-4 py-3" x-data="{ copie: false }">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $libelle }}</dt>
                    <dd class="mt-1 font-mono text-lg font-semibold text-gray-950 dark:text-white">{{ $valeur }}</dd>
                </div>
                <button
                    type="button"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-white/20 dark:text-gray-200 dark:hover:bg-white/5"
                    x-on:click="navigator.clipboard.writeText(@js($valeur)); copie = true; setTimeout(() => copie = false, 2000)"
                >
                    <span x-show="! copie">Copier</span>
                    <span x-show="copie" x-cloak>Copié</span>
                </button>
            </div>
        @endforeach
    </dl>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Mot de passe fixé à l'attribution du matricule, identique à celui du fichier Moodle. Il ne change pas.
    </p>
</div>
