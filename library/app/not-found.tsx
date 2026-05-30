import Link from 'next/link';

export default function NotFound() {
  return (
    <div className="mx-auto max-w-3xl px-6 py-24 text-center">
      <h1 className="font-heading text-5xl font-bold text-[#4A2E67]">404</h1>
      <p className="mt-4 text-lg text-[#333333]">
        Document introuvable.
      </p>
      <Link
        href="/"
        className="mt-8 inline-block rounded-md bg-[#4A2E67] px-6 py-3 font-medium text-white hover:bg-[#5C3A7E]"
      >
        Retour à la bibliothèque
      </Link>
    </div>
  );
}
