"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/routing";
import { ArrowRight, Quote } from "lucide-react";
import SplitText from "@/components/split-text";

/**
 * BrandStory — trang `/about` kể câu chuyện thương hiệu.
 * Lấy cảm hứng từ `betoya.jp/about/`: hero editorial → Tokyo 2018 →
 * ba hoài nghi → niềm tin → rào cản nguyên liệu → 2021 mở cửa Tsukiji →
 * CTA về craftsmanship / trang chủ.
 *
 * Animation dựa vào `data-reveal`, `anim-breathe`,
 * `anim-ken-burns`, `anim-float-slow` đã khai báo trong globals.css và trigger
 * bởi <ScrollReveal /> gắn ở page root.
 */

export default function BrandStory() {
  const t = useTranslations("brandStory");
  const doubts = t.raw("doubts") as string[];

  return (
    <>
      {/* ── 1. Hero editorial ────────────────────────────────────────────── */}
      <section className="relative shrink-0 overflow-hidden bg-[#fcfaf2]">
        <div
          className="pointer-events-none absolute inset-0 anim-breathe"
          style={{
            background: "#f4c542",
            clipPath: "polygon(0 55%, 40% 100%, 0 100%)",
          }}
          aria-hidden
        />
        <div className="relative mx-auto grid w-full max-w-7xl grid-cols-1 items-center gap-10 px-4 pb-20 pt-16 md:grid-cols-[1.1fr_1fr] md:gap-14 md:px-6 md:pb-28 md:pt-24">
          <div className="anim-enter order-2 md:order-1">
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
                href="/"
                className="inline-flex items-center rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary/90 hover:shadow-md"
              >
                {t("heroCtaHome")}
              </Link>
              <Link
                href="/select-branch"
                className="inline-flex items-center rounded-md border border-foreground/15 bg-background px-5 py-2.5 text-sm font-semibold shadow-sm transition hover:border-primary/50 hover:shadow-md"
              >
                {t("heroCtaBranches")}
              </Link>
            </div>
          </div>
          <div className="relative order-1 md:order-2">
            <div className="relative mx-auto aspect-[3/4] w-full max-w-[460px] overflow-hidden rounded-sm shadow-lg md:-mr-4 md:ml-auto">
              <div
                className="absolute inset-0 anim-ken-burns"
                style={{
                  backgroundImage:
                    "url(https://images.unsplash.com/photo-1583224944844-5b268c057b72?w=1200&q=90)",
                  backgroundSize: "cover",
                  backgroundPosition: "center",
                }}
                aria-hidden
              />
            </div>
            <div
              className="pointer-events-none absolute -top-6 -left-6 h-16 w-16 anim-float-slow md:-top-10 md:-left-10 md:h-20 md:w-20"
              style={{
                background: "#f4c542",
                clipPath: "polygon(50% 0, 100% 100%, 0 100%)",
              }}
              aria-hidden
            />
          </div>
        </div>
      </section>

      {/* ── 2. Tokyo 2018 — origin ───────────────────────────────────────── */}
      <section className="shrink-0 bg-[#fcfaf2] py-20 md:py-28">
        <div className="mx-auto w-full max-w-3xl px-4 md:px-6" data-reveal>
          <p className="text-xs font-semibold uppercase tracking-[0.24em] text-foreground/60">
            {t("originEyebrow")}
          </p>
          <h2 className="mt-4 text-balance text-3xl font-bold leading-tight tracking-tight text-primary md:text-4xl">
            {t("originTitle")}
          </h2>
          <div className="mt-8 space-y-5 text-base leading-[1.85] text-foreground/85 md:text-[17px]">
            <p>{t("originP1")}</p>
            <p>
              {t("originP2Pre")}{" "}
              <strong className="text-primary">{t("originP2Highlight")}</strong>
              {t("originP2Post")}
            </p>
            <p>
              {t("originP3Pre")}{" "}
              <em>{t("originP3Em")}</em>
              {t("originP3Post")}
            </p>
          </div>
        </div>
      </section>

      {/* ── 3. Ba hoài nghi (blockquotes) ────────────────────────────────── */}
      <section className="relative shrink-0 overflow-hidden bg-[#f4ecd4] py-20 md:py-28">
        <div className="mx-auto w-full max-w-5xl px-4 md:px-6">
          <div className="mb-12 max-w-2xl" data-reveal>
            <p className="text-xs font-semibold uppercase tracking-[0.24em] text-foreground/60">
              {t("doubtsEyebrow")}
            </p>
            <h2 className="mt-4 text-balance text-3xl font-bold leading-tight tracking-tight text-primary md:text-4xl">
              {t("doubtsTitle")}
            </h2>
            <p className="mt-5 text-base leading-relaxed text-foreground/80 md:text-[17px]">
              {t("doubtsIntro")}
            </p>
          </div>
          <div
            className="grid gap-6 md:grid-cols-3 md:gap-8"
            data-reveal-stagger
          >
            {doubts.map((quote, idx) => (
              <figure
                key={quote}
                className="relative flex flex-col rounded-sm border-l-4 border-[#f4c542] bg-[#fcfaf2] p-6 shadow-sm md:p-7"
              >
                <Quote
                  className="absolute right-5 top-5 h-7 w-7 text-[#f4c542]/70 anim-float-slow"
                  aria-hidden
                />
                <blockquote className="pr-8 text-base leading-relaxed text-foreground/80 md:text-[17px]">
                  「{quote}」
                </blockquote>
                <figcaption className="mt-5 text-xs font-semibold uppercase tracking-[0.18em] text-foreground/50">
                  #{String(idx + 1).padStart(2, "0")}
                </figcaption>
              </figure>
            ))}
          </div>
        </div>
      </section>

      {/* ── 4. Niềm tin — "Vậy thì chúng ta cùng làm" ────────────────────── */}
      <section className="relative shrink-0 overflow-hidden bg-[#fcfaf2] py-20 md:py-28">
        <div
          className="pointer-events-none absolute right-0 top-0 h-40 w-40 md:h-56 md:w-56"
          style={{
            background: "#f4c542",
            clipPath: "polygon(100% 0, 100% 100%, 0 0)",
          }}
          aria-hidden
        />
        <div className="relative mx-auto w-full max-w-3xl px-4 md:px-6" data-reveal>
          <p className="text-xs font-semibold uppercase tracking-[0.24em] text-foreground/60">
            {t("beliefEyebrow")}
          </p>
          <h2 className="mt-4 text-balance text-3xl font-bold leading-tight tracking-tight text-primary md:text-4xl">
            {t("beliefTitle")}
          </h2>
          <div className="mt-8 space-y-5 text-base leading-[1.85] text-foreground/85 md:text-[17px]">
            <p>{t("beliefP1")}</p>
            <blockquote className="border-l-4 border-[#f4c542] bg-[#fcfaf2] py-2 pl-6 text-lg font-semibold italic leading-relaxed text-primary md:text-xl">
              {t("beliefQuote")}
            </blockquote>
            <p>
              {t("beliefP2Pre")}{" "}
              <strong className="text-primary">{t("beliefP2Highlight")}</strong>
            </p>
          </div>
        </div>
      </section>

      {/* ── 5. Rào cản — sợi phở tươi ────────────────────────────────────── */}
      <section className="shrink-0 bg-[#0d4632] py-20 text-white/90 md:py-28">
        <div className="mx-auto grid w-full max-w-6xl grid-cols-1 gap-12 px-4 md:grid-cols-[1fr_1.1fr] md:gap-16 md:px-6">
          <div className="relative" data-reveal>
            <div className="relative aspect-[4/5] w-full max-w-[480px] overflow-hidden rounded-sm shadow-xl">
              <div
                className="absolute inset-0 anim-ken-burns"
                style={{
                  backgroundImage:
                    "url(https://images.unsplash.com/photo-1552611052-33e04de081de?w=1200&q=90)",
                  backgroundSize: "cover",
                  backgroundPosition: "center",
                }}
                aria-hidden
              />
            </div>
            <div
              className="pointer-events-none absolute -bottom-5 -right-5 h-20 w-20 anim-float-slow md:-bottom-8 md:-right-8 md:h-28 md:w-28"
              style={{
                background: "#f4c542",
                clipPath: "polygon(0 0, 100% 0, 100% 100%)",
              }}
              aria-hidden
            />
          </div>
          <div data-reveal>
            <p className="text-xs font-semibold uppercase tracking-[0.24em] text-white/60">
              {t("barrierEyebrow")}
            </p>
            <h2 className="mt-4 text-balance text-3xl font-bold leading-tight tracking-tight text-white md:text-4xl">
              {t("barrierTitleL1")}<br />{t("barrierTitleL2")}
            </h2>
            <div className="mt-8 space-y-5 text-[15px] leading-[1.85] text-white/85 md:text-base">
              <p>{t("barrierP1")}</p>
              <p>
                {t("barrierP2Pre")}{" "}
                <strong className="text-[#f4c542]">{t("barrierP2Highlight")}</strong>
                {t("barrierP2Post")}
              </p>
              <blockquote className="border-l-2 border-[#f4c542] pl-5 text-[15px] italic leading-relaxed text-white/90 md:text-base">
                {t("barrierQuoteL1")}<br />
                {t("barrierQuoteL2")}
              </blockquote>
            </div>
          </div>
        </div>
      </section>

      {/* ── 6. 2021 — Mở cửa Tsukiji ─────────────────────────────────────── */}
      <section className="shrink-0 bg-[#fcfaf2] py-20 md:py-28">
        <div className="mx-auto w-full max-w-3xl px-4 md:px-6" data-reveal>
          <p className="text-xs font-semibold uppercase tracking-[0.24em] text-foreground/60">
            {t("openingEyebrow")}
          </p>
          <h2 className="mt-4 text-balance text-3xl font-bold leading-tight tracking-tight text-primary md:text-4xl">
            {t("openingTitle")}
          </h2>
          <div className="mt-8 space-y-5 text-base leading-[1.85] text-foreground/85 md:text-[17px]">
            <p>{t("openingP1")}</p>
            <p>
              {t("openingP2Pre")}{" "}
              <strong className="text-primary">{t("openingP2Brand")}</strong>
              {t("openingP2Mid")}
              <em> {t("openingP2Em")}</em>
              {t("openingP2Post")}
            </p>
          </div>
        </div>
      </section>

      {/* ── 7. CTA — craftsmanship + home ────────────────────────────────── */}
      <section className="shrink-0 bg-[#fcfaf2] pb-24 md:pb-32">
        <div
          className="mx-auto w-full max-w-5xl rounded-sm border border-foreground/10 bg-white px-6 py-12 text-center shadow-sm md:px-10 md:py-16"
          data-reveal
        >
          <p className="text-xs font-semibold uppercase tracking-[0.24em] text-foreground/60">
            {t("ctaEyebrow")}
          </p>
          <h2 className="mt-4 text-balance text-2xl font-bold leading-tight tracking-tight text-primary md:text-3xl">
            {t("ctaTitleL1")}<br />{t("ctaTitleL2")}
          </h2>
          <p className="mx-auto mt-5 max-w-xl text-sm leading-relaxed text-foreground/75 md:text-base">
            {t("ctaBody")}
          </p>
          <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
            <Link
              href="/concept"
              className="inline-flex items-center gap-2 rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary/90 hover:shadow-md"
            >
              {t("ctaPrimary")}
              <ArrowRight className="h-4 w-4" />
            </Link>
            <Link
              href="/select-branch"
              className="inline-flex items-center rounded-md border border-foreground/15 bg-background px-5 py-2.5 text-sm font-semibold shadow-sm transition hover:border-primary/50 hover:shadow-md"
            >
              {t("ctaSecondary")}
            </Link>
          </div>
        </div>
      </section>
    </>
  );
}
