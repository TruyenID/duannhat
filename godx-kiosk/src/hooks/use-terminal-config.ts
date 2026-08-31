// src/hooks/use-terminal-config.ts
import { useCallback, useEffect, useState } from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import type { TerminalConfig } from '../types/terminal';

const HOST_KEY = 'kiosk_terminal_host';
const PORT_KEY = 'kiosk_terminal_port';

function isValidIp(ip: string): boolean {
  const regex = /^(\d{1,3}\.){3}\d{1,3}$/;
  if (!regex.test(ip)) return false;
  return ip.split('.').every((n) => Number(n) >= 0 && Number(n) <= 255);
}

function isValidPort(port: number): boolean {
  return Number.isInteger(port) && port >= 1 && port <= 65535;
}

interface UseTerminalConfigReturn {
  config: TerminalConfig | null;
  isLoading: boolean;
  saveConfig: (host: string, port: number) => Promise<void>;
}

export function useTerminalConfig(): UseTerminalConfigReturn {
  const [config, setConfig] = useState<TerminalConfig | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      AsyncStorage.getItem(HOST_KEY),
      AsyncStorage.getItem(PORT_KEY),
    ]).then(([host, port]) => {
      console.log('[TerminalConfig] loaded from storage:', { host, port });
      if (host && port) {
        setConfig({ host, port: Number(port) });
      }
      setIsLoading(false);
    });
  }, []);

  const saveConfig = useCallback(async (host: string, port: number) => {
    const trimmedHost = host.trim();
    if (!isValidIp(trimmedHost)) {
      throw new Error('invalid_ip');
    }
    if (!isValidPort(port)) {
      throw new Error('invalid_port');
    }
    await Promise.all([
      AsyncStorage.setItem(HOST_KEY, trimmedHost),
      AsyncStorage.setItem(PORT_KEY, String(port)),
    ]);
    setConfig({ host: trimmedHost, port });
  }, []);

  return { config, isLoading, saveConfig };
}
