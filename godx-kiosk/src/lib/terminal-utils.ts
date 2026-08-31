// src/lib/terminal-utils.ts
import type { VescaErrorEvent } from '../types/terminal';

type TranslateFunc = (key: string) => string;

const STATUS_MAP: Record<string, string> = {
  S507: 'terminal.status.present_card',
  S508: 'terminal.status.processing',
  S510: 'terminal.status.pin_entry',
};

export function getTerminalStatusText(
  responseCode: string,
  t: TranslateFunc,
): string {
  const key = STATUS_MAP[responseCode];
  return key ? t(key) : t('terminal.status.default');
}

export function getTerminalErrorMessage(
  error: VescaErrorEvent,
  t: TranslateFunc,
): string {
  const { ErrorCode, Message } = error.ErrorEvent;

  if (ErrorCode === 990) {
    switch (Message) {
      case 'ConnectionError':
        return t('terminal.error.connection');
      case 'NetworkDown':
        return t('terminal.error.network_down');
      case 'DeviceBusy':
        return t('terminal.error.busy');
      case 'POSForceCancelled':
        return t('terminal.error.cancelled');
      default:
        return t('terminal.error.generic');
    }
  }

  if (ErrorCode === 114) {
    return t('terminal.error.payment_declined');
  }

  return t('terminal.error.terminal');
}
