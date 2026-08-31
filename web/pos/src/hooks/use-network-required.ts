/**
 * #1501 — khoá RÕ RÀNG những hành động bắt buộc phải có mạng.
 *
 * Nguyên tắc P-27 của plan-052: không để nút "bấm → chết im". Khi POS không
 * với tới máy chủ, ba việc dưới đây không thể hoàn tất và cũng KHÔNG được xếp
 * hàng chờ — thanh toán, mở ca, đóng ca/bàn giao ca. Chúng phải trông như đã
 * bị khoá, kèm lý do, chứ không phải bấm vào rồi ngồi nhìn spinner.
 *
 * Vì sao không xếp hàng: cùng lý do KDS cố ý không có hàng đợi cho bump. Một
 * hành động tiền đồng bộ muộn một giờ là SAI ở thời điểm nó chạy, không phải
 * là "trễ". Bán offline đáng tin cần chữ ký Ed25519 + catalog revision + Cloud
 * re-price (#1092) và đó là vai của workstation-app, không phải của một tab
 * trình duyệt dùng chung.
 *
 * Dùng: `<Button disabled={…logic sẵn có…} {...blockedProps}>`. Đặt SAU thuộc
 * tính `disabled` sẵn có để nó thắng khi offline; lúc online `blockedProps` là
 * object rỗng nên không đổi gì.
 */
import { useTranslation } from "@/providers/app-provider";
import { useIsOffline } from "./use-offline";

export interface NetworkRequired {
  offline: boolean;
  blockedProps: { disabled?: true; title?: string };
}

export function useNetworkRequired(): NetworkRequired {
  const offline = useIsOffline();
  const { t } = useTranslation();

  return {
    offline,
    blockedProps: offline
      ? { disabled: true, title: t("offline.action.needs_network") }
      : {},
  };
}
