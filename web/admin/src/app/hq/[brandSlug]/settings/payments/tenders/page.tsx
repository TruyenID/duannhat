"use client";

import { useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Alert, AlertDescription } from "@godxjp/ui";
import { Badge } from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Label } from "@godxjp/ui";
import { toast } from "sonner";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { SettingsTabsNav } from "../../components/settings-tabs-nav";
import { PaymentsSettingsShell } from "../components/payments-settings-nav";
import { useTranslation } from "@/providers/app-provider";
import {
  hqTenderTypeService,
  type HqTenderType,
} from "@/services/hq-tender-type-service";

/**
 * #1881 — từ vựng tender ở HQ.
 *
 * ## Màn hình này phải NÓI TRƯỚC, không phải chặn sau
 *
 * `tender_key` được chụp lên từng chứng từ tiền, nên ba thao tác trông như quản
 * trị bình thường thật ra là thao tác trên dữ liệu bất biến. Backend trả sẵn
 * `key_editable` / `group_editable` / `deletable` cùng `payment_count`, và màn
 * hình dùng chúng để **vô hiệu hoá ô nhập kèm lý do** thay vì cho gõ rồi trả
 * 409. Một ô bị từ chối sau khi bấm Lưu là cách tệ nhất để truyền đạt ràng buộc.
 *
 * Ba cờ đó do BACKEND tính, không suy ở đây: chúng phụ thuộc số payment đang
 * tham chiếu — dữ liệu chỉ backend có. Suy ở client sẽ hoặc chặn oan, hoặc bỏ
 * lọt rồi vẫn ăn 409.
 *
 * ## Vẫn xử lý 409
 *
 * Cờ có thể cũ: một payment vừa được tạo ở quán giữa lúc trang đang mở. Nên UI
 * rẽ theo **mã lỗi**, không theo câu chữ, và refetch sau mỗi lần đụng phải.
 */
export default function HqTenderVocabularyPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();
  const qc = useQueryClient();

  const [includeInactive, setIncludeInactive] = useState(false);
  const [draft, setDraft] = useState({ tender_key: "", name: "", category: "" });

  const key = ["hq-tender-types", brandSlug, includeInactive] as const;

  const { data, isLoading, refetch } = useQuery({
    queryKey: key,
    queryFn: () => hqTenderTypeService.list(brandSlug, includeInactive),
  });

  const rows = data?.data ?? [];

  const onError = (e: unknown) => {
    const body = (e as { body?: { error_code?: string; message?: string } })?.body;
    // Rẽ theo MÃ, không theo câu chữ: câu chữ sẽ đổi khi dịch.
    toast.error(body?.message ?? t("hq.tenders.error.generic"));
    void qc.invalidateQueries({ queryKey: key });
  };

  const create = useMutation({
    mutationFn: () => hqTenderTypeService.create(brandSlug, draft),
    onSuccess: () => {
      setDraft({ tender_key: "", name: "", category: "" });
      void qc.invalidateQueries({ queryKey: key });
    },
    onError,
  });

  const toggleActive = useMutation({
    mutationFn: (row: HqTenderType) =>
      hqTenderTypeService.update(brandSlug, row.id, { is_active: !row.is_active }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: key }),
    onError,
  });

  const remove = useMutation({
    mutationFn: (row: HqTenderType) => hqTenderTypeService.remove(brandSlug, row.id),
    onSuccess: () => void qc.invalidateQueries({ queryKey: key }),
    onError,
  });

  return (
    <>
      <PageHeader
        title={t("hq.tenders.title")}
        description={t("hq.tenders.description")}
        onRefresh={refetch}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={brandSlug} />
        <PaymentsSettingsShell brandSlug={brandSlug}>
          <Alert className="mb-4">
            {/* Ràng buộc phải nói ở đầu trang, không phải trong một toast đỏ
                sau khi người dùng đã gõ xong. */}
            <AlertDescription>{t("hq.tenders.immutability_hint")}</AlertDescription>
          </Alert>

          <div className="mb-4 flex flex-wrap items-end gap-2">
            <div>
              <Label htmlFor="tender_key">{t("hq.tenders.col.key")}</Label>
              <Input
                id="tender_key"
                value={draft.tender_key}
                placeholder="cash_jpy"
                onChange={(e) => setDraft({ ...draft, tender_key: e.target.value })}
              />
            </div>
            <div>
              <Label htmlFor="tender_name">{t("hq.tenders.col.name")}</Label>
              <Input
                id="tender_name"
                value={draft.name}
                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
              />
            </div>
            <div>
              <Label htmlFor="tender_category">{t("hq.tenders.col.category")}</Label>
              <Input
                id="tender_category"
                value={draft.category}
                placeholder="cash"
                onChange={(e) => setDraft({ ...draft, category: e.target.value })}
              />
            </div>
            <Button
              onClick={() => create.mutate()}
              disabled={
                create.isPending ||
                draft.tender_key === "" ||
                draft.name === "" ||
                draft.category === ""
              }
            >
              {t("hq.tenders.add")}
            </Button>
            <Button variant="outline" onClick={() => setIncludeInactive((v) => !v)}>
              {includeInactive ? t("hq.tenders.hide_inactive") : t("hq.tenders.show_inactive")}
            </Button>
          </div>

          {isLoading ? (
            <DataTableSkeleton columns={5} />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left">
                    <th className="p-2">{t("hq.tenders.col.key")}</th>
                    <th className="p-2">{t("hq.tenders.col.name")}</th>
                    <th className="p-2">{t("hq.tenders.col.group")}</th>
                    <th className="p-2">{t("hq.tenders.col.usage")}</th>
                    <th className="p-2" />
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id} className="border-b">
                      <td className="p-2 font-mono text-xs">
                        {row.tender_key}
                        {/* Khoá KHÔNG BAO GIỜ sửa được — nói ngay cạnh nó. */}
                        <Badge variant="outline" className="ml-2">
                          {t("hq.tenders.badge.immutable")}
                        </Badge>
                      </td>
                      <td className="p-2">{row.name}</td>
                      <td className="p-2">{row.parent_tender_key ?? "—"}</td>
                      <td className="p-2">
                        {row.payment_count ?? "—"}
                        {row.group_editable === false && (
                          <span className="ml-2 text-xs text-muted-foreground">
                            {t("hq.tenders.group_locked")}
                          </span>
                        )}
                      </td>
                      <td className="p-2 text-right">
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={toggleActive.isPending}
                          onClick={() => toggleActive.mutate(row)}
                        >
                          {row.is_active ? t("hq.tenders.deactivate") : t("hq.tenders.activate")}
                        </Button>
                        {/* Nút xoá chỉ hiện khi THẬT SỰ xoá được. Hiện rồi báo
                            lỗi là mời người dùng thử một việc đã biết là hỏng. */}
                        {row.deletable === true && (
                          <Button
                            size="sm"
                            variant="destructive"
                            className="ml-2"
                            disabled={remove.isPending}
                            onClick={() => remove.mutate(row)}
                          >
                            {t("hq.tenders.delete")}
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </PaymentsSettingsShell>
      </PageContent>
    </>
  );
}
