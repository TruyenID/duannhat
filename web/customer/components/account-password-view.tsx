"use client";

import { useState, useEffect } from "react";
import { useRouter } from "@/i18n/routing";
import { useParams } from "next/navigation";
import { accountHref } from "@/lib/shop-routes";
import { useTranslations } from 'next-intl';
import { useAuth } from "@/context/auth-context";
import { useGlobalLoading } from "@/context/loading-context";
import { apiFetch, ApiError } from "@/lib/api";
import Header from "@/components/Header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import { KeyRound, Eye, EyeOff } from "lucide-react";

export default function AccountPasswordView() {
  // Cửa hàng đang xem, từ segment `[shop]` của URL (#1505).
  const { shop } = useParams<{ shop?: string }>();
  const { isLoggedIn, isLoading } = useAuth();
  const { showLoading } = useGlobalLoading();
  const router = useRouter();
  const t = useTranslations('account');

  const [currentPassword, setCurrentPassword] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [logoutOtherDevices, setLogoutOtherDevices] = useState(true);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const [showCurrent, setShowCurrent] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);

  // Auth guard
  useEffect(() => {
    if (!isLoading && !isLoggedIn) {
      router.replace("/login?redirect=/account/password");
    }
  }, [isLoading, isLoggedIn, router]);

  if (isLoading) {
    return (
      <>
        <Header showBack hideSwitcher showLogo />
        <div className="flex flex-1 items-center justify-center py-20">
          <span className="h-5 w-5 animate-spin rounded-full border-2 border-primary border-t-transparent" />
        </div>
      </>
    );
  }

  if (!isLoggedIn) return null;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setErrors({});
    setSaving(true);
    try {
      await apiFetch("/api/v1/customer/auth/password", {
        method: "POST",
        body: JSON.stringify({
          current_password: currentPassword,
          password,
          password_confirmation: passwordConfirmation,
          logout_other_devices: logoutOtherDevices,
        }),
      });
      toast.success(t('passwordChanged'));
      showLoading();
      router.push(accountHref(shop));
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setErrors((err.body.errors as Record<string, string[]>) || {});
      } else {
        toast.error(t('errorOccurred'));
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <Header showBack hideSwitcher showLogo />
      <div className="flex flex-1 flex-col items-center bg-muted/30 px-4 py-8">
        <div className="w-full max-w-sm">
          <div className="mb-6">
            <h1 className="text-xl font-bold">{t('changePassword')}</h1>
            <p className="text-sm text-muted-foreground">
              {t('changePasswordDesc')}
            </p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-1.5">
              <Label
                htmlFor="current_password"
                className="text-sm font-semibold"
              >
                {t('currentPassword')}
              </Label>
              <div className="relative">
                <Input
                  id="current_password"
                  type={showCurrent ? "text" : "password"}
                  placeholder="********"
                  autoComplete="current-password"
                  value={currentPassword}
                  onChange={(e) => setCurrentPassword(e.target.value)}
                  className="h-11 rounded-xl pr-10"
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowCurrent((v) => !v)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                >
                  {showCurrent ? (
                    <EyeOff className="h-4 w-4" />
                  ) : (
                    <Eye className="h-4 w-4" />
                  )}
                </button>
              </div>
              {errors.current_password && (
                <p className="text-xs text-destructive">
                  {errors.current_password[0]}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="password" className="text-sm font-semibold">
                {t('newPassword')}
              </Label>
              <div className="relative">
                <Input
                  id="password"
                  type={showNew ? "text" : "password"}
                  placeholder="********"
                  autoComplete="new-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="h-11 rounded-xl pr-10"
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowNew((v) => !v)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                >
                  {showNew ? (
                    <EyeOff className="h-4 w-4" />
                  ) : (
                    <Eye className="h-4 w-4" />
                  )}
                </button>
              </div>
              {errors.password && (
                <p className="text-xs text-destructive">
                  {errors.password[0]}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label
                htmlFor="password_confirmation"
                className="text-sm font-semibold"
              >
                {t('confirmNewPassword')}
              </Label>
              <div className="relative">
                <Input
                  id="password_confirmation"
                  type={showConfirm ? "text" : "password"}
                  placeholder="********"
                  autoComplete="new-password"
                  value={passwordConfirmation}
                  onChange={(e) => setPasswordConfirmation(e.target.value)}
                  className="h-11 rounded-xl pr-10"
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowConfirm((v) => !v)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                >
                  {showConfirm ? (
                    <EyeOff className="h-4 w-4" />
                  ) : (
                    <Eye className="h-4 w-4" />
                  )}
                </button>
              </div>
              {errors.password_confirmation && (
                <p className="text-xs text-destructive">
                  {errors.password_confirmation[0]}
                </p>
              )}
            </div>

            <label className="flex items-center gap-2 cursor-pointer">
              <input
                type="checkbox"
                checked={logoutOtherDevices}
                onChange={(e) => setLogoutOtherDevices(e.target.checked)}
                className="h-4 w-4 rounded border-input accent-primary"
              />
              <span className="text-sm text-muted-foreground">
                {t('logoutOtherDevices')}
              </span>
            </label>

            <Button
              type="submit"
              className="h-11 w-full gap-2 rounded-xl"
              disabled={saving}
            >
              {saving ? (
                <span className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
              ) : (
                <KeyRound className="h-4 w-4" />
              )}
              {saving ? t('processing') : t('changePasswordBtn')}
            </Button>
          </form>
        </div>
      </div>
    </>
  );
}
