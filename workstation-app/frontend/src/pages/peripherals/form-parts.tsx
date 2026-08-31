import {
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@godxjp/ui";
import { useTranslation } from "../../providers/app-provider";
import type { PrinterRole } from "../../lib/api";

export const NETWORK_TYPES = ["payment_terminal", "coin_changer"];

export const ALL_ROLES: PrinterRole[] = [
  "kitchen_printer",
  "hold_printer",
  "bar_printer",
  "receipt_printer",
];

export function Field({
  label,
  error,
  children,
}: {
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-xs font-medium text-muted-foreground">{label}</label>
      {children}
      {error && <span className="text-xs text-destructive">{error}</span>}
    </div>
  );
}

/** Role chips shared by the printer create + edit forms. */
export function RolePicker({
  roles,
  onToggle,
  disabled,
}: {
  roles: PrinterRole[];
  onToggle: (r: PrinterRole) => void;
  disabled?: boolean;
}) {
  const { t } = useTranslation();
  return (
    <div className="flex flex-wrap gap-2">
      {ALL_ROLES.map((role) => {
        const active = roles.includes(role);
        return (
          <button
            key={role}
            type="button"
            disabled={disabled}
            onClick={() => onToggle(role)}
            className={
              "rounded-lg border px-3 py-1.5 text-sm transition-colors disabled:opacity-50 " +
              (active
                ? "border-primary bg-primary text-primary-foreground"
                : "border-border bg-muted text-muted-foreground hover:border-primary/50")
            }
          >
            {t(`printer.role.${role}`)}
          </button>
        );
      })}
    </div>
  );
}

/** The connection/address/paper/roles block for a local printer. */
export function PrinterFields({
  connType,
  setConnType,
  address,
  setAddress,
  paperWidth,
  setPaperWidth,
  roles,
  setRoles,
}: {
  connType: string;
  setConnType: (v: string) => void;
  address: string;
  setAddress: (v: string) => void;
  paperWidth: number;
  setPaperWidth: (v: number) => void;
  roles: PrinterRole[];
  setRoles: (updater: (prev: PrinterRole[]) => PrinterRole[]) => void;
}) {
  const { t } = useTranslation();
  return (
    <>
      <Field label={t("peripherals.field.connection")}>
        <Select value={connType} onValueChange={setConnType}>
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="network">Network (TCP)</SelectItem>
            <SelectItem value="usb">USB</SelectItem>
          </SelectContent>
        </Select>
      </Field>
      <Field label={connType === "network" ? t("peripherals.field.address_ip") : t("peripherals.field.address_path")}>
        <Input
          value={address}
          onChange={(e) => setAddress(e.target.value)}
          placeholder={connType === "network" ? "192.168.1.100:9100" : "/dev/usb/lp0"}
        />
      </Field>
      <Field label={t("printer.paper_width")}>
        <Select value={String(paperWidth)} onValueChange={(v) => setPaperWidth(Number(v))}>
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="80">80 mm</SelectItem>
            <SelectItem value="58">58 mm</SelectItem>
          </SelectContent>
        </Select>
      </Field>
      <Field label={t("printer.roles")}>
        <RolePicker
          roles={roles}
          onToggle={(r) => setRoles((prev) => (prev.includes(r) ? prev.filter((x) => x !== r) : [...prev, r]))}
        />
      </Field>
    </>
  );
}
