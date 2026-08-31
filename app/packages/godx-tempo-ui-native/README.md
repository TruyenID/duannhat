# @godxjp/ui-native

> GodX React Native UI components — NativeWind + Expo.

## Install

```sh
npm install @godxjp/ui-native
```

## Components

| Component | Description |
|-----------|-------------|
| `Avatar` | Profile picture with Image + Fallback |
| `Badge` | Status badges (default, secondary, destructive, outline) |
| `Button` | Pressable with variants (default, destructive, outline, secondary, ghost, link) + sizes |
| `Card` | Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter |
| `Input` | Text input with size variants |
| `Separator` | Horizontal/vertical divider |
| `Skeleton` | Animated loading placeholder |
| `Text` | Typography with variants (h1-h4, p, blockquote, code, lead, large, small, muted) |
| `ErrorBoundary` | React Error Boundary with recovery UI |

## Usage

```tsx
import { Button, Card, CardContent, Text } from "@godxjp/ui-native";

<Card>
  <CardContent>
    <Text variant="h3">Hello</Text>
    <Button variant="default" size="lg">
      <Text>Press me</Text>
    </Button>
  </CardContent>
</Card>
```

## Requirements

- React Native >= 0.76
- NativeWind >= 4 (Tailwind CSS for RN)
- Expo (recommended)

## License

Private — GodX Japan.
