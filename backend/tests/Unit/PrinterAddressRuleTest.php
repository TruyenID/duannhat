<?php

use App\Rules\PrinterAddress;

/**
 * Mirrors workstation/internal/printer/validate_address_test.go.
 *
 * The two validators must agree: Cloud accepting something the workstation
 * rejects produces a printer that silently never connects, and the reverse
 * lets an operator write a config the shop can't use.
 */
function validatePrinterAddress(?string $connectionType, string $value): array
{
    $errors = [];
    (new PrinterAddress($connectionType))->validate(
        'address',
        $value,
        function (string $message) use (&$errors): void {
            $errors[] = $message;
        },
    );

    return $errors;
}

// =============================================================================
// network — accepted
// =============================================================================

it('accepts private LAN addresses', function (string $address) {
    expect(validatePrinterAddress('network', $address))->toBeEmpty();
})->with([
    'rfc1918 192.168' => '192.168.1.100:9100',
    'rfc1918 10.x' => '10.0.0.5:9100',
    'rfc1918 172.16' => '172.16.0.1:9100',
    'loopback' => '127.0.0.1:9100',
    'link-local' => '169.254.1.1:9100',
    'mdns .local' => 'kitchen-printer.local:9100',
    'mdns uppercase' => 'Kitchen-Printer.LOCAL:9100',
    'non-default port' => '192.168.1.100:631',
]);

// =============================================================================
// network — rejected (SSRF guard)
// =============================================================================

it('rejects addresses that are not LAN-scoped', function (string $address) {
    expect(validatePrinterAddress('network', $address))->not->toBeEmpty();
})->with([
    // A public IP would turn every shop workstation into an SSRF probe.
    'public ip' => '8.8.8.8:9100',
    'public ip 2' => '1.1.1.1:9100',
    // DNS could resolve these anywhere.
    'public hostname' => 'evil.example.com:9100',
    'bare hostname' => 'printer:9100',
    'missing port' => '192.168.1.100',
    'empty host' => ':9100',
    'port zero' => '192.168.1.100:0',
    'port too large' => '192.168.1.100:70000',
    'non numeric port' => '192.168.1.100:abc',
]);

// =============================================================================
// usb — path traversal guard
// =============================================================================

it('accepts known printer device nodes', function (string $address) {
    expect(validatePrinterAddress('usb', $address))->toBeEmpty();
})->with([
    'linux lp' => '/dev/usb/lp0',
    'linux usblp' => '/dev/usblp0',
    'linux plain lp' => '/dev/lp0',
    'macos cu' => '/dev/cu.usbserial',
    'windows com' => 'COM3',
    'windows lpt' => 'LPT1',
]);

it('rejects usb paths that are not printer device nodes', function (string $address) {
    expect(validatePrinterAddress('usb', $address))->not->toBeEmpty();
})->with([
    'etc passwd' => '/etc/passwd',
    'traversal' => '/dev/../etc/passwd',
    'ssh key' => '/Users/victim/.ssh/id_rsa',
    'relative traversal' => '../../etc/shadow',
]);

// =============================================================================
// presence is governed elsewhere
// =============================================================================

it('passes an empty value so the required rule owns presence', function () {
    expect(validatePrinterAddress('network', ''))->toBeEmpty();
});
