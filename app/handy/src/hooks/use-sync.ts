import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useDevice } from '@/lib/device-context';

export function useSync() {
  const qc = useQueryClient();
  const { shopSlug } = useDevice();
  const [syncing, setSyncing] = useState(false);

  async function sync(): Promise<void> {
    if (syncing) return;
    setSyncing(true);
    try {
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['shop', shopSlug, 'settings', 'order'] }),
        qc.invalidateQueries({ queryKey: ['orders', shopSlug] }),
        qc.invalidateQueries({ queryKey: ['tables', shopSlug] }),
      ]);
    } finally {
      setSyncing(false);
    }
  }

  return { sync, syncing };
}