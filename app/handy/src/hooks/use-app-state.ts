import { useEffect, useRef, useState } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

export function useAppState(): AppStateStatus {
  const [status, setStatus] = useState<AppStateStatus>(AppState.currentState);
  const ref = useRef(AppState.currentState);

  useEffect(() => {
    const sub = AppState.addEventListener('change', (next) => {
      ref.current = next;
      setStatus(next);
    });
    return () => sub.remove();
  }, []);

  return status;
}
