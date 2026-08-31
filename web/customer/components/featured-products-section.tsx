"use client";

import Image from "next/image";
import { useTranslations } from 'next-intl';
import { useCurrency } from "@/lib/currency";

interface FeaturedProduct {
  id: string;
  sku_id: string;
  name: string;
  price: number;
  image: string | null;
  status: string;
  options?: unknown;
}

interface FeaturedProductsSectionProps {
  products: FeaturedProduct[];
  onProductClick?: (product: FeaturedProduct) => void;
}

export function FeaturedProductsSection({
  products,
  onProductClick,
}: FeaturedProductsSectionProps) {
  const t = useTranslations('featured');
  const { format: fmt } = useCurrency();
  // issue #1042 — featured cards show the stored price as-is (the number the
  // customer is charged). Tax inclusion is surfaced by the 税込/税抜 label on
  // the full menu card + product modal, not on this teaser carousel.

  if (!products || products.length === 0) {
    return null;
  }

  return (
    <>
      <section className="mb-16 px-4">
        <div className="mb-8 text-center">
          <h2 className="font-serif text-3xl text-[#0d4632] md:text-4xl">
            {t('title')}
          </h2>
          <p className="mt-2 text-sm text-[#0d4632]/60">
            {t('subtitle')}
          </p>
        </div>

        <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
          {products.map((product) => (
            <button
              key={product.id}
              onClick={() => onProductClick?.(product)}
              className="group relative overflow-hidden rounded-2xl border-2 border-[#f4ecd4] bg-white transition-all duration-300 hover:border-[#0d4632] hover:shadow-xl"
            >
              <div className="relative aspect-square overflow-hidden bg-[#fcfaf2]">
                {product.image ? (
                  <Image
                    src={product.image}
                    alt={product.name}
                    fill
                    className="object-cover transition-transform duration-500 group-hover:scale-110"
                  />
                ) : (
                  <div className="flex h-full items-center justify-center">
                    <span className="text-4xl">🍜</span>
                  </div>
                )}

                <div className="absolute left-3 top-3 rounded-full bg-[#f4c542] px-3 py-1 text-xs font-medium text-[#0d4632] shadow-lg">
                  {t('badge')}
                </div>
              </div>

              <div className="p-4">
                <h3 className="font-serif text-base font-medium text-[#0d4632] line-clamp-2">
                  {product.name}
                </h3>
                <p className="mt-2 text-lg font-bold text-[#0d4632]">
                  {fmt(product.price)}
                </p>
              </div>
            </button>
          ))}
        </div>
      </section>
    </>
  );
}
