import React, { createContext, useContext, useEffect, useState } from 'react';
import { getStoredDevice, type StoredDevice } from '@/lib/auth';

interface DeviceContextValue {
  device: StoredDevice | null;
  shopSlug: string;
}

const DeviceContext = createContext<DeviceContextValue>({ device: null, shopSlug: '' });

export function DeviceProvider({ children }: { children: React.ReactNode }) {
  const [device, setDevice] = useState<StoredDevice | null>(null);

  useEffect(() => {
    getStoredDevice().then((d) => { if (d) setDevice(d); });
  }, []);

  return (
    <DeviceContext.Provider value={{ device, shopSlug: device?.shopSlug ?? '' }}>
      {children}
    </DeviceContext.Provider>
  );
}

export function useDevice(): DeviceContextValue {
  return useContext(DeviceContext);
}
