<div class="space-y-3">
    @forelse ($documents as $document)
        <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="min-w-0">
                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $document['label'] }}</p>
                <p class="truncate text-sm text-gray-500 dark:text-gray-400">
                    {{ $document['filename'] }} — {{ $document['uploaded_at']?->format('d/m/Y H:i') }}
                </p>
            </div>
            <a
                href="{{ $document['url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                class="shrink-0 rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500"
            >
                Télécharger
            </a>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">Aucune pièce déposée en ligne.</p>
    @endforelse
</div>
