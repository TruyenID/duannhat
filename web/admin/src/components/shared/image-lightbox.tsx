"use client";

import Image from "next/image";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@godxjp/ui";

export interface ImageLightboxProps {
  src: string | null;
  alt: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function ImageLightbox({ src, alt, open, onOpenChange }: ImageLightboxProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        data-slot="image-lightbox"
        aria-describedby={undefined}
        className="w-fit max-w-[min(95vw,1100px)] border-none bg-transparent p-0 shadow-none [&>button]:hidden"
        onOpenAutoFocus={(e) => e.preventDefault()}
      >
        <DialogHeader className="sr-only">
          <DialogTitle>{alt}</DialogTitle>
        </DialogHeader>
        {src && (
          <Image
            src={src}
            alt={alt}
            width={1600}
            height={1600}
            sizes="95vw"
            style={{ width: "auto", height: "auto" }}
            className="max-h-[85vh] max-w-full rounded-lg bg-background object-contain shadow-2xl"
          />
        )}
      </DialogContent>
    </Dialog>
  );
}
