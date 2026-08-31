import { Link, Stack } from "expo-router";
import { StyleSheet, Text, View } from "react-native";

export default function NotFoundScreen() {
  return (
    <>
      <Stack.Screen options={{ title: "Not found", headerShown: true }} />
      <View style={styles.root}>
        <Text style={styles.title}>Screen not found</Text>
        <Link href="/" style={styles.link}>
          Go home
        </Link>
      </View>
    </>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#f5f5f4",
    gap: 12,
  },
  title: {
    fontSize: 18,
    fontWeight: "600",
    color: "#1c1917",
  },
  link: {
    fontSize: 16,
    color: "#2563eb",
  },
});
