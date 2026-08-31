import Image from "next/image";
import { cn } from "@/lib/utils";

/** PayPay mark sourced from paypay.ne.jp brand assets (not card-network icons). */
export function PayPayBrandIcon({ className }: { className?: string }) {
  return (
    <Image
      src="/images/payments/paypay-official.svg"
      alt="PayPay"
      width={72}
      height={18}
      className={cn("h-[18px] w-auto shrink-0", className)}
      unoptimized
    />
  );
}
