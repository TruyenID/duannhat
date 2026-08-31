import type { ExpoConfig } from 'expo/config';

const config: ExpoConfig = {
  name: 'godx-handy',
  slug: 'godx-handy',
  version: '1.0.0',
  orientation: 'portrait',
  icon: './assets/images/icon.png',
  scheme: 'godxhandy',
  userInterfaceStyle: 'automatic',
  ios: {
    bundleIdentifier: 'com.godx.handy',
    icon: './assets/expo.icon',
  },
  android: {
    package: 'com.godx.handy',
    adaptiveIcon: {
      backgroundColor: '#E6F4FE',
      foregroundImage: './assets/images/android-icon-foreground.png',
      backgroundImage: './assets/images/android-icon-background.png',
      monochromeImage: './assets/images/android-icon-monochrome.png',
    },
    predictiveBackGestureEnabled: false,
  },
  web: {
    output: 'static',
    favicon: './assets/images/favicon.png',
  },
  plugins: [
    'expo-router',
    [
      'expo-build-properties',
      {
        android: {
          minSdkVersion: 26,
        },
      },
    ],
    [
      'expo-splash-screen',
      {
        backgroundColor: '#208AEF',
        android: {
          image: './assets/images/splash-icon.png',
          imageWidth: 76,
        },
      },
    ],
  ],
  updates: {
    url: 'https://u.expo.dev/959fb974-1650-418a-98ea-20ff3766d611',
  },
  runtimeVersion: {
    policy: 'appVersion',
  },
  extra: {
    apiUrl: process.env.EXPO_PUBLIC_API_URL ?? 'http://localhost:5400',
    eas: {
      projectId: '959fb974-1650-418a-98ea-20ff3766d611',
    },
  },
  experiments: {
    typedRoutes: true,
    reactCompiler: true,
  },
};

export default config;
