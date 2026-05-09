import { CookieBanner } from '@/components/CookieBanner';
import { SiteFooter } from '@/components/SiteFooter';
import { SiteHeader } from '@/components/SiteHeader';

export default function PublicLayout({
  children,
}: {
  children: React.ReactNode;
}): JSX.Element {
  return (
    <>
      <SiteHeader />
      <main id="main" className="min-h-[calc(100vh-200px)]">
        {children}
      </main>
      <SiteFooter />
      <CookieBanner />
    </>
  );
}
