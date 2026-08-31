const { getDefaultConfig } = require("expo/metro-config");
const { withNativeWind } = require("nativewind/metro");

const config = getDefaultConfig(__dirname);

config.resolver.assetExts.push("html");

config.resolver.blockList = [
  ...(config.resolver.blockList ?? []),
  /\.test\.[jt]sx?$/,
  /\.spec\.[jt]sx?$/,
];

module.exports = withNativeWind(config, { input: "./global.css" });
