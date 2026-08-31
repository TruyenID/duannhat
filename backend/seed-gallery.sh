#!/bin/bash
# Create placeholder JPG images for gallery seeding

DIR="storage/app/gallery-fixtures"
mkdir -p "$DIR"

# Minimal valid JPG (1x1 pixel, base64 encoded)
# This is a real JPG file, just very small
JPG_BASE64="/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCiABhw/9k="

for i in {1..10}; do
  echo "$JPG_BASE64" | base64 -d > "$DIR/product-$i.jpg"
  echo "Created $DIR/product-$i.jpg"
done

echo ""
echo "✓ Created 10 placeholder images"
echo "Now run: php artisan db:seed --class=ProductGallerySeeder"
