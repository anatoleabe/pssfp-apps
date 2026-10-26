import { useLocale, useTranslations } from 'next-intl';
import { Mail, MessageSquareText } from 'lucide-react';
import type { CandidatureMessage } from '@/lib/api/client';
import { formatDateLong } from '@/lib/format/date';

/**
 * Ce que le PSSFP a écrit au candidat depuis l'admin (convocation, consignes
 * d'inscription…), pour qu'un SMS perdu ou un e-mail en spam ne le prive pas
 * de l'information.
 */
export function DossierMessages({ messages }: { messages: CandidatureMessage[] | undefined }): JSX.Element | null {
  const t = useTranslations('dossier.messages');
  const locale = useLocale();

  if (!messages || messages.length === 0) {
    return null;
  }

  return (
    <section
      aria-labelledby="messages-heading"
      data-testid="dossier-messages"
      className="rounded-pssfp-card border border-[#F4EFFA] bg-white p-6 shadow-pssfp-soft md:p-7"
    >
      <p className="pssfp-eyebrow">{t('eyebrow')}</p>
      <h2 id="messages-heading" className="mt-1 font-heading text-pssfp-h3 font-bold text-[#1A1A1A]">
        {t('title')}
      </h2>
      <p className="mt-2 text-sm text-[#5C5C5C]">{t('intro')}</p>

      <ol className="mt-6 space-y-4">
        {messages.map((message, i) => {
          const Icon = message.canal === 'email' ? Mail : MessageSquareText;
          return (
            <li key={`${message.sent_at ?? ''}-${i}`}>
              <article className="rounded-pssfp-card border border-[#F4EFFA] bg-[#FAF7F2] p-5">
                <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                  <h3 className="font-heading text-lg font-bold text-[#4A2E67]">
                    {message.sujet || t('defaultSubject')}
                  </h3>
                  <p className="inline-flex items-center gap-1.5 text-xs text-[#5C5C5C]">
                    <Icon size={14} aria-hidden="true" />
                    <span>{message.canal === 'email' ? t('viaEmail') : t('viaSms')}</span>
                    {message.sent_at && (
                      <>
                        <span aria-hidden="true">·</span>
                        <time dateTime={message.sent_at}>{formatDateLong(message.sent_at, locale)}</time>
                      </>
                    )}
                  </p>
                </header>
                <p className="mt-3 whitespace-pre-line text-[15px] leading-relaxed text-[#3C3C3C]">{message.message}</p>
              </article>
            </li>
          );
        })}
      </ol>
    </section>
  );
}
