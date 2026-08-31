"use client";

import {
  Badge,
  Button,
  Card,
  CardContent,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from "@godxjp/ui";
import { Eye, EyeOff, Mail, MessageSquare, Send, Smartphone } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { useTranslation } from "@/providers/app-provider";

import {
  loadProviders,
  maskSecret,
  saveProviders,
  type EmailConfig,
  type ProviderChannel,
  type ProvidersState,
  type PushConfig,
  type SmsConfig,
} from "./provider-store";

interface ProvidersTabProps {
  brandSlug: string;
}

const CHANNEL_META: Record<
  ProviderChannel,
  { icon: typeof Mail; titleKey: string; descKey: string }
> = {
  email: {
    icon: Mail,
    titleKey: "notifications.admin.providers.email.title",
    descKey: "notifications.admin.providers.email.description",
  },
  sms: {
    icon: MessageSquare,
    titleKey: "notifications.admin.providers.sms.title",
    descKey: "notifications.admin.providers.sms.description",
  },
  push: {
    icon: Smartphone,
    titleKey: "notifications.admin.providers.push.title",
    descKey: "notifications.admin.providers.push.description",
  },
};

const EMAIL_OPTIONS: EmailConfig["provider"][] = ["sendgrid", "ses", "smtp"];
const SMS_OPTIONS: SmsConfig["provider"][] = ["twilio"];
const PUSH_OPTIONS: PushConfig["provider"][] = ["fcm"];

export function ProvidersTab({ brandSlug }: ProvidersTabProps) {
  const { t } = useTranslation();
  // Page is "use client" and rendered inside a TabsContent that is only mounted
  // after user interaction — so we can safely seed from localStorage at first
  // render. No effect needed, no SSR/CSR mismatch to worry about.
  const [state, setState] = useState<ProvidersState>(() => loadProviders(brandSlug));
  const [revealed, setRevealed] = useState<Record<string, boolean>>({});
  const [savingChannel, setSavingChannel] = useState<ProviderChannel | null>(null);
  const [testingChannel, setTestingChannel] = useState<ProviderChannel | null>(null);
  const hydrated = true;

  function updateChannel<K extends ProviderChannel>(channel: K, patch: Partial<ProvidersState[K]>) {
    setState((prev) => ({ ...prev, [channel]: { ...prev[channel], ...patch } }));
  }

  async function handleSave(channel: ProviderChannel) {
    setSavingChannel(channel);
    // Simulated latency so the spinner is visible — replace with real apiFetch
    // once backend `/notifications/providers` endpoint exists.
    await new Promise((r) => setTimeout(r, 400));
    saveProviders(brandSlug, state);
    setSavingChannel(null);
    toast.success(t("notifications.admin.providers.toast.saved"));
  }

  async function handleTest(channel: ProviderChannel) {
    const cfg = state[channel];
    if (!cfg.provider) {
      toast.error(t("notifications.admin.providers.toast.missing_provider"));
      return;
    }
    setTestingChannel(channel);
    // Backend test-send endpoint pending — stub a 500ms call and report success
    // unless required credential fields are blank. Replace once available.
    await new Promise((r) => setTimeout(r, 500));
    setTestingChannel(null);

    const missing = checkMissing(channel, state);
    if (missing) {
      toast.error(t("notifications.admin.providers.toast.test_failed_missing", { field: missing }));
      return;
    }
    toast.success(t("notifications.admin.providers.toast.test_sent"));
  }

  function toggleReveal(key: string) {
    setRevealed((prev) => ({ ...prev, [key]: !prev[key] }));
  }

  return (
    <div className="space-y-4">
      {(Object.keys(CHANNEL_META) as ProviderChannel[]).map((channel) => {
        const meta = CHANNEL_META[channel];
        const Icon = meta.icon;
        const cfg = state[channel];
        return (
          <Card key={channel} data-slot={`provider-card-${channel}`}>
            <CardContent className="space-y-4 p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                  <div className="rounded-lg bg-primary/10 p-2.5">
                    <Icon className="size-5 text-primary" />
                  </div>
                  <div>
                    <h3 className="text-sm font-semibold">{t(meta.titleKey)}</h3>
                    <p className="text-xs text-muted-foreground">{t(meta.descKey)}</p>
                  </div>
                </div>
                {cfg.provider ? (
                  <Badge variant="secondary" className="text-[10px] uppercase">
                    {cfg.provider}
                  </Badge>
                ) : (
                  <Badge variant="outline" className="text-[10px]">
                    {t("notifications.admin.providers.not_configured")}
                  </Badge>
                )}
              </div>

              {channel === "email" ? (
                <EmailFields
                  cfg={state.email}
                  hydrated={hydrated}
                  revealed={revealed}
                  toggleReveal={toggleReveal}
                  onChange={(patch) => updateChannel("email", patch)}
                />
              ) : channel === "sms" ? (
                <SmsFields
                  cfg={state.sms}
                  hydrated={hydrated}
                  revealed={revealed}
                  toggleReveal={toggleReveal}
                  onChange={(patch) => updateChannel("sms", patch)}
                />
              ) : (
                <PushFields
                  cfg={state.push}
                  hydrated={hydrated}
                  revealed={revealed}
                  toggleReveal={toggleReveal}
                  onChange={(patch) => updateChannel("push", patch)}
                />
              )}

              <div className="flex items-center justify-end gap-2 border-t pt-3">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => handleTest(channel)}
                  disabled={!cfg.provider || testingChannel !== null || savingChannel !== null}
                >
                  {testingChannel === channel ? (
                    <Spinner className="mr-2 size-3.5" />
                  ) : (
                    <Send className="mr-2 size-3.5" />
                  )}
                  {t("notifications.admin.providers.test_send")}
                </Button>
                <Button
                  size="sm"
                  onClick={() => handleSave(channel)}
                  disabled={savingChannel !== null || testingChannel !== null}
                >
                  {savingChannel === channel ? <Spinner className="mr-2 size-3.5" /> : null}
                  {t("common.save")}
                </Button>
              </div>
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}

interface FieldsBase {
  hydrated: boolean;
  revealed: Record<string, boolean>;
  toggleReveal: (key: string) => void;
}

function EmailFields({
  cfg,
  hydrated,
  revealed,
  toggleReveal,
  onChange,
}: FieldsBase & { cfg: EmailConfig; onChange: (patch: Partial<EmailConfig>) => void }) {
  const { t } = useTranslation();
  return (
    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
      <FieldWrapper label={t("notifications.admin.providers.field.provider")}>
        <Select
          value={cfg.provider || undefined}
          onValueChange={(v) => onChange({ provider: v as EmailConfig["provider"] })}
        >
          <SelectTrigger>
            <SelectValue
              placeholder={t("notifications.admin.providers.placeholder.select_provider")}
            />
          </SelectTrigger>
          <SelectContent>
            {EMAIL_OPTIONS.map((p) => (
              <SelectItem key={p} value={p}>
                {p.toUpperCase()}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </FieldWrapper>

      <FieldWrapper label={t("notifications.admin.providers.field.from_email")}>
        <Input
          type="email"
          placeholder="no-reply@example.com"
          value={cfg.from_email}
          onChange={(e) => onChange({ from_email: e.target.value })}
        />
      </FieldWrapper>

      <FieldWrapper
        label={t("notifications.admin.providers.field.api_key")}
        className="md:col-span-2"
      >
        <SecretField
          fieldKey="email.api_key"
          value={cfg.api_key}
          revealed={revealed}
          toggleReveal={toggleReveal}
          hydrated={hydrated}
          onChange={(v) => onChange({ api_key: v })}
          placeholder="SG.xxxxxxxx..."
        />
      </FieldWrapper>

      {cfg.provider === "smtp" ? (
        <>
          <FieldWrapper label={t("notifications.admin.providers.field.smtp_host")}>
            <Input
              placeholder="smtp.example.com"
              value={cfg.smtp_host ?? ""}
              onChange={(e) => onChange({ smtp_host: e.target.value })}
            />
          </FieldWrapper>
          <FieldWrapper label={t("notifications.admin.providers.field.smtp_port")}>
            <Input
              placeholder="587"
              value={cfg.smtp_port ?? ""}
              onChange={(e) => onChange({ smtp_port: e.target.value })}
            />
          </FieldWrapper>
          <FieldWrapper
            label={t("notifications.admin.providers.field.smtp_user")}
            className="md:col-span-2"
          >
            <Input
              placeholder="username"
              value={cfg.smtp_user ?? ""}
              onChange={(e) => onChange({ smtp_user: e.target.value })}
            />
          </FieldWrapper>
        </>
      ) : null}
    </div>
  );
}

function SmsFields({
  cfg,
  hydrated,
  revealed,
  toggleReveal,
  onChange,
}: FieldsBase & { cfg: SmsConfig; onChange: (patch: Partial<SmsConfig>) => void }) {
  const { t } = useTranslation();
  return (
    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
      <FieldWrapper label={t("notifications.admin.providers.field.provider")}>
        <Select
          value={cfg.provider || undefined}
          onValueChange={(v) => onChange({ provider: v as SmsConfig["provider"] })}
        >
          <SelectTrigger>
            <SelectValue
              placeholder={t("notifications.admin.providers.placeholder.select_provider")}
            />
          </SelectTrigger>
          <SelectContent>
            {SMS_OPTIONS.map((p) => (
              <SelectItem key={p} value={p}>
                {p.toUpperCase()}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </FieldWrapper>

      <FieldWrapper label={t("notifications.admin.providers.field.from_number")}>
        <Input
          placeholder="+15551234567"
          value={cfg.from_number}
          onChange={(e) => onChange({ from_number: e.target.value })}
        />
      </FieldWrapper>

      <FieldWrapper label={t("notifications.admin.providers.field.account_sid")}>
        <Input
          placeholder="ACxxxxxxxxxxxxxxxx"
          value={cfg.account_sid}
          onChange={(e) => onChange({ account_sid: e.target.value })}
        />
      </FieldWrapper>

      <FieldWrapper label={t("notifications.admin.providers.field.auth_token")}>
        <SecretField
          fieldKey="sms.auth_token"
          value={cfg.auth_token}
          revealed={revealed}
          toggleReveal={toggleReveal}
          hydrated={hydrated}
          onChange={(v) => onChange({ auth_token: v })}
          placeholder="••••••••"
        />
      </FieldWrapper>
    </div>
  );
}

function PushFields({
  cfg,
  hydrated,
  revealed,
  toggleReveal,
  onChange,
}: FieldsBase & { cfg: PushConfig; onChange: (patch: Partial<PushConfig>) => void }) {
  const { t } = useTranslation();
  return (
    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
      <FieldWrapper label={t("notifications.admin.providers.field.provider")}>
        <Select
          value={cfg.provider || undefined}
          onValueChange={(v) => onChange({ provider: v as PushConfig["provider"] })}
        >
          <SelectTrigger>
            <SelectValue
              placeholder={t("notifications.admin.providers.placeholder.select_provider")}
            />
          </SelectTrigger>
          <SelectContent>
            {PUSH_OPTIONS.map((p) => (
              <SelectItem key={p} value={p}>
                {p.toUpperCase()}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </FieldWrapper>

      <FieldWrapper label={t("notifications.admin.providers.field.project_id")}>
        <Input
          placeholder="my-firebase-project"
          value={cfg.project_id}
          onChange={(e) => onChange({ project_id: e.target.value })}
        />
      </FieldWrapper>

      <FieldWrapper
        label={t("notifications.admin.providers.field.server_key")}
        className="md:col-span-2"
      >
        <SecretField
          fieldKey="push.server_key"
          value={cfg.server_key}
          revealed={revealed}
          toggleReveal={toggleReveal}
          hydrated={hydrated}
          onChange={(v) => onChange({ server_key: v })}
          placeholder="AAAA..."
        />
      </FieldWrapper>
    </div>
  );
}

function FieldWrapper({
  label,
  className,
  children,
}: {
  label: string;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div className={className}>
      <label className="mb-1 block text-xs text-muted-foreground">{label}</label>
      {children}
    </div>
  );
}

function SecretField({
  fieldKey,
  value,
  hydrated,
  revealed,
  toggleReveal,
  onChange,
  placeholder,
}: FieldsBase & {
  fieldKey: string;
  value: string;
  onChange: (next: string) => void;
  placeholder?: string;
}) {
  const isRevealed = !!revealed[fieldKey];
  // Until the user reveals, render the masked preview (read-only) so the
  // saved key isn't sitting in plain-text in the DOM. Tapping the eye flips
  // to a regular input so they can edit.
  if (!isRevealed && value && hydrated) {
    return (
      <div className="flex items-center gap-2">
        <Input value={maskSecret(value)} readOnly className="font-mono" />
        <Button
          type="button"
          variant="outline"
          size="icon"
          onClick={() => toggleReveal(fieldKey)}
          title="Reveal"
        >
          <Eye className="size-4" />
        </Button>
      </div>
    );
  }
  return (
    <div className="flex items-center gap-2">
      <Input
        type="text"
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
      {value ? (
        <Button
          type="button"
          variant="outline"
          size="icon"
          onClick={() => toggleReveal(fieldKey)}
          title="Hide"
        >
          <EyeOff className="size-4" />
        </Button>
      ) : null}
    </div>
  );
}

function checkMissing(channel: ProviderChannel, state: ProvidersState): string | null {
  if (channel === "email") {
    const { api_key, from_email, provider, smtp_host, smtp_port } = state.email;
    if (!api_key) return "api_key";
    if (!from_email) return "from_email";
    if (provider === "smtp" && !smtp_host) return "smtp_host";
    if (provider === "smtp" && !smtp_port) return "smtp_port";
    return null;
  }
  if (channel === "sms") {
    const { account_sid, auth_token, from_number } = state.sms;
    if (!account_sid) return "account_sid";
    if (!auth_token) return "auth_token";
    if (!from_number) return "from_number";
    return null;
  }
  const { server_key, project_id } = state.push;
  if (!server_key) return "server_key";
  if (!project_id) return "project_id";
  return null;
}
