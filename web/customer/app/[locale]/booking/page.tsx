"use client";

import { Suspense } from "react";
import { useTranslations } from 'next-intl';
import Header from "@/components/Header";
import BookingPage from "@/components/booking-page";

export default function Booking() {
  const t = useTranslations('common');
  return (
    <>
      <Header showBack hideOrderCta />
      <Suspense fallback={<div className="flex flex-1 items-center justify-center text-sm text-muted-foreground">{t('loading')}</div>}>
        <BookingPage />
      </Suspense>
    </>
  );
}
