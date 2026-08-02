import { getTranslations } from 'next-intl/server';
import Image from 'next/image';
import { Link } from '@/navigation';

export default async function NotFound(): Promise<JSX.Element> {
  const tnf = await getTranslations('notFound');
  return (
    <div className="mx-auto max-w-xl px-6 py-20 text-center">
      <Image src="/logos/pssfp.png" alt="PSSFP" width={88} height={88} className="mx-auto" />
      <p className="mt-6 text-sm font-semibold uppercase tracking-widest text-[#8A641D]">{tnf('code')}</p>
      <h1 className="mt-3 font-heading text-4xl font-bold text-[#4A2E67]">{tnf('title')}</h1>
      <p className="mt-4 text-base text-[#4B4B4B]">{tnf('body')}</p>
      <Link
        href="/"
        className="mt-8 inline-block rounded-md bg-[#4A2E67] px-6 py-3 font-medium text-white hover:bg-[#5C3A7E]"
      >
        {tnf('backHome')}
      </Link>
      <p className="mt-7 text-sm text-[#595959]">{tnf('needHelp')} <a className="underline" href="mailto:admissions@pssfp.org">{tnf('contact')}</a>.</p>
    </div>
  );
}
