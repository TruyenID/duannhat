import { FormField } from "@godxjp/ui/admin";
import {
  CheckboxGroup,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@godxjp/ui/data-entry";
import { useTranslation } from "../../providers/app-provider";
import type { PrinterRole } from "../../lib/api";

export const NETWORK_TYPES = ["payment_terminal", "coin_changer"];

export const ALL_ROLES: PrinterRole[] = [
  "kitchen_printer",
  "hall_printer",
  "bar_printer",
  "receipt_printer",
];

/** ESC/POS printers listen on 9100 (RAW / JetDirect) unless told otherwise. */
export const DEFAULT_PRINTER_PORT = 9100;

/**
 * The wire format is a single `host:port` string, because that is what the Go
 * side dials. Splitting it in the UI is deliberate: one combined box made the
 * port look like part of the IP, and a typo in it fails as an unreachable
 * printer rather than as a validation error.
 *
 * IPv6 literals are bracketed (`[::1]:9100`), so split on the LAST colon.
 */
export function splitAddress(address: string): { host: string; port: string } {
  const idx = address.lastIndexOf(":");
  if (idx === -1 || address.endsWith("]")) {
    return { host: address, port: "" };
  }
  return { host: address.slice(0, idx), port: address.slice(idx + 1) };
}

export function joinAddress(host: string, port: string): string {
  const h = host.trim();
  const p = port.trim();
  if (!h) return "";
  return p ? `${h}:${p}` : h;
}

/**
 * Roles are checkboxes, not toggle chips: a printer can hold several at once,
 * and the chips gave no visual difference between selected and unselected, so
 * the current assignment was unreadable.
 */
export function RolePicker({
  roles,
  onChange,
  disabled,
}: {
  roles: PrinterRole[];
  onChange: (next: PrinterRole[]) => void;
  disabled?: boolean;
}) {
  const { t } = useTranslation();
  return (
    <CheckboxGroup
      orientation="horizontal"
      value={roles}
      onValueChange={(next) => onChange(next as PrinterRole[])}
      disabled={disabled}
      options={ALL_ROLES.map((role) => ({
        value: role,
        label: t(`printer.role.${role}`),
      }))}
    />
  );
}

/** The connection/address/paper/roles block for a local printer. */
export function PrinterFields({
  connType,
  setConnType,
  host,
  setHost,
  port,
  setPort,
  paperWidth,
  setPaperWidth,
  roles,
  setRoles,
  disabled,
}: {
  connType: string;
  setConnType: (v: string) => void;
  host: string;
  setHost: (v: string) => void;
  port: string;
  setPort: (v: string) => void;
  paperWidth: number;
  setPaperWidth: (v: number) => void;
  roles: PrinterRole[];
  setRoles: (next: PrinterRole[]) => void;
  disabled?: boolean;
}) {
  const { t } = useTranslation();
  const isNetwork = connType === "network";

  return (
    <>
      <FormField id="printer-connection" label={t("peripherals.field.connection")}>
        <Select value={connType} onValueChange={setConnType} disabled={disabled}>
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="network">Network (TCP)</SelectItem>
            <SelectItem value="usb">USB</SelectItem>
          </SelectContent>
        </Select>
      </FormField>

      <FormField
        id="printer-address"
        label={
          isNetwork
            ? t("peripherals.field.address_ip")
            : t("peripherals.field.address_path")
        }
      >
        <Input
          value={host}
          onChange={(e) => setHost(e.target.value)}
          disabled={disabled}
          placeholder={isNetwork ? "192.168.1.100" : "/dev/usb/lp0"}
        />
      </FormField>

      {/* USB devices are a path with no port — asking for one would be nonsense. */}
      {isNetwork && (
        <FormField
          id="printer-port"
          label={t("peripherals.field.port")}
          helper={t("peripherals.field.port_help", { port: DEFAULT_PRINTER_PORT })}
        >
          <Input
            type="number"
            value={port}
            onChange={(e) => setPort(e.target.value)}
            disabled={disabled}
            placeholder={String(DEFAULT_PRINTER_PORT)}
          />
        </FormField>
      )}

      <FormField id="printer-paper" label={t("printer.paper_width")}>
        <Select
          value={String(paperWidth)}
          onValueChange={(v) => setPaperWidth(Number(v))}
          disabled={disabled}
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="80">80 mm</SelectItem>
            <SelectItem value="58">58 mm</SelectItem>
          </SelectContent>
        </Select>
      </FormField>

      <FormField id="printer-roles" label={t("printer.roles")} helper={t("printer.roles_help")}>
        <RolePicker roles={roles} onChange={setRoles} disabled={disabled} />
      </FormField>
    </>
  );
}
