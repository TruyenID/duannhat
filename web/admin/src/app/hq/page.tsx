"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { LoadingShell } from "@/components/layout/loading-shell";
import { ErrorShell } from "@/components/layout/error-shell";
import { readLastBrandSlug, rememberLastBrandSlug } from "@/lib/workspace-preference";
import { resolveHqBrandSlug } from "@/services/hq-entry-service";

export default function HqEntryPage() {
  const router = useRouter();
  const { data, error, refetch } = useQuery({
    queryKey: ["hq", "entry"],
    queryFn: () => resolveHqBrandSlug(readLastBrandSlug()),
    retry: false,
    networkMode: "always",
  });

  useEffect(() => {
    if (data === undefined) return;

    if (data) {
      rememberLastBrandSlug(data);
      router.replace(`/hq/${data}/dashboard`);
      return;
    }

    router.replace("/select-context");
  }, [data, router]);

  if (error) {
    return <ErrorShell error={error} onRetry={refetch} />;
  }

  return <LoadingShell />;
}
