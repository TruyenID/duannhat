const React = require("react");

const View = (props) => React.createElement("div", props);
const Text = (props) => React.createElement("span", props);
const Pressable = (props) => {
  const { onPress, disabled, ...rest } = props;
  return React.createElement("button", {
    ...rest,
    onClick: disabled ? undefined : onPress,
    disabled,
    role: "button",
  });
};
const TextInput = React.forwardRef((props, ref) => {
  const { onChangeText, editable, ...rest } = props;
  return React.createElement("input", {
    ...rest,
    ref,
    onChange: onChangeText ? (e) => onChangeText(e.target.value) : undefined,
    disabled: editable === false,
  });
});
const Animated = {
  View,
  Value: class { constructor() {} },
  loop: () => ({ start: () => {}, stop: () => {} }),
  sequence: () => ({}),
  timing: () => ({}),
};
const Platform = { OS: "ios", Version: "17.0", select: (obj) => obj.ios || obj.default };
const StyleSheet = { create: (s) => s };

module.exports = {
  View, Text, Pressable, TextInput, Animated, Platform, StyleSheet,
  TouchableOpacity: Pressable,
  ScrollView: View,
  SafeAreaView: View,
  ActivityIndicator: View,
  Image: (props) => React.createElement("img", props),
};
