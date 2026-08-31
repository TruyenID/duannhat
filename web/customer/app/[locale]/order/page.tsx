import Header from "@/components/Header";
import OrderTypeLanding from "@/components/order-type-landing";

export const metadata = { title: "Đặt đơn · Tempo" };

export default function OrderPage() {
  return (
    // Desktop: ép flex column + min-h-screen để `OrderTypeLanding` (đã có
    // `flex-1 items-center justify-center` ở root) thực sự stretch và
    // center nội dung theo chiều dọc viewport. Mobile vẫn dùng block flow
    // bình thường (nội dung ngắn, py-12 đủ tạo khoảng cách).
    <div className="md:flex md:min-h-screen md:flex-col">
	      <Header hideSwitcher showLogo hideRegister />
      <OrderTypeLanding />
    </div>
  );
}
