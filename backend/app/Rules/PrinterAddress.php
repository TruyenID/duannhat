<?php

namespace App\Rules;

use App\Omnify\Enums\PrinterConnectionTypeEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a printer address the same way the workstation does before it
 * opens a socket.
 *
 * This is a faithful port of `ValidateAddress()` in
 * workstation/internal/printer/printer.go:194. Keep the two in sync — the
 * workstation re-validates on its side, so a value accepted here but rejected
 * there just yields a printer that silently never connects.
 *
 * SECURITY — why the host allow-list exists:
 * A printer address is a host the workstation will connect to. Left open, an
 * operator (or anyone who compromised an admin account) could point a
 * "printer" at an arbitrary internet host and turn every shop workstation into
 * an SSRF probe against the LAN it sits on. So `network` addresses are limited
 * to RFC1918 / loopback / link-local IPs, plus `.local` mDNS names which are
 * LAN-scoped by definition. Any other hostname is refused because DNS could
 * resolve it anywhere.
 *
 * USB paths are restricted to known printer device-node prefixes and reject
 * `..` so `/dev/../etc/passwd` and friends can't be smuggled through. Cloud
 * can only check the shape — whether the node exists is the workstation's
 * business, since the path is specific to that one PC.
 */
class PrinterAddress implements ValidationRule
{
    /** Unix device-node prefixes: Linux line printers + macOS USB/serial. */
    private const USB_PREFIXES = [
        '/dev/usb/lp',
        '/dev/usblp',
        '/dev/lp',
        '/dev/cu.',
        '/dev/tty.',
    ];

    /** Localised messages, self-contained (mirrors PhoneFormatForBranch). */
    private const MESSAGES = [
        'host_port' => [
            'en' => 'The printer address must be in host:port form (e.g. 192.168.1.100:9100).',
            'ja' => 'プリンターアドレスは host:port 形式で入力してください（例：192.168.1.100:9100）。',
            'vi' => 'Địa chỉ máy in phải có dạng host:port (ví dụ 192.168.1.100:9100).',
        ],
        'port' => [
            'en' => 'The printer port must be a number between 1 and 65535.',
            'ja' => 'プリンターのポートは1〜65535の数値で指定してください。',
            'vi' => 'Cổng máy in phải là số từ 1 đến 65535.',
        ],
        'not_lan' => [
            'en' => 'The printer must be on a private LAN address or a .local mDNS name.',
            'ja' => 'プリンターはプライベートLANアドレスまたは.local名である必要があります。',
            'vi' => 'Máy in phải nằm ở địa chỉ LAN nội bộ hoặc tên .local.',
        ],
        'usb' => [
            'en' => 'The USB printer address must be a printer device node (e.g. /dev/usb/lp0, COM3).',
            'ja' => 'USBプリンターのアドレスはデバイスノードを指定してください（例：/dev/usb/lp0、COM3）。',
            'vi' => 'Địa chỉ máy in USB phải là đường dẫn thiết bị (ví dụ /dev/usb/lp0, COM3).',
        ],
    ];

    public function __construct(
        private readonly ?string $connectionType,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            // Presence is governed by the `required_if` / `nullable` rule on
            // the form request, not here.
            return;
        }

        $value = trim($value);

        if ($this->connectionType === PrinterConnectionTypeEnum::Usb->value) {
            $this->validateUsb($value, $fail);

            return;
        }

        $this->validateNetwork($value, $fail);
    }

    /**
     * `host:port` where host is a private/LAN IP or a `.local` mDNS name.
     */
    private function validateNetwork(string $value, Closure $fail): void
    {
        // rposition of ':' so a bracketed IPv6 literal still splits correctly.
        $pos = strrpos($value, ':');
        if ($pos === false) {
            $fail($this->message('host_port'));

            return;
        }

        $host = substr($value, 0, $pos);
        $port = substr($value, $pos + 1);

        // Bracketed IPv6: [fd00::1]:9100
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($host === '') {
            $fail($this->message('host_port'));

            return;
        }

        if ($port === '' || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $fail($this->message('port'));

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isPrivatePrinterIp($host)) {
                $fail($this->message('not_lan'));
            }

            return;
        }

        // Hostname form — only mDNS .local names are LAN-scoped; anything else
        // could resolve to an arbitrary internet host (SSRF).
        if (! str_ends_with(mb_strtolower($host), '.local')) {
            $fail($this->message('not_lan'));
        }
    }

    /**
     * Device node path. Machine-specific, so Cloud only sanity-checks shape.
     */
    private function validateUsb(string $value, Closure $fail): void
    {
        if (str_contains($value, '..')) {
            $fail($this->message('usb'));

            return;
        }

        foreach (self::USB_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return;
            }
        }

        $upper = mb_strtoupper($value);
        if (str_starts_with($upper, 'COM')
            || str_starts_with($upper, 'LPT')
            || str_starts_with($value, '\\\\.\\')
        ) {
            return;
        }

        $fail($this->message('usb'));
    }

    /**
     * Mirrors Go's `isPrivatePrinterIP`: loopback, RFC1918 private, or
     * link-local unicast.
     *
     * PHP expresses this inversely — an address that fails BOTH the
     * "no private range" and "no reserved range" filters is exactly one we
     * want to allow.
     */
    private function isPrivatePrinterIp(string $host): bool
    {
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Resolve the localised message, degrading to English when there is no
     * booted application (pure unit tests instantiate this rule directly).
     */
    private function message(string $key): string
    {
        $messages = self::MESSAGES[$key];

        $locale = null;
        $fallback = 'en';
        if (function_exists('app') && app()->bound('translator')) {
            $locale = app()->getLocale();
            $fallback = config('app.fallback_locale', 'en');
        }

        return $messages[$locale]
            ?? $messages[$fallback]
            ?? $messages['en'];
    }
}
