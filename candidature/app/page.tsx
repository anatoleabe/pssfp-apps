import { getTranslations } from 'next-intl/server';
import Link from 'next/link';
import { CountdownToClose } from '@/components/CountdownToClose';
import { getCurrentCampaign } from '@/lib/api/client';

export default async function HomePage(): Promise<JSX.Element> {
  const t = await getTranslations('home');
  const result = await getCurrentCampaign();
  const campagne = result.ok ? result.data : null;
  const isOpen = campagne?.is_currently_open === true;

  return (
    <div className="mx-auto max-w-5xl px-6 py-16">
      <section aria-labelledby="hero-heading" className="space-y-6">
        <div className="flex flex-wrap items-center gap-3">
          <h1
            id="hero-heading"
            className="font-heading text-4xl font-bold text-[#4A2E67] md:text-5xl"
          >
            {campagne?.nom ?? t('title')}
          </h1>
          {isOpen && campagne?.closes_at && (
            <CountdownToClose closesAt={campagne.closes_at} ariaLabel={t('countdownAria')} />
          )}
        </div>

        <p className="max-w-3xl text-lg text-[#333333]">
          {isOpen ? t('introOpen') : t('introClosed')}
        </p>

        {isOpen ? (
          <div className="flex flex-wrap gap-4">
            <Link
              href="/inscription"
              data-testid="cta-inscription"
              className="rounded-md bg-[#4A2E67] px-6 py-3 font-medium text-white hover:bg-[#5C3A7E]"
            >
              {t('ctaCreateAccount')}
            </Link>
            <Link
              href="/login"
              className="rounded-md border border-[#4A2E67] px-6 py-3 font-medium text-[#4A2E67] hover:bg-[#F4EFFA]"
            >
              {t('ctaLogin')}
            </Link>
          </div>
        ) : (
          <div
            role="status"
            className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
          >
            {t('campaignClosedNotice')}
          </div>
        )}
      </section>

      <section
        aria-labelledby="why-heading"
        className="mt-12 grid gap-4 md:grid-cols-2 lg:grid-cols-4"
      >
        <h2 id="why-heading" className="sr-only">
          {t('whyHeading')}
        </h2>
        <Pillar title={t('pillars.excellence.title')} body={t('pillars.excellence.body')} />
        <Pillar title={t('pillars.partenariats.title')} body={t('pillars.partenariats.body')} />
        <Pillar title={t('pillars.debouches.title')} body={t('pillars.debouches.body')} />
        <Pillar title={t('pillars.foad.title')} body={t('pillars.foad.body')} />
      </section>

      <section className="mt-12 rounded-lg border border-[#D4AF6A]/40 bg-[#FFFBEA] p-6">
        <h2 className="mb-2 font-heading text-lg font-semibold text-[#D4AF6A]">{t('feeTitle')}</h2>
        <p className="text-sm text-[#333333]">{t('feeNotice')}</p>
      </section>
    </div>
  );
}

function Pillar({ title, body }: { title: string; body: string }): JSX.Element {
  return (
    <div className="rounded-lg border border-[#F4EFFA] bg-white p-5 shadow-sm">
      <h3 className="mb-2 font-heading text-base font-semibold text-[#4A2E67]">{title}</h3>
      <p className="text-sm text-[#666]">{body}</p>
    </div>
  );
}
