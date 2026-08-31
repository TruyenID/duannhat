"use client";

/**
 * #2350 — Checklist dữ liệu gốc của brand và các chi nhánh.
 *
 * Phơi đúng phép đo của `php artisan provisioning:reconcile --dry-run`, qua
 * `GET /hq/{brandSlug}/readiness` (#2344). Không có nút "sửa" ở đây, và đó là
 * quyết định chứ không phải thiếu sót: endpoint là read-only, dựng dữ liệu gốc
 * là hành động có chủ ý và có log. Màn hình chỉ chỉ ra cái thiếu + nêu đúng
 * lệnh cần chạy.
 */

import { useQueryClient } from "@tanstack/react-query";
import { RefreshCw } from "lucide-react";

import { useReadiness, readinessKeys } from "@/hooks/api/use-readiness";
import type { ReadinessCheck, ReadinessState } from "@/services/readiness-service";
import { useTranslation } from "@/providers/app-provider";

import {
  Badge,
  Button,
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
  Spinner,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@godxjp/ui";

/**
 * `skipped` mang màu RIÊNG (vàng), không dùng lại màu của `satisfied`.
 *
 * Nó nghĩa là *chưa kiểm được*, không phải *đã đúng*. Gộp màu là cách nhanh
 * nhất để một brand chưa đồng bộ Platform trông như đã sẵn sàng.
 */
const STATE_VARIANT: Record<ReadinessState, "default" | "destructive" | "secondary"> = {
  satisfied: "default",
  missing: "destructive",
  skipped: "secondary",
};

export interface BrandReadinessTabProps {
  brandSlug: string;
}

export function BrandReadinessTab({ brandSlug }: BrandReadinessTabProps) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  const { data, isPending, isError, error, isFetching } = useReadiness(brandSlug);

  const refresh = () => qc.invalidateQueries({ queryKey: readinessKeys.all(brandSlug) });

  if (isPending) {
    return (
      <div className="flex justify-center py-10">
        <Spinner />
      </div>
    );
  }

  if (isError) {
    return (
      <Card>
        <CardContent className="py-6 text-sm text-destructive">
          {error instanceof Error ? error.message : t("hq.brand.settings.readiness.load_failed")}
        </CardContent>
      </Card>
    );
  }

  // Mục đã đạt không cần chiếm chỗ trên màn hình, nhưng vẫn phải ĐẾM được —
  // "12/14 đạt" là thứ nói cho người đọc biết bảng dưới đã đầy đủ hay chưa.
  const problems = data.checks.filter((c) => c.state !== "satisfied");
  const satisfiedCount = data.checks.length - problems.length;

  return (
    <Card data-slot="brand-readiness-tab">
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div className="space-y-1.5">
          <CardTitle className="flex items-center gap-2">
            {t("hq.brand.settings.readiness.section_title")}
            <Badge variant={data.ready ? "default" : "destructive"}>
              {data.ready
                ? t("hq.brand.settings.readiness.status.ready")
                : t("hq.brand.settings.readiness.status.not_ready")}
            </Badge>
          </CardTitle>
          <CardDescription>{t("hq.brand.settings.readiness.section_description")}</CardDescription>
        </div>
        <Button variant="outline" size="sm" onClick={refresh} disabled={isFetching}>
          <RefreshCw className={isFetching ? "animate-spin" : undefined} />
          {t("hq.brand.settings.readiness.refresh")}
        </Button>
      </CardHeader>

      <CardContent className="space-y-4">
        <p className="text-sm text-muted-foreground">
          {t("hq.brand.settings.readiness.satisfied_count")
            .replace("{done}", String(satisfiedCount))
            .replace("{total}", String(data.checks.length))}
        </p>

        {problems.length === 0 ? (
          <p className="text-sm">{t("hq.brand.settings.readiness.all_good")}</p>
        ) : (
          <>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t("hq.brand.settings.readiness.column.subject")}</TableHead>
                    <TableHead>{t("hq.brand.settings.readiness.column.item")}</TableHead>
                    <TableHead>{t("hq.brand.settings.readiness.column.state")}</TableHead>
                    <TableHead>{t("hq.brand.settings.readiness.column.detail")}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {problems.map((check: ReadinessCheck) => (
                    <TableRow key={`${check.subject}:${check.key}`}>
                      <TableCell className="font-mono text-xs">{check.subject}</TableCell>
                      <TableCell className="font-mono text-xs">{check.key}</TableCell>
                      <TableCell>
                        <Badge variant={STATE_VARIANT[check.state]}>
                          {t(`hq.brand.settings.readiness.state.${check.state}`)}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-sm">{check.detail}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            <div className="rounded-md border bg-muted/40 p-3 text-sm">
              <p className="mb-1 font-medium">{t("hq.brand.settings.readiness.how_to_fix")}</p>
              <code className="block font-mono text-xs">
                php artisan provisioning:reconcile --brand={brandSlug} --dry-run
              </code>
              <code className="block font-mono text-xs">
                php artisan provisioning:reconcile --brand={brandSlug}
              </code>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}
