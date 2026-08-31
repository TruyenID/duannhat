import { useTranslations } from "next-intl";
import { Link } from "@/i18n/routing";

/**
 * Footer — editorial dark-green footer mirroring betoya.jp.
 * Store list và company link tạm hardcode; sau khi có API branches/pages
 * có thể swap sang `useBrand().branches` và CMS-driven menu.
 */

const STORE_KEYS = [
  "storeAeon",
  "storeMonzen",
  "storeHongo",
  "storeTameike",
  "storeJimbocho",
  "storeNingyocho",
  "storeTsukiji",
] as const;

const COMPANY_LINK_KEYS: { key: string; href: string }[] = [
  { key: "companyProfile", href: "#" },
  { key: "careers", href: "#" },
  { key: "brandStory", href: "/about" },
  { key: "concept", href: "/concept" },
  { key: "inquiry", href: "#" },
];

const LEGAL_LINK_KEYS: { key: string; href: string }[] = [
  { key: "companyProfile", href: "#" },
  { key: "specifiedCommercialTransactions", href: "#" },
  { key: "terms", href: "#" },
  { key: "privacy", href: "#" },
];

export default function Footer() {
  const t = useTranslations("footer");
  return (
    <footer className="shrink-0 bg-[#0d4632] text-white/90">
      <div className="mx-auto w-full max-w-7xl px-4 py-14 md:px-6 md:py-16">
        <div className="grid gap-10 md:grid-cols-2 lg:grid-cols-[1.2fr_1fr_1fr_auto] lg:gap-12">
          <div>
            <div className="text-3xl font-extrabold leading-none tracking-tight">VIET ORIGIN</div>
            <div className="mt-1 text-lg font-semibold text-white/85">ベト屋</div>
            <div className="mt-6 flex gap-6 text-sm">
              <Link href="#" className="transition hover:text-[#f4c542]">
                {t('langJa')}
              </Link>
              <Link href="#" className="transition hover:text-[#f4c542]">
                {t('langEn')}
              </Link>
            </div>
            <Link
              href="#"
              className="mt-6 inline-flex items-center justify-center rounded-full bg-[#5d8a73] px-8 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-white shadow-sm transition hover:bg-[#6b9a81]"
            >
              {t('inquiry')}
            </Link>
          </div>

          <nav aria-label={t('storeListLabel')} className="flex flex-col gap-3 text-sm">
            {STORE_KEYS.map((key) => (
              <Link key={key} href="#" className="transition hover:text-[#f4c542]">
                {t(key)}
              </Link>
            ))}
          </nav>

          <nav aria-label={t('companyInfoLabel')} className="flex flex-col gap-3 text-sm">
            {COMPANY_LINK_KEYS.map((l) => (
              <Link key={l.key} href={l.href} className="transition hover:text-[#f4c542]">
                {t(l.key)}
              </Link>
            ))}
          </nav>

          <div className="flex items-start gap-3">
            <SocialCircle href="#" label="LINE" icon={<LineIcon />} />
            <SocialCircle href="#" label="X (Twitter)" icon={<XIcon />} />
            <SocialCircle href="#" label="Instagram" icon={<InstagramIcon />} />
            <SocialCircle href="#" label="TikTok" icon={<TikTokIcon />} />
          </div>
        </div>

        <div className="mt-14 flex flex-col gap-3 border-t border-white/15 pt-6 md:flex-row md:items-start md:justify-between md:gap-8">
          <p className="text-xs text-white/70">
            {t('copyright')}
          </p>
          <div className="flex flex-col gap-1 text-xs text-white/70 md:items-end md:text-right">
            <div className="flex flex-wrap gap-x-4 gap-y-1 md:justify-end">
              {LEGAL_LINK_KEYS.slice(0, 2).map((l) => (
                <Link key={l.key} href={l.href} className="transition hover:text-[#f4c542]">
                  {t(l.key)}
                </Link>
              ))}
            </div>
            <div className="flex flex-wrap gap-x-4 gap-y-1 md:justify-end">
              {LEGAL_LINK_KEYS.slice(2).map((l) => (
                <Link key={l.key} href={l.href} className="transition hover:text-[#f4c542]">
                  {t(l.key)}
                </Link>
              ))}
            </div>
          </div>
        </div>
      </div>
    </footer>
  );
}

function SocialCircle({
  href,
  label,
  icon,
}: {
  href: string;
  label: string;
  icon: React.ReactNode;
}) {
  return (
    <Link
      href={href}
      aria-label={label}
      className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-[#0a3a29] text-white/95 transition hover:bg-[#5d8a73]"
    >
      {icon}
    </Link>
  );
}

function LineIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-4 w-4" fill="currentColor" aria-hidden>
      <path d="M19.365 9.89c.571 0 .853.471.853.897 0 .425-.282.896-.853.896h-1.59v1.016h1.59c.472 0 .853.426.853.897 0 .472-.381.897-.853.897h-2.444c-.472 0-.853-.425-.853-.897V7.84c0-.472.381-.898.853-.898h2.444c.472 0 .853.426.853.898 0 .471-.381.897-.853.897h-1.59v1.152h1.59zM14.89 13.594c0 .472-.381.897-.853.897-.283 0-.523-.141-.664-.377l-2.492-3.387v2.867c0 .472-.381.897-.853.897-.472 0-.853-.425-.853-.897V7.84c0-.472.381-.898.853-.898.283 0 .523.141.664.377l2.492 3.386V7.84c0-.472.381-.898.853-.898.472 0 .853.426.853.898v5.754zM7.637 14.491c-.472 0-.853-.425-.853-.897V7.84c0-.472.381-.898.853-.898.472 0 .853.426.853.898v5.754c0 .472-.381.897-.853.897zm-2.4-.002H2.793c-.472 0-.853-.425-.853-.897V7.84c0-.472.381-.898.853-.898.472 0 .853.426.853.898v4.856h1.592c.471 0 .853.425.853.897 0 .472-.382.897-.854.897zM12 0C5.373 0 0 4.373 0 9.75c0 4.815 4.258 8.851 10.012 9.621.39.084.922.257 1.056.589.121.301.079.77.039 1.075l-.171 1.021c-.052.301-.24 1.18 1.036.644 1.275-.536 6.878-4.049 9.384-6.935C23.044 14 24 11.975 24 9.75 24 4.373 18.627 0 12 0z" />
    </svg>
  );
}

function XIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-4 w-4" fill="currentColor" aria-hidden>
      <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
    </svg>
  );
}

function InstagramIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
      <rect x="2" y="2" width="20" height="20" rx="5" />
      <circle cx="12" cy="12" r="4" />
      <line x1="17.5" y1="6.5" x2="17.51" y2="6.5" />
    </svg>
  );
}

function TikTokIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-4 w-4" fill="currentColor" aria-hidden>
      <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5.8 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1.84-.1z" />
    </svg>
  );
}
