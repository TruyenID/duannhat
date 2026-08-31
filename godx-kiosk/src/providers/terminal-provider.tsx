// src/providers/terminal-provider.tsx
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import { AppState, View } from 'react-native';
import { WebView } from 'react-native-webview';
import type { WebViewMessageEvent } from 'react-native-webview';
import { Asset } from 'expo-asset';
import { useTerminalConfig } from '../hooks/use-terminal-config';
import type {
  TerminalInMessage,
  TerminalOutMessage,
  TerminalStatus,
  VescaErrorEvent,
  VescaOutputCompleteEvent,
  VescaRequest,
} from '../types/terminal';

interface TerminalContextValue {
  status: TerminalStatus;
  statusEvent: string | null;
  result: VescaOutputCompleteEvent | null;
  error: VescaErrorEvent | null;
  isReady: boolean;
  isLoadingConfig: boolean;
  hasTerminal: boolean;
  requestPayment: (request: VescaRequest) => void;
  cancel: () => void;
  reset: () => void;
  testConnection: () => void;
  /** Re-print the last approved slip to recover a terminal stuck in LP0. */
  printRetry: () => void;
  /** Terminate + recreate the bridge Worker to clear a wedged DeviceBusy state. */
  forceReset: () => void;
}

const TerminalContext = createContext<TerminalContextValue | null>(null);

export function TerminalProvider({ children }: { children: React.ReactNode }) {
  const { config, isLoading } = useTerminalConfig();
  const configRef = useRef(config);
  configRef.current = config;
  const webViewRef = useRef<WebView>(null);

  const [status, setStatus] = useState<TerminalStatus>('idle');
  const statusRef = useRef<TerminalStatus>('idle');
  const [statusEvent, setStatusEvent] = useState<string | null>(null);
  const [result, setResult] = useState<VescaOutputCompleteEvent | null>(null);
  const [error, setError] = useState<VescaErrorEvent | null>(null);

  // Sync ref with state so callbacks always see latest value
  const updateStatus = useCallback((s: TerminalStatus) => {
    statusRef.current = s;
    setStatus(s);
  }, []);

  const hasTerminal = !isLoading && config !== null;
  const isReady = status === 'ready' || status === 'idle';
  const workerReady = useRef(false);
  const pendingMessage = useRef<TerminalOutMessage | null>(null);

  // Resolve vesca-bridge.html to a local file:// URI so WKWebView uses
  // loadFileURL (which grants Blob/Worker access) instead of loadHTMLString.
  const [bridgeUri, setBridgeUri] = useState<string | null>(null);

  useEffect(() => {
    if (!hasTerminal) return;
    const asset = Asset.fromModule(require('../../assets/vesca-bridge.html'));
    asset.downloadAsync().then(() => {
      if (asset.localUri) {
        setBridgeUri(asset.localUri);
      }
    });
  }, [hasTerminal]);

  // Set 'initializing' when config exists but WebView hasn't sent READY yet
  useEffect(() => {
    if (hasTerminal && statusRef.current === 'idle') {
      updateStatus('initializing');
    }
  }, [hasTerminal, updateStatus]);

  const postToWebView = useCallback((msg: TerminalOutMessage) => {
    console.log('[Terminal] postToWebView:', msg.type, 'workerReady:', workerReady.current, 'webViewRef:', !!webViewRef.current);
    if (!workerReady.current) {
      console.log('[Terminal] Worker not ready, queuing message');
      pendingMessage.current = msg;
      return;
    }
    webViewRef.current?.postMessage(JSON.stringify(msg));
  }, []);

  const flushPendingMessage = useCallback(() => {
    if (pendingMessage.current && workerReady.current) {
      webViewRef.current?.postMessage(JSON.stringify(pendingMessage.current));
      pendingMessage.current = null;
    }
  }, []);

  const requestPayment = useCallback(
    (request: VescaRequest) => {
      const cfg = configRef.current;
      if (!cfg || statusRef.current === 'processing') return;
      updateStatus('processing');
      setStatusEvent(null);
      setResult(null);
      setError(null);
      postToWebView({
        type: 'REQUEST',
        host: cfg.host,
        port: cfg.port,
        request,
      });
    },
    [updateStatus, postToWebView],
  );

  const cancel = useCallback(() => {
    postToWebView({ type: 'CANCEL' });
  }, [postToWebView]);

  // PrintRetry rides the REQUEST path; the terminal answers with
  // OutputCompleteEvent (-> status 'success') or ErrorEvent. Schema verified
  // against the Vesca FullFeatured-WS sample page (payment-sample.js v0.5).
  //
  // Unlike requestPayment, this does NOT bail when status is 'processing':
  // recovery exists precisely for the case where the provider is wedged in a
  // stale 'processing'/'success' state after a stuck (LP0) transaction, so the
  // busy-guard would otherwise silently swallow the recovery tap.
  const printRetry = useCallback(() => {
    const cfg = configRef.current;
    if (!cfg) return;
    updateStatus('processing');
    setStatusEvent(null);
    setResult(null);
    setError(null);
    postToWebView({
      type: 'REQUEST',
      host: cfg.host,
      port: cfg.port,
      request: { PrintRetry: { SequenceNumber: 113, CurrentService: 'All', TrainingMode: false } },
    });
  }, [updateStatus, postToWebView]);

  // Hard-reset the wedged bridge Worker. Bypass postToWebView's workerReady gate
  // (we send precisely because the worker is stuck) and post FORCE_RESET to the
  // bridge main thread, which terminates + recreates the Worker and re-emits
  // READY. Local state goes back to initializing until that READY lands.
  const forceReset = useCallback(() => {
    workerReady.current = false;
    pendingMessage.current = null;
    webViewRef.current?.postMessage(JSON.stringify({ type: 'FORCE_RESET' }));
    updateStatus(hasTerminal ? 'initializing' : 'idle');
    setStatusEvent(null);
    setResult(null);
    setError(null);
  }, [hasTerminal, updateStatus]);

  const reset = useCallback(() => {
    updateStatus(hasTerminal ? 'ready' : 'idle');
    setStatusEvent(null);
    setResult(null);
    setError(null);
    pendingMessage.current = null;
  }, [hasTerminal, updateStatus]);

  // App-switch / background policy: ABORT, don't try to survive. iOS suspends
  // the WebView when the app backgrounds (multitasking), which silently drops
  // the WebSocket to the P400 and would leave a sale half-open on the terminal.
  // So the moment we background mid-transaction we force-cancel it on the P400
  // (POS_FORCE_CANCEL) — the customer cannot keep paying through an app switch.
  // On return we hard-reset the worker so the connection comes back clean and a
  // fresh payment can start.
  //
  // Only a real `background` triggers this. `inactive` (notification banner,
  // control center) is transient and keeps the connection — cancelling on it
  // would abort a live payment every time a banner pops up.
  const wasBackgrounded = useRef(false);
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'background') {
        wasBackgrounded.current = true;
        if (statusRef.current === 'processing') {
          cancel();
        }
      } else if (state === 'active' && wasBackgrounded.current) {
        wasBackgrounded.current = false;
        // If the cancel resolved while suspended, status is already 'error'
        // (POSForceCancelled) — leave it so the screen shows the cancelled
        // state. Only force-reset when the worker is still wedged in
        // 'processing' (the cancel never reached the terminal before suspend).
        if (hasTerminal && statusRef.current === 'processing') forceReset();
      }
    });
    return () => sub.remove();
  }, [hasTerminal, cancel, forceReset]);

  const testConnection = useCallback(() => {
    const cfg = configRef.current;
    if (!cfg || statusRef.current === 'processing') return;
    updateStatus('processing');
    setStatusEvent(null);
    setResult(null);
    setError(null);
    postToWebView({
      type: 'REQUEST',
      host: cfg.host,
      port: cfg.port,
      request: {
        StartService: {
          CurrentService: 'Credit',
        },
      },
    });
  }, [updateStatus, postToWebView]);

  const handleMessage = useCallback((event: WebViewMessageEvent) => {
    try {
      const msg: TerminalInMessage = JSON.parse(event.nativeEvent.data);

      switch (msg.type) {
        case 'READY':
          workerReady.current = true;
          updateStatus('ready');
          flushPendingMessage();
          break;
        case 'STATUS_EVENT':
          setStatusEvent(msg.data.ResponseCode);
          break;
        case 'RESULT':
          updateStatus('success');
          setResult(msg.data);
          break;
        case 'ERROR':
          updateStatus('error');
          setError(msg.data);
          break;
        case 'INIT_ERROR':
          updateStatus('error');
          setError({
            ErrorEvent: {
              ErrorCode: 990,
              ErrorCodeExtended: -1,
              Errorcodedetail: '0129',
              Message: msg.message,
            },
          });
          break;
      }
    } catch {
      // Ignore malformed messages
    }
  }, []);

  const value = useMemo<TerminalContextValue>(
    () => ({
      status,
      statusEvent,
      result,
      error,
      isReady,
      isLoadingConfig: isLoading,
      hasTerminal,
      requestPayment,
      cancel,
      reset,
      testConnection,
      printRetry,
      forceReset,
    }),
    [status, statusEvent, result, error, isReady, hasTerminal, requestPayment, cancel, reset, testConnection, printRetry, forceReset],
  );

  return (
    <TerminalContext.Provider value={value}>
      {hasTerminal && bridgeUri && (
        <View className="absolute w-0 h-0 overflow-hidden">
          <WebView
            ref={webViewRef}
            source={{ uri: bridgeUri }}
            onMessage={handleMessage}
            originWhitelist={['*']}
            javaScriptEnabled
            // allowFileAccess (Android) defaults to FALSE in react-native-webview
            // v13+. Without it the WebView refuses to load the bundled
            // vesca-bridge.html at its file:// URI → net::ERR_ACCESS_DENIED →
            // the bridge Worker never posts READY → testConnection()'s message
            // sits queued forever and the UI spins "connecting" with no result.
            allowFileAccess
            allowFileAccessFromFileURLs
            allowUniversalAccessFromFileURLs
            // The bridge page is loaded over file:// and opens a cleartext
            // ws:// socket to the LAN payment terminal; allow that from inside
            // the WebView (app-level usesCleartextTraffic already permits it).
            mixedContentMode="always"
            allowingReadAccessToURL={bridgeUri.substring(0, bridgeUri.lastIndexOf('/'))}
            onError={() => {
              workerReady.current = false;
              updateStatus('error');
            }}
          />
        </View>
      )}
      {children}
    </TerminalContext.Provider>
  );
}

export function useTerminal(): TerminalContextValue {
  const ctx = useContext(TerminalContext);
  if (!ctx) {
    throw new Error('useTerminal must be used within TerminalProvider');
  }
  return ctx;
}
