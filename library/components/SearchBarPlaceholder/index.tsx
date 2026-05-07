'use client';

import { useState } from 'react';

export interface SearchBarPlaceholderProps {
  placeholder: string;
}

export function SearchBarPlaceholder({ placeholder }: SearchBarPlaceholderProps) {
  const [query, setQuery] = useState('');
  return (
    <form
      role="search"
      className="mt-8 flex max-w-3xl gap-2"
      onSubmit={(event) => {
        event.preventDefault();
      }}
    >
      <label htmlFor="search-input" className="sr-only">
        {placeholder}
      </label>
      <input
        id="search-input"
        data-testid="library-search-input"
        type="search"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        placeholder={placeholder}
        className="flex-1 rounded-md border border-gray-300 px-4 py-3 text-base focus:border-[#6B2FA0] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0]"
      />
      <button
        type="submit"
        disabled
        title="Recherche disponible prochainement"
        className="rounded-md bg-[#6B2FA0] px-6 py-3 font-medium text-white opacity-60"
      >
        Rechercher
      </button>
    </form>
  );
}
