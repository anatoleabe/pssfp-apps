import { Logo } from '@pssfp/ui';
import Link from 'next/link';
import { useTranslations } from 'next-intl';

function PublicHeader() {
  const t = useTranslations('nav');
  return (
    <header className="border-b border-gray-200 bg-white">
      <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
        <Link href="/" aria-label="PSSFP — Accueil">
          <Logo />
        </Link>
        <nav aria-label="Navigation principale" className="flex items-center gap-6 text-sm">
          <Link className="text-[#333333] hover:text-[#6B2FA0]" href="/">
            {t('home')}
          </Link>
          <a
            className="text-[#333333] hover:text-[#6B2FA0]"
            href={process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#'}
          >
            {t('library')}
          </a>
          <a
            className="rounded-md bg-[#6B2FA0] px-4 py-2 font-medium text-white hover:bg-[#9B59B6]"
            href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
          >
            {t('candidature')}
          </a>
        </nav>
      </div>
    </header>
  );
}

function PublicFooter() {
  return (
    <footer className="mt-16 border-t border-gray-200 bg-[#F5F5F5]">
      <div className="mx-auto max-w-7xl px-6 py-8 text-sm text-[#666666]">
        <p>© {new Date().getFullYear()} PSSFP — Campus de Messa, Yaoundé.</p>
      </div>
    </footer>
  );
}

export default function PublicLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <PublicHeader />
      <main id="main">{children}</main>
      <PublicFooter />
    </>
  );
}
