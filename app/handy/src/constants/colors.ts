import { Platform } from 'react-native';

export const Colors = {
  light: {
    // Base
    text: '#23221e',
    textSecondary: '#706d65',
    textMuted: '#949495',
    background: '#fafaf9',
    backgroundElement: '#f3f4f6',
    backgroundSelected: '#e8e9ec',
    card: '#ffffff',
    border: '#d6d3d0',
    borderSoft: '#ebe9e6',
    divider: '#ebe9e6',

    // Brand
    primary: '#0077c7',
    primarySoft: '#e6f1f9',
    sumi: '#23221e',

    // Semantic
    success: '#68be8d',
    successSoft: '#e7f4ec',
    warning: '#f8b500',
    warningSoft: '#fdf2d4',
    attention: '#eb6101',
    attentionSoft: '#fdebde',
    error: '#b7282e',
    errorSoft: '#fce8e9',
    info: '#4c6cb3',
    infoSoft: '#e7ecf6',

    // Table status
    statusFreeAccent: '#68be8d',
    statusFreeBg: '#ffffff',
    statusOccupiedAccent: '#eb6101',
    statusOccupiedBg: '#ffffff',
    statusReservedAccent: '#8a6500',
    statusReservedBg: '#fdf2d4',
    statusCleaningAccent: '#4c6cb3',
    statusCleaningBg: '#ffffff',
    statusOutOfServiceAccent: '#b7282e',
    statusOutOfServiceBg: '#ffffff',
  },
  dark: {
    // Base
    text: '#f0ede8',
    textSecondary: '#9b9790',
    textMuted: '#6b6865',
    background: '#1a1917',
    backgroundElement: '#252320',
    backgroundSelected: '#2e2c29',
    card: '#212120',
    border: '#3a3835',
    borderSoft: '#2e2c29',
    divider: '#2e2c29',

    // Brand
    primary: '#3fa8e8',
    primarySoft: '#0e2a3d',
    sumi: '#f0ede8',

    // Semantic
    success: '#5aa87a',
    successSoft: '#0e2d1c',
    warning: '#d4a000',
    warningSoft: '#2a2000',
    attention: '#d45a00',
    attentionSoft: '#2a1600',
    error: '#d94040',
    errorSoft: '#2d0f0f',
    info: '#5b7fd4',
    infoSoft: '#0e1a36',

    // Table status
    statusFreeAccent: '#5aa87a',
    statusFreeBg: '#212120',
    statusOccupiedAccent: '#d45a00',
    statusOccupiedBg: '#212120',
    statusReservedAccent: '#d4a000',
    statusReservedBg: '#2a2000',
    statusCleaningAccent: '#5b7fd4',
    statusCleaningBg: '#212120',
    statusOutOfServiceAccent: '#d94040',
    statusOutOfServiceBg: '#212120',
  },
} as const;

export type ColorScheme = keyof typeof Colors;
export type ThemeColor = keyof typeof Colors.light & keyof typeof Colors.dark;

export const Fonts = Platform.select({
  ios: {
    sans: 'system-ui',
    serif: 'ui-serif',
    rounded: 'ui-rounded',
    mono: 'ui-monospace',
  },
  default: {
    sans: 'normal',
    serif: 'serif',
    rounded: 'normal',
    mono: 'monospace',
  },
});
