"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/routing";
import { ArrowRight, Flame, Leaf, Wheat } from "lucide-react";
import SplitText from "@/components/split-text";

/**
 * ConceptStory — trang `/concept` kể chi tiết "Sự tỉ mỉ" (こだわり).
 * Lấy cảm hứng từ `betoya.jp/concept/`: hero → Phở bò 14 giờ →
 * 3 bước (công thức / rau củ / sợi tươi) → Phở gà → CTA về brand story.
 *
 * Dùng lại animation tokens `anim-breathe`, `anim-ken-burns`,
 * `anim-float-slow`, `data-reveal`, `data-reveal-stagger` từ globals.css.
 */

// Non-translatable structural data — text comes from i18n via t.raw().
const STEP_META = [
  {
    key: "step-1",
    icon: Flame,
    image:
      "https://images.unsplash.com/photo-1540420773420-3366772f4999?w=1200&q=90",
  },
  {
    key: "step-2",
    icon: Leaf,
    image:
      "https://images.unsplash.com/photo-1555126634-323283e090fa?w=1200&q=90",
  },
  {
    key: "step-3",
    icon: Wheat,
    image:
      "https://images.unsplash.com/photo-1552611052-33e04de081de?w=1200&q=90",
  },
];

type Step = { kanji: string; label: string; title: string; body: string };

export default function ConceptStory() {
  const t = useTranslations("conceptStory");
  const steps = t.raw("steps") as Step[];

  return (
    <>
      {/* ── 1. Hero editorial ──────────────────────────────────────── */}
      <section className="relative shrink-0 overflow-hidden bg-[#fcfaf2]">
        <div
          className="pointer-events-none absolute inset-0 anim-breathe"
          style={{
            background: "#f4c542",
            clipPath: "polygon(100% 0, 100% 100%, 60% 100%)",
          }}
          aria-hidden
        />
        <div className="relative mx-auto grid w-full max-w-7xl grid-cols-1 items-center gap-10 px-4 pb-20 pt-16 md:grid-cols-[1fr_1.1fr] md:gap-14 md:px-6 md:pb-28 md:pt-24">
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
                href="#pho-bo"
                className="inline-flex items-center rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary/90 hover:shadow-md"
              >
                {t("heroCtaPhoBo")}
              </Link>
              <Link
                href="/about"
                className="inline-flex items-center rounded-md border border-foreground/15 bg-background px-5 py-2.5 text-sm font-semibold shadow-sm transition hover:border-primary/50 hover:shadow-md"
              >
                {t("heroCtaBrand")}
              </Link>
            </div>
          </div>
          <div className="relative">
            <div className="relative mx-auto aspect-[4/5] w-full max-w-[520px] overflow-hidden rounded-sm shadow-lg md:-ml-4">
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
              className="pointer-events-none absolute -bottom-6 -right-6 h-20 w-20 anim-float-slow md:-bottom-10 md:-right-10 md:h-28 md:w-28"
              style={{
                background: "#f4c542",
                clipPath: "polygon(0 0, 100% 0, 50% 100%)",
              }}
              aria-hidden
            />
          </div>
        </div>
      </section>

      {/* ── 2. Phở bò — intro ─────────────────────────────────────── */}
      <section
        id="pho-bo"
        className="shrink-0 scroll-mt-24 bg-[#fcfaf2] py-20 md:py-28"
      >
        <div className="mx-auto w-full max-w-3xl px-4 text-center md:px-6" data-reveal>
          <p className="text-xs font-semibold uppercase tracking-[0.24em] text-foreground/60">
            {t("phoBoEyebrow")}
          </p>
          <h2 className="mt-4 text-balance text-3xl font-bold leading-tight tracking-tight text-primary md:text-5xl">
            {t("phoBoTitleL1")}<br />
            <span className="text-[#c79a2e]">{t("phoBoTitleL2")}</span>
          </h2>
          <p className="mx-auto mt-6 max-w-xl text-base leading-relaxed text-foreground/80 md:text-[17px]">
            {t("phoBoIntro")}
          </p>
        </div>
      </section>

      {/* ── 3. Ba bước công phu ───────────────────────────────────── */}
      <section className="shrink-0 bg-[#fcfaf2] pb-24 md:pb-32">
        <div className="mx-auto w-full max-w-6xl px-4 md:px-6">
          <div className="flex flex-col gap-20 md:gap-28">
            {STEP_META.map((meta, idx) => {
              const Icon = meta.icon;
              const step = steps[idx];
              const reverse = idx % 2 === 1;
              return (
                <article
                  key={meta.key}
                  className={`grid grid-cols-1 items-center gap-10 md:grid-cols-[1fr_1.1fr] md:gap-14 ${
                    reverse ? "md:[&>div:first-child]:order-2" : ""
                  }`}
                  data-reveal
                >
                  <div className="relative">
                    <div className="relative aspect-[4/5] w-full max-w-[460px] overflow-hidden rounded-sm shadow-lg md:mx-auto">
                      <div
                        className="absolute inset-0 anim-ken-burns"
                        style={{
                          backgroundImage: `url(${meta.image})`,
                          backgroundSize: "cover",
                          backgroundPosition: "center",
                        }}
                        aria-hidden
                      />
                      <div className="absolute left-4 top-4 flex h-12 w-12 items-center justify-center rounded-full bg-[#f4c542] text-primary shadow-md md:h-14 md:w-14">
                        <span className="text-lg font-bold md:text-xl">
                          {String(idx + 1).padStart(2, "0")}
                        </span>
                      </div>
                    </div>
                    <div
                      className={`pointer-events-none absolute h-16 w-16 anim-float-slow md:h-24 md:w-24 ${
                        reverse
                          ? "-bottom-5 -left-5 md:-bottom-8 md:-left-8"
                          : "-bottom-5 -right-5 md:-bottom-8 md:-right-8"
                      }`}
                      style={{
                        background: "#0d4632",
                        clipPath: reverse
                          ? "polygon(100% 0, 100% 100%, 0 100%)"
                          : "polygon(0 0, 100% 100%, 0 100%)",
                      }}
                      aria-hidden
                    />
                  </div>
                  <div>
                    <div className="flex items-center gap-3">
                      <span className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-[#f4c542]/20 text-[#c79a2e]">
                        <Icon className="h-5 w-5" aria-hidden />
                      </span>
                      <p className="text-xs font-semibold uppercase tracking-[0.24em] text-foreground/60">
                        {t("stepLabel", { num: idx + 1, kanji: step.kanji })}
                      </p>
                    </div>
                    <h3 className="mt-4 text-balance text-2xl font-bold leading-tight tracking-tight text-primary md:text-3xl">
                      {step.title}
                    </h3>
                    <p className="mt-2 text-sm font-semibold uppercase tracking-[0.18em] text-[#c79a2e]">
                      {step.label}
                    </p>
                    <p className="mt-6 text-base leading-[1.85] text-foreground/85 md:text-[17px]">
                      {step.body}
                    </p>
                    {meta.key === "step-3" ? (
                      <div className="mt-6 rounded-sm border-l-4 border-[#f4c542] bg-[#f4ecd4] px-5 py-4">
                        <p className="text-sm italic leading-relaxed text-foreground/80 md:text-[15px]">
                          {t("step3Quote")}
                        </p>
                      </div>
                    ) : null}
                  </div>
                </article>
              );
            })}
          </div>
        </div>
      </section>

      {/* ── 4. Phở gà — "Thanh mà đậm" ────────────────────────────── */}
      <section className="shrink-0 bg-[#0d4632] py-20 text-white/90 md:py-28">
        <div className="mx-auto grid w-full max-w-6xl grid-cols-1 items-center gap-12 px-4 md:grid-cols-[1.1fr_1fr] md:gap-16 md:px-6">
          <div data-reveal>
            <p className="text-xs font-semibold uppercase tracking-[0.24em] text-white/60">
              {t("phoGaEyebrow")}
            </p>
            <h2 className="mt-4 text-balance text-3xl font-bold leading-tight tracking-tight text-white md:text-4xl">
              {t("phoGaTitleL1")}<br />{t("phoGaTitleL2")}
            </h2>
            <div className="mt-8 space-y-5 text-[15px] leading-[1.85] text-white/85 md:text-base">
              <p>{t("phoGaP1")}</p>
              <p>
                {t("phoGaP2Pre")}{" "}
                <strong className="text-[#f4c542]">{t("phoGaP2Highlight")}</strong>
                {t("phoGaP2Post")}
              </p>
              <blockquote className="border-l-2 border-[#f4c542] pl-5 text-[15px] italic leading-relaxed text-white/90 md:text-base">
                {t("phoGaQuote")}
              </blockquote>
            </div>
          </div>
          <div className="relative" data-reveal>
            <div className="relative mx-auto aspect-[4/5] w-full max-w-[480px] overflow-hidden rounded-sm shadow-xl">
              <div
                className="absolute inset-0 anim-ken-burns"
                style={{
                  backgroundImage:
                    "url(https://images.unsplash.com/photo-1569718212165-3a8278d5f624?w=1200&q=90)",
                  backgroundSize: "cover",
                  backgroundPosition: "center",
                }}
                aria-hidden
              />
            </div>
            <div
              className="pointer-events-none absolute -top-5 -left-5 h-20 w-20 anim-float-slow md:-top-8 md:-left-8 md:h-28 md:w-28"
              style={{
                background: "#f4c542",
                clipPath: "polygon(0 0, 100% 0, 0 100%)",
              }}
              aria-hidden
            />
          </div>
        </div>
      </section>

      {/* ── 5. CTA → brand story ──────────────────────────────────── */}
      <section className="shrink-0 bg-[#fcfaf2] pb-24 pt-20 md:pb-32 md:pt-28">
        <div
          className="mx-auto w-full max-w-5xl rounded-sm border border-foreground/10 bg-white px-6 py-12 text-center shadow-sm md:px-10 md:py-16"
          data-reveal
        >
          <p className="text-xs font-semibold uppercase tracking-[0.24em] text-foreground/60">
            {t("ctaEyebrow")}
          </p>
          <h2 className="mt-4 text-balance text-2xl font-bold leading-tight tracking-tight text-primary md:text-3xl">
            {t("ctaTitle")}
          </h2>
          <p className="mx-auto mt-5 max-w-xl text-sm leading-relaxed text-foreground/75 md:text-base">
            {t("ctaBody")}
          </p>
          <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
            <Link
              href="/about"
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
