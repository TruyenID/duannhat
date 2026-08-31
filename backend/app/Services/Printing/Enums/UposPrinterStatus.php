<?php

namespace App\Services\Printing\Enums;

/**
 * plan-052 T1.3 — printer status, normalised to the UnifiedPOS (UPOS/OMG,
 * ex-NRF-ARTS) POSPrinter vocabulary (STANDARDS §1).
 *
 * Every transport speaks its own dialect: the workstation has `online /
 * offline / error / printing`, StarPRNT ASB has a status byte, CloudPRNT
 * posts JSON, ePOS returns an XML attribute. They all collapse HERE, so a
 * dashboard row means the same thing regardless of which machine produced it.
 */
enum UposPrinterStatus: string
{
    case Ok = 'ok';
    case CoverOpen = 'cover_open';
    case PaperEnd = 'paper_end';
    case PaperNearEnd = 'paper_near_end';
    case Offline = 'offline';
    case Error = 'error';

    /**
     * Map the workstation's legacy `DeviceStatus` (internal/printer) onto UPOS.
     * `printing` is a busy state, not a fault — it maps to Ok.
     */
    public static function fromWorkstationStatus(?string $status): self
    {
        return match ($status) {
            'online', 'printing' => self::Ok,
            'offline' => self::Offline,
            'error' => self::Error,
            default => self::Offline,
        };
    }

    /**
     * A machine in one of these states must not be handed a money document
     * (P-34 preflight): printing an invoice into an open cover produces a
     * half-slip nobody can account for.
     */
    public function blocksMoneyDocument(): bool
    {
        return match ($this) {
            self::CoverOpen, self::PaperEnd, self::Offline, self::Error => true,
            self::Ok, self::PaperNearEnd => false,
        };
    }
}
