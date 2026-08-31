import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './styles/globals.css'
import { initSentry } from './lib/sentry'
import App from './App.tsx'

// Initialise error tracking BEFORE React mounts so a crash during the
// first render is captured. Silent no-op when VITE_SENTRY_DSN is unset
// (dev, tests, unwired deploys).
initSentry()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
