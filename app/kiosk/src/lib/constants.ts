import { Platform } from "react-native";

export const IS_WEB = Platform.OS === "web";
export const IS_IOS = Platform.OS === "ios";
export const IS_ANDROID = Platform.OS === "android";

export const PASSCODE_LENGTH = 8;
export const SECURE_STORE_PASSCODE_KEY = "kiosk_passcode";
