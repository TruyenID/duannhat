"use client";

import { useEffect, useState } from "react";
import CheckoutPage from "@/components/checkout-page";
import CheckoutPageMobile from "@/components/checkout-page-mobile";

export default function Checkout() {
  const [isMobile, setIsMobile] = useState(false);

  useEffect(() => {
    const checkMobile = () => {
      setIsMobile(window.innerWidth < 768);
    };

    checkMobile();
    window.addEventListener("resize", checkMobile);
    return () => window.removeEventListener("resize", checkMobile);
  }, []);

  return (
    // Page bg theo design system flow Dine-in/Takeaway — #FAFAFA phủ ngoài
    // CheckoutPage / CheckoutPageMobile, các card bên trong giữ bg-white riêng.
    <div className="min-h-screen bg-[#FAFAFA]">
      {isMobile ? <CheckoutPageMobile /> : <CheckoutPage />}
    </div>
  );
}
