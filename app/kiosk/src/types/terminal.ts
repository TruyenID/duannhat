// src/types/terminal.ts

/** Terminal connection config stored in AsyncStorage. */
export interface TerminalConfig {
  host: string;
  port: number;
}

/** VescaJS payment service types. */
export type VescaService = 'Credit' | 'ElectronicMoney' | 'QRCode' | 'QRPayment';

/** VescaJS request JSON sent to the terminal. */
export type VescaRequest =
  | VescaStartServiceRequest
  | VescaAuthorizeSalesRequest
  | VescaSubtractValueRequest
  | VescaPrintRetryRequest;

/** StartService — connection test only (疎通確認). */
export interface VescaStartServiceRequest {
  StartService: {
    CurrentService: VescaService;
    AdditionalSecurityInformation?: Record<string, unknown>;
  };
}

/** AuthorizeSales — actual payment transaction. */
export interface VescaAuthorizeSalesRequest {
  AuthorizeSales: {
    SequenceNumber: number;
    CurrentService: VescaService;
    Amount: number;
    TaxOthers?: number;
    TrainingMode?: boolean;
    AdditionalSecurityInformation?: Record<string, unknown>;
  };
}

/** SubtractValue — QR payment (PayPay, Alipay, WeChat, LINE Pay, etc.). */
export interface VescaSubtractValueRequest {
  SubtractValue: {
    SequenceNumber: number;
    CurrentService: VescaService;
    Amount: number;
    TrainingMode?: boolean;
    AdditionalSecurityInformation?: Record<string, unknown>;
  };
}

/**
 * PrintRetry — re-print the slip of the last approved transaction.
 *
 * Recovers a terminal stuck in the LP0 state (transaction approved but the
 * internal slip failed to print, e.g. paper jam / out of paper — the terminal
 * then waits for the POS to send PrintRetry). Schema verified against the Vesca
 * FullFeatured-WS sample page (payment-sample.js v0.5). Routed through the same
 * REQUEST path as AuthorizeSales; returns OutputCompleteEvent on success.
 */
export interface VescaPrintRetryRequest {
  PrintRetry: {
    SequenceNumber: number;
    CurrentService: 'All';
    TrainingMode: boolean;
  };
}

/** Messages sent from React Native to the WebView bridge. */
export type TerminalOutMessage =
  | { type: 'REQUEST'; host: string; port: number; request: VescaRequest }
  | { type: 'CANCEL' }
  // Hard-reset the bridge Worker (terminate + recreate) to clear a stuck
  // singleton that's rejecting new requests with DeviceBusy. Clears app-side
  // state only — does NOT settle a transaction held open on the physical
  // terminal (use PrintRetry for that).
  | { type: 'FORCE_RESET' };

/** Messages received from the WebView bridge to React Native. */
export type TerminalInMessage =
  | { type: 'READY' }
  | { type: 'STATUS_EVENT'; data: { ResponseCode: string } }
  | { type: 'RESULT'; data: VescaOutputCompleteEvent }
  | { type: 'ERROR'; data: VescaErrorEvent }
  | { type: 'INIT_ERROR'; message: string };

/** Successful terminal transaction result. */
export interface VescaOutputCompleteEvent {
  OutputCompleteEvent: Record<string, unknown>;
}

/** Terminal transaction error. */
export interface VescaErrorEvent {
  ErrorEvent: {
    ErrorCode: number;
    ErrorCodeExtended: number;
    Errorcodedetail: string;
    Message?: string;
    SequenceNumber?: number;
    CurrentService?: string;
  };
}

/** Terminal hook status. */
export type TerminalStatus =
  | 'idle'
  | 'initializing'
  | 'ready'
  | 'processing'
  | 'success'
  | 'error';
