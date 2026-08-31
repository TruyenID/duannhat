import { cn } from '../utils/cn';
import { useEffect, useRef } from 'react';
import { Animated, type ViewProps } from 'react-native';

function Skeleton({ className, style, ...props }: ViewProps) {
  const opacity = useRef(new Animated.Value(1)).current;

  useEffect(() => {
    const animation = Animated.loop(
      Animated.sequence([
        Animated.timing(opacity, { toValue: 0.5, duration: 1000, useNativeDriver: true }),
        Animated.timing(opacity, { toValue: 1, duration: 1000, useNativeDriver: true }),
      ])
    );
    animation.start();
    return () => animation.stop();
  }, [opacity]);

  return (
    <Animated.View
      className={cn('bg-muted rounded-md', className)}
      style={[{ opacity }, style]}
      {...props}
    />
  );
}

export { Skeleton };
