export function DossierPhotoCard(): JSX.Element {
  return (
    <section
      aria-labelledby="photo-heading"
      className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
    >
      <h2 id="photo-heading" className="font-heading text-lg font-bold text-[#6B2FA0]">
        Photo d'identité
      </h2>
      <p className="mt-3 text-sm text-[#666]">
        Vous pourrez ajouter votre photo d'identité officielle directement depuis ce
        tableau de bord — fonctionnalité disponible prochainement.
      </p>
      <button
        type="button"
        disabled
        title="Disponible prochainement"
        className="mt-4 inline-flex h-11 cursor-not-allowed items-center rounded-md border border-dashed border-gray-300 bg-gray-50 px-4 text-sm text-gray-400"
      >
        Ajouter ma photo (bientôt)
      </button>
    </section>
  );
}
