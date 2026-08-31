import { Platform } from 'react-native';

export const Layout = {
  screenPaddingH: 12,
  cardGap: 8,
  cardWidth: 108,
  cardHeight: 88,
  statusStripeWidth: 3,
  cardRadius: 6,
  headerHeight: 52,
  cartBarHeight: 52,
  sectionGap: 16,
  maxContentWidth: 800,
  bottomTabInset: Platform.select({ ios: 50, android: 80 }) ?? 0,
} as const;

export const Spacing = {
  half: 2,
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const;

export const Radius = {
  sm: 4,
  md: 6,
  lg: 12,
  xl: 24,
} as const;
