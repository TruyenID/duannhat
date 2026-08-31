"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/routing";
import { ArrowRight, Users, Shuffle, Gift, Phone, Mail, MessageCircle } from "lucide-react";
import SplitText from "@/components/split-text";

/**
 * PartyStory — trang `/party` giới thiệu dịch vụ đặt tiệc / catering.
 * Lấy cảm hứng từ `betoya.jp/party/`: hero "Chuyến du hành Việt Nam
 * ngay tại nhà/văn phòng" → 3 dịch vụ (Party Size / Customize / Bento) →
 * Quy trình 3 bước → Contact CTA.
 *
 * Dùng lại animation tokens `anim-breathe`, `anim-ken-burns`,
 * `anim-float-slow`, `data-reveal`, `data-reveal-stagger` từ globals.css.
 */

// Non-translatable structural data — text comes from i18n via t.raw().
const OFFERING_META = [
  {
    key: "party-size",
    icon: Users,
    image:
      "https://images.unsplash.com/photo-1559925393-8be0ec4767c8?w=1200&q=90",
  },
  {
    key: "customize",
    icon: Shuffle,
    image:
      "https://images.unsplash.com/photo-1493770348161-369560ae357d?w=1200&q=90",
  },
  {
    key: "bento",
    icon: Gift,
    image:
      "https://images.unsplash.com/photo-1542219550-37153d387c27?w=1200&q=90",
  },
];

type Offering = {
  kanji: string;
  eyebrow: string;
  title: string;
  tagline: string;
  body: string;
};
type ProcessStep = { num: string; title: string; body: string };

export default function PartyStory() {
  const t = useTranslations("partyStory");
  const offerings = t.raw("offerings") as Offering[];
  const steps = t.raw("steps") as ProcessStep[];

  return (
    <>
      {/* ── 1. Hero editorial ──────────────────────────────────────── */}
      <section className="relative shrink-0 overflow-hidden bg-[#fcfaf2]">
        <div
          className="pointer-events-none absolute inset-0 anim-breathe"
          style={{
            background: "#f4c542",
            clipPath: "polygon(0 0, 40% 0, 0 60%)",
          }}
          aria-hidden
        />
        <div className="relative mx-auto grid w-full max-w-7xl grid-cols-1 items-center gap-10 px-4 pb-20 pt-16 md:grid-cols-[1.1fr_1fr] md:gap-14 md:px-6 md:pb-28 md:pt-24">
          <div className="anim-enter">
            <p className="text-xs font-semibold uppercase tracking-[0.24em] text-foreground/60">
              {t("heroEyebrow")}
            </p>
            <h1 className="mt-4 text-balance text-4xl font-bold leading-[1.1] tracking-tight text-primary md:text-5xl lg:text-6xl">
              <SplitText text={t("heroTitle")} />
            </h1>
            <p className="mt-6 max-w-md text-sm leading-relaxed text-foreground/80 md:text-base">
              {t("heroBody")}
            </p>
            <div className="mt-8 flex flex-wrap items-center gap-3 anim-stagger">
              <Link
                href="#offerings"
                className="inline-flex items-center rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary/90 hover:shadow-md"
              >
                {t("heroCtaOfferings")}
              </Link>
              <Link
                href="#contact"
                className="inline-flex items-center rounded-md border border-foreground/15 bg-background px-5 py-2.5 text-sm font-semibold shadow-sm transition hover:border-primary/50 hover:shadow-md"
              >
                {t("heroCtaContact")}
              </Link>
            </div>
          </div>
          <div className="relative">
            <div className="relative mx-auto aspect-[4/5] w-full max-w-[520px] overflow-hidden rounded-sm shadow-lg md:-mr-4 md:ml-auto">
              <div
                className="absolute inset-0 anim-ken-burns"
                style={{
                  backgroundImage:
                    "url(https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=1200&q=90)",
                  backgroundSize: "cover",
                  backgroundPosition: "center",
                }}
                aria-hidden
              />
            </div>
            <div
              className="pointer-events-none absolute -top-6 -left-6 h-20 w-20 anim-float-slow md:-top-10 md:-left-10 md:h-28 md:w-28"
              style={{
                background: "#f4c542",
                clipPath: "polygon(50% 0, 100% 100%, 0 100%)",
              }}
              aria-hidden
            />
          </div>
        </div>
      </section>

      {/* ── 2. Intro — "みんなで" ──────────────────────────────────── */}
      <section
        id="offerings"
        className="shrink-0 scroll-mt-24 bg-[#fcfaf2] pt-20 md:pt-28"
      >
        <div className="mx-auto w-full max-w-3xl px-4 text-center md:px-6" data-reveal>
          <p className="text-xs font-semibold uppercase tracking-[0.24em] text-foreground/60">
            {t("introEyebrow")}
          </p>
          <h2 className="mt-4 text-balance text-3xl font-bold leading-tight tracking-tight text-primary md:text-5xl">
            {t("introTitleL1")}<br />
            <span className="text-[#c79a2e]">{t("introTitleL2")}</span>
          </h2>
          <p className="mx-auto mt-6 max-w-xl text-base leading-relaxed text-foreground/80 md:text-[17px]">
            {t("introBody")}
          </p>
        </div>
      </section>

      {/* ── 3. Ba dịch vụ chính ───────────────────────────────────── */}
      <section className="shrink-0 bg-[#fcfaf2] py-16 md:py-20">
        <div className="mx-auto w-full max-w-7xl px-4 md:px-6">
          <div className="grid gap-6 md:grid-cols-3 md:gap-7" data-reveal-stagger>
            {OFFERING_META.map(({ key, icon: Icon, image }, idx) => {
              const offering = offerings[idx];
              return (
              <article
                key={key}
                className="group relative flex flex-col overflow-hidden rounded-sm bg-background shadow-[0_10px_30px_-12px_rgba(0,0,0,0.15)] transition hover:-translate-y-1 hover:shadow-[0_20px_40px_-15px_rgba(0,0,0,0.25)]"
              >
                <div className="relative aspect-[4/3] overflow-hidden">
                  <div
                    className="absolute inset-0 transition duration-700 group-hover:scale-105"
                    style={{
                      backgroundImage: `url(${image})`,
                      backgroundSize: "cover",
                      backgroundPosition: "center",
                    }}
                    aria-hidden
                  />
                  <div
                    className="absolute inset-0"
                    style={{
                      background:
                        "linear-gradient(180deg, rgba(0,0,0,0) 40%, rgba(0,0,0,0.3) 75%, rgba(0,0,0,0.7) 100%)",
                    }}
                    aria-hidden
                  />
                  <span className="anim-float-slow absolute right-5 top-5 inline-flex h-14 w-14 items-center justify-center rounded-full bg-[#f4c542] text-sm font-bold text-foreground shadow-md md:h-16 md:w-16 md:text-base">
                    {offering.kanji}
                  </span>
                  <div className="absolute inset-x-0 bottom-0 p-6 md:p-7">
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-white/80">
                      {offering.eyebrow}
                    </p>
                    <h3 className="mt-1 text-xl font-bold text-white md:text-2xl">
                      {offering.title}
                    </h3>
                  </div>
                </div>
                <div className="flex flex-1 flex-col p-6 md:p-7">
                  <div className="flex items-center gap-3">
                    <span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-[#f4c542]/20 text-[#c79a2e]">
                      <Icon className="h-4 w-4" aria-hidden />
                    </span>
                    <p className="text-sm font-semibold text-[#c79a2e]">
                      {offering.tagline}
                    </p>
                  </div>
                  <p className="mt-4 text-sm leading-relaxed text-foreground/80 md:text-[15px]">
                    {offering.body}
                  </p>
                </div>
              </article>
              );
            })}
          </div>
        </div>
      </section>

      {/* ── 4. Quy trình 3 bước ───────────────────────────────────── */}
      <section className="shrink-0 bg-[#f4ecd4] py-20 md:py-28">
        <div className="mx-auto w-full max-w-6xl px-4 md:px-6">
          <div className="mb-12 max-w-2xl" data-reveal>
            <p className="text-xs font-semibold uppercase tracking-[0.24em] text-foreground/60">
              {t("processEyebrow")}
            </p>
            <h2 className="mt-4 text-balance text-3xl font-bold leading-tight tracking-tight text-primary md:text-4xl">
              {t("processTitle")}
            </h2>
          </div>
          <div className="grid gap-6 md:grid-cols-3 md:gap-8" data-reveal-stagger>
            {steps.map(({ num, title, body }) => (
              <div
                key={num}
                className="relative rounded-sm border-l-4 border-[#f4c542] bg-[#fcfaf2] p-6 shadow-sm md:p-7"
              >
                <span className="text-5xl font-bold leading-none text-[#f4c542]/60 md:text-6xl">
                  {num}
                </span>
                <h3 className="mt-4 text-lg font-bold text-primary md:text-xl">
                  {title}
                </h3>
                <p className="mt-3 text-sm leading-relaxed text-foreground/80 md:text-[15px]">
                  {body}
                </p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── 5. Contact CTA — dark green ───────────────────────────── */}
      <section
        id="contact"
        className="shrink-0 scroll-mt-24 bg-[#0d4632] py-20 text-white/90 md:py-28"
      >
        <div className="mx-auto w-full max-w-5xl px-4 md:px-6">
          <div className="mb-12 max-w-2xl" data-reveal>
            <p className="text-xs font-semibold uppercase tracking-[0.24em] text-white/60">
              {t("contactEyebrow")}
            </p>
            <h2 className="mt-4 text-balance text-3xl font-bold leading-tight tracking-tight text-white md:text-4xl">
              {t("contactTitle")}
            </h2>
            <p className="mt-5 text-[15px] leading-relaxed text-white/80 md:text-base">
              {t("contactBody")}
            </p>
          </div>
          <div className="grid gap-5 md:grid-cols-3 md:gap-6" data-reveal-stagger>
            <ContactCard
              href="tel:+81335500000"
              icon={<Phone className="h-5 w-5" aria-hidden />}
              eyebrow={t("contactPhoneEyebrow")}
              label="03-3550-0000"
              hint={t("contactPhoneHint")}
            />
            <ContactCard
              href="https://line.me/R/ti/p/@betoya"
              icon={<MessageCircle className="h-5 w-5" aria-hidden />}
              eyebrow="LINE"
              label="@betoya"
              hint={t("contactLineHint")}
            />
            <ContactCard
              href="mailto:catering@betoya.jp?subject=Yêu cầu đặt tiệc"
              icon={<Mail className="h-5 w-5" aria-hidden />}
              eyebrow={t("contactEmailEyebrow")}
              label="catering@betoya.jp"
              hint={t("contactEmailHint")}
            />
          </div>
          <div className="mt-10 flex flex-wrap items-center justify-center gap-3" data-reveal>
            <Link
              href="/concept"
              className="inline-flex items-center gap-2 rounded-md bg-[#f4c542] px-5 py-2.5 text-sm font-semibold text-[#0d4632] shadow-sm transition hover:bg-[#f5cc58] hover:shadow-md"
            >
              {t("contactCtaConcept")}
              <ArrowRight className="h-4 w-4" />
            </Link>
            <Link
              href="/select-branch"
              className="inline-flex items-center rounded-md border border-white/20 bg-transparent px-5 py-2.5 text-sm font-semibold text-white transition hover:border-[#f4c542] hover:text-[#f4c542]"
            >
              {t("contactCtaBranches")}
            </Link>
          </div>
        </div>
      </section>
    </>
  );
}

function ContactCard({
  href,
  icon,
  eyebrow,
  label,
  hint,
}: {
  href: string;
  icon: React.ReactNode;
  eyebrow: string;
  label: string;
  hint: string;
}) {
  return (
    <Link
      href={href}
      className="group flex flex-col rounded-sm border border-white/15 bg-white/5 p-6 transition hover:-translate-y-0.5 hover:border-[#f4c542] hover:bg-white/10"
    >
      <span className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-[#f4c542] text-[#0d4632]">
        {icon}
      </span>
      <p className="mt-5 text-xs font-semibold uppercase tracking-[0.2em] text-white/60">
        {eyebrow}
      </p>
      <p className="mt-1 text-lg font-bold text-white transition group-hover:text-[#f4c542] md:text-xl">
        {label}
      </p>
      <p className="mt-2 text-xs text-white/60 md:text-sm">{hint}</p>
    </Link>
  );
}
