// src/hooks/use-terminal-cancel.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createElement, act, useRef } from 'react';
import { createRoot } from 'react-dom/client';
import { useTerminalCancel } from './use-terminal-cancel';
import type { TerminalStatus } from '../types/terminal';

// React 19-compatible renderHook (mirrors the pattern in use-payment.test.ts)
function renderHook<T, P>(
  hookFn: (props: P) => T,
  options?: { initialProps?: P },
): {
  result: { current: T };
  rerender: (props: P) => void;
} {
  const result: { current: T } = { current: undefined as unknown as T };
  let currentProps = options?.initialProps as P;

  const container = document.createElement('div');
  document.body.appendChild(container);

  function HookHarness({ hookProps }: { hookProps: P }) {
    const ref = useRef<T>(undefined as unknown as T);
    ref.current = hookFn(hookProps);
    result.current = ref.current;
    return null;
  }

  const root = createRoot(container);
  act(() => {
    root.render(createElement(HookHarness, { hookProps: currentProps }));
  });

  function rerender(props: P) {
    currentProps = props;
    act(() => {
      root.render(createElement(HookHarness, { hookProps: currentProps }));
    });
  }

  return { result, rerender };
}

describe('useTerminalCancel', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  it('calls fail() and onCancelled when status is pending after cancel wait', async () => {
    const cancel = vi.fn();
    const fail = vi.fn().mockResolvedValue(undefined);
    const checkStatus = vi.fn().mockResolvedValue('pending');
    const onSuccess = vi.fn();
    const onCancelled = vi.fn();

    const { result } = renderHook(
      () =>
        useTerminalCancel({
          cancel,
          fail,
          checkStatus,
          terminalStatus: 'idle' as TerminalStatus,
          terminalError: null,
          onSuccess,
          onCancelled,
        }),
      { initialProps: undefined },
    );

    const cancelPromise = result.current.execute();
    await vi.advanceTimersByTimeAsync(3000);
    await cancelPromise;

    expect(cancel).toHaveBeenCalledOnce();
    expect(checkStatus).toHaveBeenCalledOnce();
    expect(fail).toHaveBeenCalledOnce();
    expect(onCancelled).toHaveBeenCalledOnce();
    expect(onSuccess).not.toHaveBeenCalled();
  });

  it('calls onSuccess when payment captured during cancel wait (ghost-payment guard)', async () => {
    const cancel = vi.fn();
    const fail = vi.fn();
    const checkStatus = vi.fn().mockResolvedValue('succeeded');
    const onSuccess = vi.fn();
    const onCancelled = vi.fn();

    const { result } = renderHook(
      () =>
        useTerminalCancel({
          cancel,
          fail,
          checkStatus,
          terminalStatus: 'idle' as TerminalStatus,
          terminalError: null,
          onSuccess,
          onCancelled,
        }),
      { initialProps: undefined },
    );

    const cancelPromise = result.current.execute();
    await vi.advanceTimersByTimeAsync(3000);
    await cancelPromise;

    expect(cancel).toHaveBeenCalledOnce();
    expect(checkStatus).toHaveBeenCalledOnce();
    expect(fail).not.toHaveBeenCalled();
    expect(onSuccess).toHaveBeenCalledOnce();
    expect(onCancelled).not.toHaveBeenCalled();
  });

  it('forwards terminalError reason and errorCode to fail()', async () => {
    const cancel = vi.fn();
    const fail = vi.fn().mockResolvedValue(undefined);
    const checkStatus = vi.fn().mockResolvedValue('pending');
    const onSuccess = vi.fn();
    const onCancelled = vi.fn();

    const terminalError = {
      ErrorEvent: {
        Message: 'Card declined',
        Errorcodedetail: '0114',
      },
    };

    const { result } = renderHook(
      () =>
        useTerminalCancel({
          cancel,
          fail,
          checkStatus,
          terminalStatus: 'idle' as TerminalStatus,
          // eslint-disable-next-line @typescript-eslint/no-explicit-any
          terminalError: terminalError as any, // VescaErrorEvent — keep minimal shape
          onSuccess,
          onCancelled,
        }),
      { initialProps: undefined },
    );

    const cancelPromise = result.current.execute();
    await vi.advanceTimersByTimeAsync(3000);
    await cancelPromise;

    expect(fail).toHaveBeenCalledWith('Card declined', '0114');
    expect(onCancelled).toHaveBeenCalledOnce();
  });

  it('resolves early when terminal status changes to error/ready/idle before 3s elapses', async () => {
    const cancel = vi.fn();
    const fail = vi.fn().mockResolvedValue(undefined);
    const checkStatus = vi.fn().mockResolvedValue('pending');
    const onSuccess = vi.fn();
    const onCancelled = vi.fn();

    const { result, rerender } = renderHook(
      ({ status }: { status: TerminalStatus }) =>
        useTerminalCancel({
          cancel,
          fail,
          checkStatus,
          terminalStatus: status,
          terminalError: null,
          onSuccess,
          onCancelled,
        }),
      { initialProps: { status: 'processing' as TerminalStatus } },
    );

    const cancelPromise = result.current.execute();
    await vi.advanceTimersByTimeAsync(500);

    // Simulate terminal acking cancel before the 3s timeout.
    rerender({ status: 'error' as TerminalStatus });
    await vi.advanceTimersByTimeAsync(0);
    await cancelPromise;

    expect(checkStatus).toHaveBeenCalledOnce();
    expect(fail).toHaveBeenCalledOnce();
    expect(onCancelled).toHaveBeenCalledOnce();
  });
});
