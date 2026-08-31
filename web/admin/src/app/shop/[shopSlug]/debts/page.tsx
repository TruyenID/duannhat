"use client";

/**
 * Mặt CÔNG NỢ cho quản lý (#1998).
 *
 * Trước trang này, thu ngân đứng ở quầy thấy tiền quán được nợ (dialog tra cứu
 * ở POS), còn người quyết định có đi đòi hay không thì không có chỗ nào xem.
 *
 * Hai phần **hiển thị tách bạch**, không cộng lại — luật #1990:
 *
 *   Nợ trên tài khoản   khách được cấp hạn mức có chủ đích. Một QUYẾT ĐỊNH
 *                       kinh doanh: đã đồng ý cho nợ.
 *   Đơn trả chưa đủ     khách trả thiếu rồi đi. Một SỰ CỐ vận hành: không ai
 *                       đóng đơn, và không sổ nợ nào thấy vì nó nằm trên
 *                       `customer_orders` chứ không trên `order_payments`.
 *
 * Gộp thành một con số "tổng công nợ" nghe gọn hơn và **sai**: hai loại này cần
 * hai hành động khác nhau, và trộn lại thì cái thứ hai biến mất trong cái thứ
 * nhất — đúng cách 3.751đ ở #1990 không ai nhìn thấy suốt nhiều tháng.
 */

import { AlertCircle, Receipt, Wallet } from "lucide-react";
import { useParams } from "next/navigation";
import { useState } from "react";
import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Skeleton,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@godxjp/ui";
import { formatPriceAmount } from "@/lib/currency";
import { useShopOpenAccountDebts, useShopPartPaidDebts } from "@/hooks/api/use-shop-debts";
import { useTranslation } from "@/providers/app-provider";

function EmptyRow({ colSpan, label }: { colSpan: number; label: string }) {
  return (
    <TableRow>
      <TableCell colSpan={colSpan} className="py-10 text-center text-xs text-muted-foreground">
        {label}
      </TableCell>
    </TableRow>
  );
}

export default function ShopDebtsPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t, locale } = useTranslation();

  const onAccount = useShopOpenAccountDebts(shopSlug);
  const partPaid = useShopPartPaidDebts(shopSlug);

  const [expanded, setExpanded] = useState<string | null>(null);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-lg font-semibold text-foreground">{t("shop_debts.title")}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{t("shop_debts.subtitle")}</p>
      </div>

      {/* Vì sao KHÔNG có ô "tổng công nợ" ở đầu trang: xem docblock. */}
      <Alert>
        <AlertCircle className="size-4" aria-hidden />
        <AlertDescription>{t("shop_debts.why_separate")}</AlertDescription>
      </Alert>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="flex items-center gap-2 text-sm">
            <Wallet className="size-4" aria-hidden />
            {t("shop_debts.on_account.title")}
          </CardTitle>
          <CardDescription>{t("shop_debts.on_account.desc")}</CardDescription>
        </CardHeader>
        <CardContent>
          {onAccount.isError ? (
            <Alert variant="destructive">
              <AlertDescription>{t("common.error")}</AlertDescription>
            </Alert>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t("shop_debts.col.customer")}</TableHead>
                  <TableHead className="text-right">{t("shop_debts.col.total")}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {onAccount.isLoading ? (
                  <TableRow>
                    <TableCell colSpan={2}>
                      <Skeleton className="h-5 w-full" />
                    </TableCell>
                  </TableRow>
                ) : (onAccount.data ?? []).length === 0 ? (
                  <EmptyRow colSpan={2} label={t("shop_debts.on_account.empty")} />
                ) : (
                  (onAccount.data ?? []).map((row) => (
                    <TableRow key={row.customer_id}>
                      <TableCell>
                        <span className="text-sm font-medium text-foreground">
                          {row.customer_name ?? t("shop_debts.unnamed")}
                        </span>
                        {row.customer_phone ? (
                          <span className="ml-2 text-xs text-muted-foreground">
                            {row.customer_phone}
                          </span>
                        ) : null}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatPriceAmount(row.open_debt_total, locale)}
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="flex items-center gap-2 text-sm">
            <Receipt className="size-4" aria-hidden />
            {t("shop_debts.part_paid.title")}
          </CardTitle>
          <CardDescription>{t("shop_debts.part_paid.desc")}</CardDescription>
        </CardHeader>
        <CardContent>
          {partPaid.isError ? (
            <Alert variant="destructive">
              <AlertDescription>{t("common.error")}</AlertDescription>
            </Alert>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t("shop_debts.col.customer")}</TableHead>
                  <TableHead className="text-right">{t("shop_debts.col.orders")}</TableHead>
                  <TableHead className="text-right">{t("shop_debts.col.unpaid")}</TableHead>
                  <TableHead className="w-24" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {partPaid.isLoading ? (
                  <TableRow>
                    <TableCell colSpan={4}>
                      <Skeleton className="h-5 w-full" />
                    </TableCell>
                  </TableRow>
                ) : (partPaid.data ?? []).length === 0 ? (
                  <EmptyRow colSpan={4} label={t("shop_debts.part_paid.empty")} />
                ) : (
                  (partPaid.data ?? []).flatMap((row) => {
                    const open = expanded === row.customer_id;

                    return [
                      <TableRow key={row.customer_id}>
                        <TableCell>
                          <span className="text-sm font-medium text-foreground">
                            {row.customer_name ?? t("shop_debts.unnamed")}
                          </span>
                          {row.customer_phone ? (
                            <span className="ml-2 text-xs text-muted-foreground">
                              {row.customer_phone}
                            </span>
                          ) : null}
                        </TableCell>
                        <TableCell className="text-right">
                          <Badge variant="outline" className="h-5 text-[10px]">
                            {row.order_count}
                          </Badge>
                        </TableCell>
                        <TableCell className="text-right tabular-nums font-medium">
                          {formatPriceAmount(row.total_unpaid, locale)}
                        </TableCell>
                        <TableCell className="text-right">
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setExpanded(open ? null : row.customer_id)}
                          >
                            {open ? t("shop_debts.hide") : t("shop_debts.detail")}
                          </Button>
                        </TableCell>
                      </TableRow>,
                      ...(open
                        ? row.orders.map((order) => (
                            <TableRow key={order.order_id} className="bg-muted/40">
                              <TableCell className="pl-8 text-xs text-muted-foreground">
                                {order.order_code ?? order.order_id}
                              </TableCell>
                              <TableCell />
                              <TableCell className="text-right text-xs tabular-nums">
                                {/* Ba con số: đã trả / tổng / còn thiếu. Chỉ hiện
                                    "còn thiếu" thì người đọc không biết khách đã
                                    trả bao nhiêu, mà đó là thứ quyết định cách đi
                                    đòi. */}
                                {formatPriceAmount(order.paid_amount, locale)}
                                {" / "}
                                {formatPriceAmount(order.total_amount, locale)}
                                <span className="ml-2 font-medium text-foreground">
                                  {formatPriceAmount(order.unpaid_amount, locale)}
                                </span>
                              </TableCell>
                              <TableCell />
                            </TableRow>
                          ))
                        : []),
                    ];
                  })
                )}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
