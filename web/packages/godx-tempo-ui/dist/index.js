"use client";
import * as AccordionPrimitive from '@radix-ui/react-accordion';
import { CheckIcon, XIcon, EyeOff, Eye, ChevronDownIcon, ChevronDown, CornerDownLeft, ChevronRight, MoreHorizontal, ChevronLeftIcon, ChevronRightIcon, ArrowLeft, ArrowRight, Check, SearchIcon, X, ChevronsUpDown, CircleIcon, Calendar as Calendar$1, ImagePlus, Upload, Paperclip, Plus, MinusIcon, MoreHorizontalIcon, Star, GripVerticalIcon, Bold, Italic, Strikethrough, Code, Heading1, Heading2, Heading3, List, ListOrdered, Quote, Undo2, Redo2, ChevronUpIcon, PanelLeftIcon, Loader2Icon, Clock, FileImage, FileVideo, FileText, File } from 'lucide-react';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';
import { jsx, jsxs, Fragment } from 'react/jsx-runtime';
import { cva } from 'class-variance-authority';
import * as AlertDialogPrimitive from '@radix-ui/react-alert-dialog';
import * as React2 from 'react';
import { createContext, useContext, useState, useRef, useEffect, useCallback } from 'react';
import { Slot } from '@radix-ui/react-slot';
import * as AspectRatioPrimitive from '@radix-ui/react-aspect-ratio';
import * as AvatarPrimitive from '@radix-ui/react-avatar';
import { getDefaultClassNames, DayPicker } from 'react-day-picker';
import useEmblaCarousel from 'embla-carousel-react';
import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import * as CollapsiblePrimitive from '@radix-ui/react-collapsible';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import { Command as Command$1 } from 'cmdk';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import * as ContextMenuPrimitive from '@radix-ui/react-context-menu';
import { format } from 'date-fns';
import { Drawer as Drawer$1 } from 'vaul';
import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import { FormProvider, Controller, useFormContext, useFormState } from 'react-hook-form';
import * as LabelPrimitive from '@radix-ui/react-label';
import * as HoverCardPrimitive from '@radix-ui/react-hover-card';
import { OTPInput, OTPInputContext } from 'input-otp';
import * as MenubarPrimitive from '@radix-ui/react-menubar';
import * as NavigationMenuPrimitive from '@radix-ui/react-navigation-menu';
import * as SeparatorPrimitive from '@radix-ui/react-separator';
import * as ProgressPrimitive from '@radix-ui/react-progress';
import * as RadioGroupPrimitive from '@radix-ui/react-radio-group';
import * as ResizablePrimitive from 'react-resizable-panels';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import * as ScrollAreaPrimitive from '@radix-ui/react-scroll-area';
import * as SelectPrimitive from '@radix-ui/react-select';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import * as SliderPrimitive from '@radix-ui/react-slider';
import { Toaster as Toaster$1 } from 'sonner';
export { toast } from 'sonner';
import * as SwitchPrimitives from '@radix-ui/react-switch';
import * as TabsPrimitive from '@radix-ui/react-tabs';
import * as TogglePrimitive from '@radix-ui/react-toggle';
import * as ToggleGroupPrimitive from '@radix-ui/react-toggle-group';

// src/accordion/index.tsx
function cn(...inputs) {
  return twMerge(clsx(inputs));
}
function Accordion({
  ...props
}) {
  return /* @__PURE__ */ jsx(AccordionPrimitive.Root, { "data-slot": "accordion", ...props });
}
function AccordionItem({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    AccordionPrimitive.Item,
    {
      "data-slot": "accordion-item",
      className: cn("border-b last:border-b-0", className),
      ...props
    }
  );
}
function AccordionTrigger({
  className,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsx(AccordionPrimitive.Header, { className: "flex", children: /* @__PURE__ */ jsxs(
    AccordionPrimitive.Trigger,
    {
      "data-slot": "accordion-trigger",
      className: cn(
        "focus-visible:border-ring focus-visible:ring-ring/50 flex flex-1 items-start justify-between gap-4 rounded-md py-[var(--density-accordion)] text-left text-sm font-medium transition-all outline-none hover:underline focus-visible:ring-[3px] disabled:pointer-events-none disabled:opacity-50 [&[data-state=open]>svg]:rotate-180",
        className
      ),
      ...props,
      children: [
        children,
        /* @__PURE__ */ jsx(ChevronDownIcon, { className: "text-muted-foreground pointer-events-none size-4 shrink-0 translate-y-0.5 transition-transform duration-200" })
      ]
    }
  ) });
}
function AccordionContent({
  className,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    AccordionPrimitive.Content,
    {
      "data-slot": "accordion-content",
      className: "data-[state=closed]:animate-accordion-up data-[state=open]:animate-accordion-down overflow-hidden text-sm",
      ...props,
      children: /* @__PURE__ */ jsx("div", { className: cn("pt-0 pb-[var(--density-accordion)]", className), children })
    }
  );
}
var alertVariants = cva(
  "relative w-full rounded-lg border px-4 py-3 text-sm grid has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] grid-cols-[0_1fr] has-[>svg]:gap-x-3 gap-y-0.5 items-start [&>svg]:size-4 [&>svg]:translate-y-0.5 [&>svg]:text-current",
  {
    variants: {
      variant: {
        // Bordered card style — color applied via compoundVariants
        default: "",
        // Legacy — maps to default + destructive color (backward compatible)
        destructive: "",
        // Filled background style — color applied via compoundVariants
        soft: ""
      },
      color: {
        primary: "",
        destructive: "",
        success: "",
        warning: "",
        info: ""
      }
    },
    compoundVariants: [
      // ── Default (bordered) × color ──
      { variant: "default", color: "primary", className: "bg-card text-card-foreground" },
      { variant: "default", color: "destructive", className: "text-destructive bg-card [&>svg]:text-current *:data-[slot=alert-description]:text-destructive/90" },
      { variant: "default", color: "success", className: "text-success bg-card [&>svg]:text-current *:data-[slot=alert-description]:text-success/90" },
      { variant: "default", color: "warning", className: "text-warning bg-card [&>svg]:text-current *:data-[slot=alert-description]:text-warning/90" },
      { variant: "default", color: "info", className: "text-info bg-card [&>svg]:text-current *:data-[slot=alert-description]:text-info/90" },
      // ── Legacy destructive variant (backward compat) ──
      { variant: "destructive", className: "text-destructive bg-card [&>svg]:text-current *:data-[slot=alert-description]:text-destructive/90" },
      // ── Soft (filled bg) × color ──
      { variant: "soft", color: "primary", className: "bg-primary/10 text-primary border-primary/20 [&>svg]:text-current *:data-[slot=alert-description]:text-primary/90" },
      { variant: "soft", color: "destructive", className: "bg-destructive/10 text-destructive border-destructive/20 [&>svg]:text-current *:data-[slot=alert-description]:text-destructive/90" },
      { variant: "soft", color: "success", className: "bg-success/10 text-success border-success/20 [&>svg]:text-current *:data-[slot=alert-description]:text-success/90" },
      { variant: "soft", color: "warning", className: "bg-warning/10 text-warning border-warning/20 [&>svg]:text-current *:data-[slot=alert-description]:text-warning/90" },
      { variant: "soft", color: "info", className: "bg-info/10 text-info border-info/20 [&>svg]:text-current *:data-[slot=alert-description]:text-info/90" }
    ],
    defaultVariants: {
      variant: "default",
      color: "primary"
    }
  }
);
function Alert({
  className,
  variant,
  color,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "alert",
      role: "alert",
      className: cn(alertVariants({ variant, color }), className),
      ...props
    }
  );
}
function AlertTitle({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "alert-title",
      className: cn(
        "col-start-2 line-clamp-1 min-h-4 font-medium tracking-tight",
        className
      ),
      ...props
    }
  );
}
function AlertDescription({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "alert-description",
      className: cn(
        "text-muted-foreground col-start-2 grid justify-items-start gap-1 text-sm [&_p]:leading-relaxed",
        className
      ),
      ...props
    }
  );
}
var buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        // Solid — color applied via compoundVariants
        default: "",
        // Legacy — maps to solid + destructive color (backward compatible)
        destructive: "",
        // Color-independent
        secondary: "bg-secondary text-secondary-foreground hover:bg-secondary/80",
        // Color-aware — applied via compoundVariants
        outline: "",
        soft: "",
        ghost: "",
        link: ""
      },
      color: {
        primary: "",
        destructive: "",
        success: "",
        warning: "",
        info: ""
      },
      size: {
        xs: "h-element-xs rounded-md gap-1 px-2 text-xs has-[>svg]:px-1.5",
        sm: "h-element-sm rounded-md gap-1.5 px-3 has-[>svg]:px-2.5",
        default: "h-element px-4 py-2 has-[>svg]:px-3",
        lg: "h-element-lg rounded-md px-6 has-[>svg]:px-4",
        xl: "h-element-xl rounded-md px-8 text-base font-semibold has-[>svg]:px-5",
        icon: "size-element rounded-md"
      }
    },
    compoundVariants: [
      // ── Solid (default variant) × color ──
      { variant: "default", color: "primary", className: "bg-primary text-primary-foreground hover:bg-primary/90" },
      { variant: "default", color: "destructive", className: "bg-destructive text-destructive-foreground hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40" },
      { variant: "default", color: "success", className: "bg-success text-success-foreground hover:bg-success/90 focus-visible:ring-success/20 dark:focus-visible:ring-success/40" },
      { variant: "default", color: "warning", className: "bg-warning text-warning-foreground hover:bg-warning/90 focus-visible:ring-warning/20 dark:focus-visible:ring-warning/40" },
      { variant: "default", color: "info", className: "bg-info text-info-foreground hover:bg-info/90 focus-visible:ring-info/20 dark:focus-visible:ring-info/40" },
      // ── Legacy destructive variant (backward compat) ──
      { variant: "destructive", className: "bg-destructive text-destructive-foreground hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40" },
      // ── Outline × color ──
      { variant: "outline", color: "primary", className: "border border-input bg-background text-foreground hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50" },
      { variant: "outline", color: "destructive", className: "border border-destructive/50 text-destructive bg-background hover:bg-destructive/10 focus-visible:ring-destructive/20" },
      { variant: "outline", color: "success", className: "border border-success/50 text-success bg-background hover:bg-success/10 focus-visible:ring-success/20" },
      { variant: "outline", color: "warning", className: "border border-warning/50 text-warning bg-background hover:bg-warning/10 focus-visible:ring-warning/20" },
      { variant: "outline", color: "info", className: "border border-info/50 text-info bg-background hover:bg-info/10 focus-visible:ring-info/20" },
      // ── Soft × color ──
      { variant: "soft", color: "primary", className: "bg-primary/10 text-primary hover:bg-primary/20" },
      { variant: "soft", color: "destructive", className: "bg-destructive/10 text-destructive hover:bg-destructive/20 focus-visible:ring-destructive/20" },
      { variant: "soft", color: "success", className: "bg-success/10 text-success hover:bg-success/20 focus-visible:ring-success/20" },
      { variant: "soft", color: "warning", className: "bg-warning/10 text-warning hover:bg-warning/20 focus-visible:ring-warning/20" },
      { variant: "soft", color: "info", className: "bg-info/10 text-info hover:bg-info/20 focus-visible:ring-info/20" },
      // ── Ghost × color ──
      { variant: "ghost", color: "primary", className: "hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50" },
      { variant: "ghost", color: "destructive", className: "text-destructive hover:bg-destructive/10" },
      { variant: "ghost", color: "success", className: "text-success hover:bg-success/10" },
      { variant: "ghost", color: "warning", className: "text-warning hover:bg-warning/10" },
      { variant: "ghost", color: "info", className: "text-info hover:bg-info/10" },
      // ── Link × color ──
      { variant: "link", color: "primary", className: "text-primary underline-offset-4 hover:underline" },
      { variant: "link", color: "destructive", className: "text-destructive underline-offset-4 hover:underline" },
      { variant: "link", color: "success", className: "text-success underline-offset-4 hover:underline" },
      { variant: "link", color: "warning", className: "text-warning underline-offset-4 hover:underline" },
      { variant: "link", color: "info", className: "text-info underline-offset-4 hover:underline" }
    ],
    defaultVariants: {
      variant: "default",
      color: "primary",
      size: "default"
    }
  }
);
var Button = React2.forwardRef(
  ({ className, variant, color, size, asChild = false, block = false, ...props }, ref) => {
    const Comp = asChild ? Slot : "button";
    return /* @__PURE__ */ jsx(
      Comp,
      {
        ref,
        "data-slot": "button",
        className: cn(buttonVariants({ variant, color, size }), block && "w-full", className),
        ...props
      }
    );
  }
);
Button.displayName = "Button";
function AlertDialog({
  ...props
}) {
  return /* @__PURE__ */ jsx(AlertDialogPrimitive.Root, { "data-slot": "alert-dialog", ...props });
}
function AlertDialogTrigger({
  ...props
}) {
  return /* @__PURE__ */ jsx(AlertDialogPrimitive.Trigger, { "data-slot": "alert-dialog-trigger", ...props });
}
function AlertDialogPortal({
  ...props
}) {
  return /* @__PURE__ */ jsx(AlertDialogPrimitive.Portal, { "data-slot": "alert-dialog-portal", ...props });
}
function AlertDialogOverlay({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    AlertDialogPrimitive.Overlay,
    {
      "data-slot": "alert-dialog-overlay",
      className: cn(
        "data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/50",
        className
      ),
      ...props
    }
  );
}
function AlertDialogContent({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsxs(AlertDialogPortal, { children: [
    /* @__PURE__ */ jsx(AlertDialogOverlay, {}),
    /* @__PURE__ */ jsx(
      AlertDialogPrimitive.Content,
      {
        "data-slot": "alert-dialog-content",
        className: cn(
          "bg-background data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-[50%] left-[50%] z-50 grid w-full max-w-[calc(100%-2rem)] translate-x-[-50%] translate-y-[-50%] gap-4 rounded-lg border p-6 shadow-lg duration-200 sm:max-w-lg",
          className
        ),
        ...props
      }
    )
  ] });
}
function AlertDialogHeader({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "alert-dialog-header",
      className: cn("flex flex-col gap-2 text-center sm:text-left", className),
      ...props
    }
  );
}
function AlertDialogFooter({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "alert-dialog-footer",
      className: cn(
        "flex flex-col-reverse gap-2 sm:flex-row sm:justify-end",
        className
      ),
      ...props
    }
  );
}
function AlertDialogTitle({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    AlertDialogPrimitive.Title,
    {
      "data-slot": "alert-dialog-title",
      className: cn("text-lg font-semibold", className),
      ...props
    }
  );
}
function AlertDialogDescription({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    AlertDialogPrimitive.Description,
    {
      "data-slot": "alert-dialog-description",
      className: cn("text-muted-foreground text-sm", className),
      ...props
    }
  );
}
function AlertDialogAction({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    AlertDialogPrimitive.Action,
    {
      className: cn(buttonVariants(), className),
      ...props
    }
  );
}
function AlertDialogCancel({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    AlertDialogPrimitive.Cancel,
    {
      className: cn(buttonVariants({ variant: "outline" }), className),
      ...props
    }
  );
}
function AspectRatio({
  ...props
}) {
  return /* @__PURE__ */ jsx(AspectRatioPrimitive.Root, { "data-slot": "aspect-ratio", ...props });
}
function Avatar({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    AvatarPrimitive.Root,
    {
      "data-slot": "avatar",
      className: cn(
        "relative flex size-10 shrink-0 overflow-hidden rounded-full",
        className
      ),
      ...props
    }
  );
}
function AvatarImage({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    AvatarPrimitive.Image,
    {
      "data-slot": "avatar-image",
      className: cn("aspect-square size-full", className),
      ...props
    }
  );
}
function AvatarFallback({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    AvatarPrimitive.Fallback,
    {
      "data-slot": "avatar-fallback",
      className: cn(
        "bg-muted flex size-full items-center justify-center rounded-full",
        className
      ),
      ...props
    }
  );
}
var badgeVariants = cva(
  "inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        // Solid — color applied via compoundVariants
        default: "",
        // Legacy — maps to solid + destructive color (backward compatible)
        destructive: "",
        // Color-independent
        secondary: "border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/90",
        // Color-aware — applied via compoundVariants
        outline: "",
        soft: ""
      },
      color: {
        primary: "",
        destructive: "",
        success: "",
        warning: "",
        info: ""
      }
    },
    compoundVariants: [
      // ── Solid (default variant) × color ──
      { variant: "default", color: "primary", className: "border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary/90" },
      { variant: "default", color: "destructive", className: "border-transparent bg-destructive text-destructive-foreground [a&]:hover:bg-destructive/90" },
      { variant: "default", color: "success", className: "border-transparent bg-success text-success-foreground [a&]:hover:bg-success/90" },
      { variant: "default", color: "warning", className: "border-transparent bg-warning text-warning-foreground [a&]:hover:bg-warning/90" },
      { variant: "default", color: "info", className: "border-transparent bg-info text-info-foreground [a&]:hover:bg-info/90" },
      // ── Legacy destructive variant (backward compat) ──
      { variant: "destructive", className: "border-transparent bg-destructive text-destructive-foreground [a&]:hover:bg-destructive/90" },
      // ── Outline × color ──
      { variant: "outline", color: "primary", className: "text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground" },
      { variant: "outline", color: "destructive", className: "border-destructive/50 text-destructive [a&]:hover:bg-destructive/10" },
      { variant: "outline", color: "success", className: "border-success/50 text-success [a&]:hover:bg-success/10" },
      { variant: "outline", color: "warning", className: "border-warning/50 text-warning [a&]:hover:bg-warning/10" },
      { variant: "outline", color: "info", className: "border-info/50 text-info [a&]:hover:bg-info/10" },
      // ── Soft × color ──
      { variant: "soft", color: "primary", className: "border-transparent bg-primary/10 text-primary [a&]:hover:bg-primary/20" },
      { variant: "soft", color: "destructive", className: "border-transparent bg-destructive/10 text-destructive [a&]:hover:bg-destructive/20" },
      { variant: "soft", color: "success", className: "border-transparent bg-success/10 text-success [a&]:hover:bg-success/20" },
      { variant: "soft", color: "warning", className: "border-transparent bg-warning/10 text-warning [a&]:hover:bg-warning/20" },
      { variant: "soft", color: "info", className: "border-transparent bg-info/10 text-info [a&]:hover:bg-info/20" }
    ],
    defaultVariants: {
      variant: "default",
      color: "primary"
    }
  }
);
function Badge({
  className,
  variant,
  color,
  asChild = false,
  ...props
}) {
  const Comp = asChild ? Slot : "span";
  return /* @__PURE__ */ jsx(
    Comp,
    {
      "data-slot": "badge",
      className: cn(badgeVariants({ variant, color }), className),
      ...props
    }
  );
}
function Breadcrumb({ ...props }) {
  return /* @__PURE__ */ jsx("nav", { "aria-label": "breadcrumb", "data-slot": "breadcrumb", ...props });
}
function BreadcrumbList({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "ol",
    {
      "data-slot": "breadcrumb-list",
      className: cn(
        "text-muted-foreground flex flex-wrap items-center gap-1.5 text-sm break-words sm:gap-2.5",
        className
      ),
      ...props
    }
  );
}
function BreadcrumbItem({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "li",
    {
      "data-slot": "breadcrumb-item",
      className: cn("inline-flex items-center gap-1.5", className),
      ...props
    }
  );
}
function BreadcrumbLink({
  asChild,
  className,
  ...props
}) {
  const Comp = asChild ? Slot : "a";
  return /* @__PURE__ */ jsx(
    Comp,
    {
      "data-slot": "breadcrumb-link",
      className: cn("hover:text-foreground transition-colors", className),
      ...props
    }
  );
}
function BreadcrumbPage({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "span",
    {
      "data-slot": "breadcrumb-page",
      role: "link",
      "aria-disabled": "true",
      "aria-current": "page",
      className: cn("text-foreground font-normal", className),
      ...props
    }
  );
}
function BreadcrumbSeparator({
  children,
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "li",
    {
      "data-slot": "breadcrumb-separator",
      role: "presentation",
      "aria-hidden": "true",
      className: cn("[&>svg]:size-3.5", className),
      ...props,
      children: children ?? /* @__PURE__ */ jsx(ChevronRight, {})
    }
  );
}
function BreadcrumbEllipsis({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    "span",
    {
      "data-slot": "breadcrumb-ellipsis",
      role: "presentation",
      "aria-hidden": "true",
      className: cn("flex size-9 items-center justify-center", className),
      ...props,
      children: [
        /* @__PURE__ */ jsx(MoreHorizontal, { className: "size-4" }),
        /* @__PURE__ */ jsx("span", { className: "sr-only", children: "More" })
      ]
    }
  );
}
function Calendar({
  className,
  classNames,
  showOutsideDays = true,
  ...props
}) {
  const defaultClassNames = getDefaultClassNames();
  return /* @__PURE__ */ jsx(
    DayPicker,
    {
      showOutsideDays,
      className: cn("p-3", className),
      classNames: {
        root: cn("w-fit", defaultClassNames.root),
        months: cn("flex flex-col sm:flex-row gap-2", defaultClassNames.months),
        month: cn("flex flex-col gap-4 w-full", defaultClassNames.month),
        month_caption: cn(
          "flex justify-center pt-1 relative items-center w-full",
          defaultClassNames.month_caption
        ),
        caption_label: cn("text-sm font-medium select-none", defaultClassNames.caption_label),
        dropdowns: cn(
          "flex items-center text-sm font-medium justify-center gap-1.5",
          defaultClassNames.dropdowns
        ),
        dropdown_root: cn(
          "relative border border-input rounded-md",
          defaultClassNames.dropdown_root
        ),
        dropdown: cn("absolute inset-0 opacity-0", defaultClassNames.dropdown),
        nav: cn(
          "flex items-center gap-1 w-full absolute top-0 inset-x-0 justify-between",
          defaultClassNames.nav
        ),
        button_previous: cn(
          buttonVariants({ variant: "outline" }),
          "size-7 bg-transparent p-0 opacity-50 hover:opacity-100 select-none",
          defaultClassNames.button_previous
        ),
        button_next: cn(
          buttonVariants({ variant: "outline" }),
          "size-7 bg-transparent p-0 opacity-50 hover:opacity-100 select-none",
          defaultClassNames.button_next
        ),
        table: "w-full border-collapse",
        weekdays: cn("flex", defaultClassNames.weekdays),
        weekday: cn(
          "text-muted-foreground rounded-md w-8 font-normal text-[0.8rem] select-none",
          defaultClassNames.weekday
        ),
        week: cn("flex w-full mt-2", defaultClassNames.week),
        day: cn(
          "relative p-0 text-center text-sm focus-within:relative focus-within:z-20 select-none",
          "[&:has([aria-selected])]:bg-accent",
          "[&:has([aria-selected].rdp-day_range_end)]:rounded-r-md",
          props.mode === "range" ? "[&:has(>.rdp-day_range_end)]:rounded-r-md [&:has(>.rdp-day_range_start)]:rounded-l-md first:[&:has([aria-selected])]:rounded-l-md last:[&:has([aria-selected])]:rounded-r-md" : "[&:has([aria-selected])]:rounded-md",
          defaultClassNames.day
        ),
        day_button: cn(
          buttonVariants({ variant: "ghost" }),
          "size-8 p-0 font-normal aria-selected:opacity-100"
        ),
        range_start: "rdp-day_range_start aria-selected:bg-primary aria-selected:text-primary-foreground rounded-l-md",
        range_end: "rdp-day_range_end aria-selected:bg-primary aria-selected:text-primary-foreground rounded-r-md",
        selected: "bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground focus:bg-primary focus:text-primary-foreground rounded-md",
        today: "bg-accent text-accent-foreground rounded-md",
        outside: "text-muted-foreground aria-selected:text-muted-foreground",
        disabled: "text-muted-foreground opacity-50",
        range_middle: "aria-selected:bg-accent aria-selected:text-accent-foreground rounded-none",
        hidden: "invisible",
        ...classNames
      },
      components: {
        Chevron: ({ className: className2, orientation, ...chevronProps }) => {
          const Icon2 = orientation === "left" ? ChevronLeftIcon : orientation === "right" ? ChevronRightIcon : ChevronDownIcon;
          return /* @__PURE__ */ jsx(Icon2, { className: cn("size-4", className2), ...chevronProps });
        }
      },
      ...props
    }
  );
}
function Card({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "card",
      className: cn(
        "bg-card text-card-foreground flex flex-col gap-card rounded-lg border",
        className
      ),
      ...props
    }
  );
}
function CardHeader({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "card-header",
      className: cn(
        "@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-card pt-card has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-card",
        className
      ),
      ...props
    }
  );
}
function CardTitle({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "h4",
    {
      "data-slot": "card-title",
      className: cn("leading-none", className),
      ...props
    }
  );
}
function CardDescription({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "p",
    {
      "data-slot": "card-description",
      className: cn("text-muted-foreground", className),
      ...props
    }
  );
}
function CardAction({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "card-action",
      className: cn(
        "col-start-2 row-span-2 row-start-1 self-start justify-self-end",
        className
      ),
      ...props
    }
  );
}
function CardContent({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "card-content",
      className: cn("px-card [&:last-child]:pb-card", className),
      ...props
    }
  );
}
function CardMedia({
  className,
  aspectRatio,
  children,
  ...props
}) {
  const aspectClass = aspectRatio ? aspectRatio === "square" ? "aspect-square" : `aspect-[${aspectRatio}]` : void 0;
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "card-media",
      className: cn(
        "relative overflow-hidden bg-muted",
        // Round top corners when this is the first child of the Card
        "[&:first-child]:rounded-t-lg",
        // Round bottom corners when this is the last child of the Card
        "[&:last-child]:rounded-b-lg",
        // Drop the parent flex-col gap-card spacing when CardMedia is followed
        // by another Card sub-component (image flush against the next slot).
        "[&:not(:last-child)]:-mb-card",
        aspectClass,
        className
      ),
      ...props,
      children
    }
  );
}
function CardFooter({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "card-footer",
      className: cn("flex items-center px-card pb-card [.border-t]:pt-card", className),
      ...props
    }
  );
}
var CarouselContext = React2.createContext(null);
function useCarousel() {
  const context = React2.useContext(CarouselContext);
  if (!context) {
    throw new Error("useCarousel must be used within a <Carousel />");
  }
  return context;
}
function Carousel({
  orientation = "horizontal",
  opts,
  setApi,
  plugins,
  className,
  children,
  ...props
}) {
  const [carouselRef, api] = useEmblaCarousel(
    {
      ...opts,
      axis: orientation === "horizontal" ? "x" : "y"
    },
    plugins
  );
  const [canScrollPrev, setCanScrollPrev] = React2.useState(false);
  const [canScrollNext, setCanScrollNext] = React2.useState(false);
  const onSelect = React2.useCallback((api2) => {
    if (!api2) return;
    setCanScrollPrev(api2.canScrollPrev());
    setCanScrollNext(api2.canScrollNext());
  }, []);
  const scrollPrev = React2.useCallback(() => {
    api?.scrollPrev();
  }, [api]);
  const scrollNext = React2.useCallback(() => {
    api?.scrollNext();
  }, [api]);
  const handleKeyDown = React2.useCallback(
    (event) => {
      if (event.key === "ArrowLeft") {
        event.preventDefault();
        scrollPrev();
      } else if (event.key === "ArrowRight") {
        event.preventDefault();
        scrollNext();
      }
    },
    [scrollPrev, scrollNext]
  );
  React2.useEffect(() => {
    if (!api || !setApi) return;
    setApi(api);
  }, [api, setApi]);
  React2.useEffect(() => {
    if (!api) return;
    onSelect(api);
    api.on("reInit", onSelect);
    api.on("select", onSelect);
    return () => {
      api?.off("select", onSelect);
    };
  }, [api, onSelect]);
  return /* @__PURE__ */ jsx(
    CarouselContext.Provider,
    {
      value: {
        carouselRef,
        api,
        opts,
        orientation: orientation || (opts?.axis === "y" ? "vertical" : "horizontal"),
        scrollPrev,
        scrollNext,
        canScrollPrev,
        canScrollNext
      },
      children: /* @__PURE__ */ jsx(
        "div",
        {
          onKeyDownCapture: handleKeyDown,
          className: cn("relative", className),
          role: "region",
          "aria-roledescription": "carousel",
          "data-slot": "carousel",
          ...props,
          children
        }
      )
    }
  );
}
function CarouselContent({ className, ...props }) {
  const { carouselRef, orientation } = useCarousel();
  return /* @__PURE__ */ jsx(
    "div",
    {
      ref: carouselRef,
      className: "overflow-hidden",
      "data-slot": "carousel-content",
      children: /* @__PURE__ */ jsx(
        "div",
        {
          className: cn(
            "flex",
            orientation === "horizontal" ? "-ml-4" : "-mt-4 flex-col",
            className
          ),
          ...props
        }
      )
    }
  );
}
function CarouselItem({ className, ...props }) {
  const { orientation } = useCarousel();
  return /* @__PURE__ */ jsx(
    "div",
    {
      role: "group",
      "aria-roledescription": "slide",
      "data-slot": "carousel-item",
      className: cn(
        "min-w-0 shrink-0 grow-0 basis-full",
        orientation === "horizontal" ? "pl-4" : "pt-4",
        className
      ),
      ...props
    }
  );
}
function CarouselPrevious({
  className,
  variant = "outline",
  size = "icon",
  ...props
}) {
  const { orientation, scrollPrev, canScrollPrev } = useCarousel();
  return /* @__PURE__ */ jsxs(
    Button,
    {
      "data-slot": "carousel-previous",
      variant,
      size,
      className: cn(
        "absolute size-8 rounded-full",
        orientation === "horizontal" ? "top-1/2 -left-12 -translate-y-1/2" : "-top-12 left-1/2 -translate-x-1/2 rotate-90",
        className
      ),
      disabled: !canScrollPrev,
      onClick: scrollPrev,
      ...props,
      children: [
        /* @__PURE__ */ jsx(ArrowLeft, {}),
        /* @__PURE__ */ jsx("span", { className: "sr-only", children: "Previous slide" })
      ]
    }
  );
}
function CarouselNext({
  className,
  variant = "outline",
  size = "icon",
  ...props
}) {
  const { orientation, scrollNext, canScrollNext } = useCarousel();
  return /* @__PURE__ */ jsxs(
    Button,
    {
      "data-slot": "carousel-next",
      variant,
      size,
      className: cn(
        "absolute size-8 rounded-full",
        orientation === "horizontal" ? "top-1/2 -right-12 -translate-y-1/2" : "-bottom-12 left-1/2 -translate-x-1/2 rotate-90",
        className
      ),
      disabled: !canScrollNext,
      onClick: scrollNext,
      ...props,
      children: [
        /* @__PURE__ */ jsx(ArrowRight, {}),
        /* @__PURE__ */ jsx("span", { className: "sr-only", children: "Next slide" })
      ]
    }
  );
}
var Checkbox = React2.forwardRef(({ className, ...props }, ref) => {
  return /* @__PURE__ */ jsx(
    CheckboxPrimitive.Root,
    {
      ref,
      "data-slot": "checkbox",
      className: cn(
        "peer border bg-input-background dark:bg-input/30 data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground dark:data-[state=checked]:bg-primary data-[state=checked]:border-primary focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive size-4 shrink-0 rounded-[4px] border shadow-xs transition-shadow outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50",
        className
      ),
      ...props,
      children: /* @__PURE__ */ jsx(
        CheckboxPrimitive.Indicator,
        {
          "data-slot": "checkbox-indicator",
          className: "flex items-center justify-center text-current transition-none",
          children: /* @__PURE__ */ jsx(CheckIcon, { className: "size-3.5" })
        }
      )
    }
  );
});
Checkbox.displayName = CheckboxPrimitive.Root.displayName;
function Collapsible({
  ...props
}) {
  return /* @__PURE__ */ jsx(CollapsiblePrimitive.Root, { "data-slot": "collapsible", ...props });
}
function CollapsibleTrigger2({
  ...props
}) {
  return /* @__PURE__ */ jsx(
    CollapsiblePrimitive.CollapsibleTrigger,
    {
      "data-slot": "collapsible-trigger",
      ...props
    }
  );
}
function CollapsibleContent2({
  ...props
}) {
  return /* @__PURE__ */ jsx(
    CollapsiblePrimitive.CollapsibleContent,
    {
      "data-slot": "collapsible-content",
      ...props
    }
  );
}
function Popover({
  ...props
}) {
  return /* @__PURE__ */ jsx(PopoverPrimitive.Root, { "data-slot": "popover", ...props });
}
function PopoverTrigger({
  ...props
}) {
  return /* @__PURE__ */ jsx(PopoverPrimitive.Trigger, { "data-slot": "popover-trigger", ...props });
}
function PopoverContent({
  className,
  align = "center",
  sideOffset = 4,
  ...props
}) {
  return /* @__PURE__ */ jsx(PopoverPrimitive.Portal, { children: /* @__PURE__ */ jsx(
    PopoverPrimitive.Content,
    {
      "data-slot": "popover-content",
      align,
      sideOffset,
      className: cn(
        "bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 w-72 origin-(--radix-popover-content-transform-origin) rounded-md border p-[var(--density-popover)] shadow-md outline-hidden",
        className
      ),
      ...props
    }
  ) });
}
function PopoverAnchor({
  ...props
}) {
  return /* @__PURE__ */ jsx(PopoverPrimitive.Anchor, { "data-slot": "popover-anchor", ...props });
}
var MAX_INLINE_TABS = 3;
function TranslatableField({
  config,
  value,
  onChange,
  children,
  className,
  errors
}) {
  const [activeLocale, setActiveLocale] = useState(config.defaultLocale);
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const dropdownRef = useRef(null);
  useEffect(() => {
    if (!dropdownOpen) return;
    const handler = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
        setDropdownOpen(false);
      }
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [dropdownOpen]);
  const isFallback = activeLocale !== config.fallbackLocale;
  const fallbackPlaceholder = isFallback ? value[config.fallbackLocale] ?? void 0 : void 0;
  const hasError = !!errors?.[activeLocale];
  const handleChange = (v) => {
    onChange({ ...value, [activeLocale]: v });
  };
  const localeEntries = Object.entries(config.locales);
  let visibleEntries;
  let overflowEntries;
  if (localeEntries.length <= MAX_INLINE_TABS) {
    visibleEntries = localeEntries;
    overflowEntries = [];
  } else {
    const nonActive = localeEntries.filter(([code]) => code !== activeLocale);
    const visibleNonActive = nonActive.slice(0, MAX_INLINE_TABS - 1);
    const visibleCodes = /* @__PURE__ */ new Set([...visibleNonActive.map(([c]) => c), activeLocale]);
    visibleEntries = localeEntries.filter(([code]) => visibleCodes.has(code));
    overflowEntries = localeEntries.filter(([code]) => !visibleCodes.has(code));
  }
  const activeInOverflow = overflowEntries.some(([code]) => code === activeLocale);
  const overflowHasValue = overflowEntries.some(([code]) => !!(value[code] ?? ""));
  const overflowHasError = overflowEntries.some(([code]) => !!errors?.[code]);
  return /* @__PURE__ */ jsxs("div", { className: cn("flex flex-col gap-1", className), children: [
    /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-0.5", children: [
      visibleEntries.map(([code, label]) => {
        const isActive = code === activeLocale;
        const hasValue = !!(value[code] ?? "");
        const hasLocaleError = !!errors?.[code];
        return /* @__PURE__ */ jsxs(
          "button",
          {
            type: "button",
            title: label,
            onClick: () => setActiveLocale(code),
            className: cn(
              "relative px-2 py-0.5 rounded text-xs font-medium transition-colors select-none",
              isActive ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground hover:bg-muted"
            ),
            children: [
              code.toUpperCase(),
              (hasValue || hasLocaleError) && !isActive && /* @__PURE__ */ jsx("span", { className: cn(
                "absolute -top-0.5 -right-0.5 w-1.5 h-1.5 rounded-full",
                hasLocaleError ? "bg-destructive" : "bg-primary"
              ) })
            ]
          },
          code
        );
      }),
      overflowEntries.length > 0 && /* @__PURE__ */ jsxs("div", { ref: dropdownRef, className: "relative", children: [
        /* @__PURE__ */ jsxs(
          "button",
          {
            type: "button",
            title: "More languages",
            onClick: () => setDropdownOpen((o) => !o),
            className: cn(
              "relative flex items-center gap-0.5 px-1.5 py-0.5 rounded text-xs font-medium transition-colors select-none",
              activeInOverflow ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground hover:bg-muted"
            ),
            children: [
              activeInOverflow ? activeLocale.toUpperCase() : `+${overflowEntries.length}`,
              /* @__PURE__ */ jsx(ChevronDown, { className: cn("w-3 h-3 transition-transform", dropdownOpen && "rotate-180") }),
              (overflowHasValue || overflowHasError) && !activeInOverflow && /* @__PURE__ */ jsx("span", { className: cn(
                "absolute -top-0.5 -right-0.5 w-1.5 h-1.5 rounded-full",
                overflowHasError ? "bg-destructive" : "bg-primary"
              ) })
            ]
          }
        ),
        dropdownOpen && /* @__PURE__ */ jsx("div", { className: "absolute top-full left-0 mt-1 z-10 min-w-[140px] rounded-md border border-border bg-popover shadow-md py-1", children: overflowEntries.map(([code, label]) => {
          const isActive = code === activeLocale;
          const hasValue = !!(value[code] ?? "");
          const hasLocaleError = !!errors?.[code];
          return /* @__PURE__ */ jsxs(
            "button",
            {
              type: "button",
              onClick: () => {
                setActiveLocale(code);
                setDropdownOpen(false);
              },
              className: cn(
                "w-full flex items-center gap-2 px-3 py-1.5 text-sm transition-colors",
                isActive ? "bg-primary/10 text-primary font-medium" : "hover:bg-accent text-foreground"
              ),
              children: [
                /* @__PURE__ */ jsx("span", { className: "text-xs font-semibold w-6 shrink-0", children: code.toUpperCase() }),
                /* @__PURE__ */ jsx("span", { className: "text-xs text-muted-foreground flex-1 text-left", children: label }),
                (hasValue || hasLocaleError) && /* @__PURE__ */ jsx("span", { className: cn(
                  "w-1.5 h-1.5 rounded-full shrink-0",
                  hasLocaleError ? "bg-destructive" : "bg-primary"
                ) })
              ]
            },
            code
          );
        }) })
      ] }),
      fallbackPlaceholder && !value[activeLocale] && /* @__PURE__ */ jsxs("span", { "data-testid": "fallback-hint", className: "ml-1 flex items-center gap-0.5 text-xs text-muted-foreground", children: [
        /* @__PURE__ */ jsx(CornerDownLeft, { className: "w-3 h-3" }),
        config.fallbackLocale.toUpperCase()
      ] })
    ] }),
    children({
      locale: activeLocale,
      value: value[activeLocale] ?? "",
      onChange: handleChange,
      fallbackPlaceholder,
      hasError
    })
  ] });
}
var UIContext = createContext(void 0);

// src/internal/ui-hooks.ts
function useTheme() {
  const ctx = useContext(UIContext);
  if (!ctx) throw new Error("useTheme must be used within UIProvider");
  return { theme: ctx.theme, setTheme: ctx.setTheme };
}
function useUILocales() {
  return useContext(UIContext)?.locale;
}
function useLocale() {
  const ctx = useContext(UIContext);
  if (!ctx) throw new Error("useLocale must be used within UIProvider");
  const config = ctx.locale ?? { locales: {}, defaultLocale: "", fallbackLocale: "" };
  return {
    currentLocale: ctx.currentLocale,
    setLocale: ctx.setLocale,
    locales: config.locales,
    defaultLocale: config.defaultLocale,
    fallbackLocale: config.fallbackLocale
  };
}
function useTimezone() {
  const ctx = useContext(UIContext);
  if (!ctx) throw new Error("useTimezone must be used within UIProvider");
  return { timezone: ctx.timezone, setTimezone: ctx.setTimezone };
}
function useDateFnsLocale() {
  return useContext(UIContext)?.dateFnsLocale;
}
function resolveTranslatableConfig(translatable, providerLocales) {
  if (translatable === true) {
    return providerLocales;
  }
  const base = providerLocales ?? { locales: {}, defaultLocale: "", fallbackLocale: "" };
  const merged = {
    locales: translatable.locales ?? base.locales,
    defaultLocale: translatable.defaultLocale ?? base.defaultLocale,
    fallbackLocale: translatable.fallbackLocale ?? base.fallbackLocale
  };
  return Object.keys(merged.locales).length > 0 ? merged : void 0;
}
var inputVariants = cva(
  "file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input flex w-full min-w-0 rounded-md border bg-input-background transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      size: {
        xs: "h-element-xs px-2 text-xs",
        sm: "h-element-sm px-2.5 text-sm",
        default: "h-element px-3 py-1 text-base md:text-sm",
        lg: "h-element-lg px-4 text-sm",
        xl: "h-element-xl px-4 text-base"
      }
    },
    defaultVariants: {
      size: "default"
    }
  }
);
var Input = React2.forwardRef(
  (props, ref) => {
    const { className, type, size, translatable, ...rest } = props;
    const providerLocales = useUILocales();
    if (translatable !== void 0) {
      const config = resolveTranslatableConfig(translatable, providerLocales);
      if (!config) {
        const { value: _v, onChange: _oc, ...inputRest3 } = rest;
        return /* @__PURE__ */ jsx(
          "input",
          {
            type,
            ref,
            "data-slot": "input",
            className: cn(inputVariants({ size, className })),
            ...inputRest3
          }
        );
      }
      const { value: value2 = {}, onChange: onChange2, errors, ...inputRest2 } = rest;
      return /* @__PURE__ */ jsx(
        TranslatableField,
        {
          config,
          value: value2,
          onChange: onChange2 ?? (() => {
          }),
          errors,
          children: ({ value: localeValue, onChange: localeChange, fallbackPlaceholder, hasError }) => /* @__PURE__ */ jsx(
            "input",
            {
              type,
              ref,
              "data-slot": "input",
              "data-translatable": true,
              className: cn(inputVariants({ size, className })),
              value: localeValue,
              placeholder: fallbackPlaceholder ?? inputRest2.placeholder,
              onChange: (e) => localeChange(e.target.value),
              ...inputRest2,
              "aria-invalid": hasError || inputRest2["aria-invalid"] || void 0
            }
          )
        }
      );
    }
    const { value, onChange, error, ...inputRest } = rest;
    const ariaInvalid = error !== void 0 && error !== "" ? true : inputRest["aria-invalid"];
    return /* @__PURE__ */ jsxs(Fragment, { children: [
      /* @__PURE__ */ jsx(
        "input",
        {
          type,
          ref,
          "data-slot": "input",
          className: cn(inputVariants({ size, className })),
          value,
          onChange,
          "aria-invalid": ariaInvalid,
          ...inputRest
        }
      ),
      error ? /* @__PURE__ */ jsx("p", { "data-slot": "input-error", className: "mt-1 text-sm text-destructive", children: error }) : null
    ] });
  }
);
Input.displayName = "Input";
var PRESET_COLORS = [
  "#EF4444",
  // Red
  "#F97316",
  // Orange
  "#F59E0B",
  // Amber
  "#EAB308",
  // Yellow
  "#84CC16",
  // Lime
  "#22C55E",
  // Green
  "#10B981",
  // Emerald
  "#14B8A6",
  // Teal
  "#06B6D4",
  // Cyan
  "#0EA5E9",
  // Sky
  "#3B82F6",
  // Blue
  "#6366F1",
  // Indigo
  "#8B5CF6",
  // Purple
  "#A855F7",
  // Violet
  "#D946EF",
  // Fuchsia
  "#EC4899",
  // Pink
  "#F43F5E",
  // Rose
  "#64748B",
  // Slate
  "#6B7280",
  // Gray
  "#000000"
  // Black
];
function ColorPicker({
  value = "#3B82F6",
  onChange,
  className,
  disabled,
  showPresets = true,
  showInput = true
}) {
  const [customColor, setCustomColor] = React2.useState(value);
  const handleColorChange = (color) => {
    setCustomColor(color);
    onChange?.(color);
  };
  return /* @__PURE__ */ jsxs(Popover, { children: [
    /* @__PURE__ */ jsx(PopoverTrigger, { asChild: true, children: /* @__PURE__ */ jsxs(
      Button,
      {
        variant: "outline",
        disabled,
        className: cn(
          "w-full justify-start gap-2",
          className
        ),
        children: [
          /* @__PURE__ */ jsx(
            "div",
            {
              className: "h-4 w-4 rounded border border-border",
              style: { backgroundColor: value }
            }
          ),
          /* @__PURE__ */ jsx("span", { className: "flex-1 text-left", children: value })
        ]
      }
    ) }),
    /* @__PURE__ */ jsx(PopoverContent, { className: "w-64 p-3", align: "start", children: /* @__PURE__ */ jsxs("div", { className: "space-y-3", children: [
      showPresets && /* @__PURE__ */ jsxs("div", { children: [
        /* @__PURE__ */ jsx("div", { className: "text-xs font-medium mb-2 text-foreground", children: "M\xE0u m\u1EB7c \u0111\u1ECBnh" }),
        /* @__PURE__ */ jsx("div", { className: "grid grid-cols-10 gap-1.5", children: PRESET_COLORS.map((color) => /* @__PURE__ */ jsx(
          "button",
          {
            type: "button",
            className: cn(
              "h-6 w-6 rounded border-2 transition-all hover:scale-110",
              value === color ? "border-foreground ring-2 ring-foreground ring-offset-1" : "border-border"
            ),
            style: { backgroundColor: color },
            onClick: () => handleColorChange(color),
            children: value === color && /* @__PURE__ */ jsx(Check, { className: "w-3 h-3 text-white mx-auto drop-shadow" })
          },
          color
        )) })
      ] }),
      showInput && /* @__PURE__ */ jsxs("div", { children: [
        /* @__PURE__ */ jsx("div", { className: "text-xs font-medium mb-2 text-foreground", children: "M\xE0u t\xF9y ch\u1EC9nh" }),
        /* @__PURE__ */ jsx("div", { className: "flex gap-2", children: /* @__PURE__ */ jsxs("div", { className: "relative flex-1", children: [
          /* @__PURE__ */ jsx(
            Input,
            {
              value: customColor,
              onChange: (e) => setCustomColor(e.target.value),
              onBlur: () => {
                if (/^#[0-9A-F]{6}$/i.test(customColor)) {
                  handleColorChange(customColor);
                } else {
                  setCustomColor(value);
                }
              },
              placeholder: "#000000",
              className: "pr-10"
            }
          ),
          /* @__PURE__ */ jsx(
            "input",
            {
              type: "color",
              value: customColor,
              onChange: (e) => {
                setCustomColor(e.target.value);
                handleColorChange(e.target.value);
              },
              className: "absolute right-2 top-1/2 -translate-y-1/2 h-6 w-6 rounded border border-border cursor-pointer"
            }
          )
        ] }) })
      ] })
    ] }) })
  ] });
}
function Dialog({
  ...props
}) {
  return /* @__PURE__ */ jsx(DialogPrimitive.Root, { "data-slot": "dialog", ...props });
}
function DialogTrigger({
  ...props
}) {
  return /* @__PURE__ */ jsx(DialogPrimitive.Trigger, { "data-slot": "dialog-trigger", ...props });
}
function DialogPortal({
  ...props
}) {
  return /* @__PURE__ */ jsx(DialogPrimitive.Portal, { "data-slot": "dialog-portal", ...props });
}
function DialogClose({
  ...props
}) {
  return /* @__PURE__ */ jsx(DialogPrimitive.Close, { "data-slot": "dialog-close", ...props });
}
var DialogOverlay = React2.forwardRef(({ className, ...props }, ref) => {
  return /* @__PURE__ */ jsx(
    DialogPrimitive.Overlay,
    {
      ref,
      "data-slot": "dialog-overlay",
      className: cn(
        "data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/50",
        className
      ),
      ...props
    }
  );
});
DialogOverlay.displayName = DialogPrimitive.Overlay.displayName;
var DialogContent = React2.forwardRef(({ className, children, ...props }, ref) => {
  return /* @__PURE__ */ jsxs(DialogPortal, { "data-slot": "dialog-portal", children: [
    /* @__PURE__ */ jsx(DialogOverlay, {}),
    /* @__PURE__ */ jsxs(
      DialogPrimitive.Content,
      {
        ref,
        "data-slot": "dialog-content",
        className: cn(
          "bg-background data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-[50%] left-[50%] z-50 grid w-full max-w-[calc(100%-2rem)] translate-x-[-50%] translate-y-[-50%] gap-4 rounded-lg border p-dialog shadow-lg duration-200 sm:max-w-lg",
          className
        ),
        ...props,
        children: [
          children,
          /* @__PURE__ */ jsxs(DialogPrimitive.Close, { className: "ring-offset-background focus:ring-ring data-[state=open]:bg-accent data-[state=open]:text-muted-foreground absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4", children: [
            /* @__PURE__ */ jsx(XIcon, {}),
            /* @__PURE__ */ jsx("span", { className: "sr-only", children: "Close" })
          ] })
        ]
      }
    )
  ] });
});
DialogContent.displayName = DialogPrimitive.Content.displayName;
function DialogHeader({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "dialog-header",
      className: cn("flex flex-col gap-2 text-center sm:text-left", className),
      ...props
    }
  );
}
function DialogFooter({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "dialog-footer",
      className: cn(
        "flex flex-col-reverse gap-2 sm:flex-row sm:justify-end",
        className
      ),
      ...props
    }
  );
}
function DialogTitle({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    DialogPrimitive.Title,
    {
      "data-slot": "dialog-title",
      className: cn("text-lg leading-none font-semibold", className),
      ...props
    }
  );
}
function DialogDescription({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    DialogPrimitive.Description,
    {
      "data-slot": "dialog-description",
      className: cn("text-muted-foreground text-sm", className),
      ...props
    }
  );
}
function Command({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    Command$1,
    {
      "data-slot": "command",
      className: cn(
        "bg-popover text-popover-foreground flex h-full w-full flex-col overflow-hidden rounded-md",
        className
      ),
      ...props
    }
  );
}
function CommandDialog({
  title = "Command Palette",
  description = "Search for a command to run...",
  children,
  ...props
}) {
  return /* @__PURE__ */ jsxs(Dialog, { ...props, children: [
    /* @__PURE__ */ jsxs(DialogHeader, { className: "sr-only", children: [
      /* @__PURE__ */ jsx(DialogTitle, { children: title }),
      /* @__PURE__ */ jsx(DialogDescription, { children: description })
    ] }),
    /* @__PURE__ */ jsx(DialogContent, { className: "overflow-hidden p-0", children: /* @__PURE__ */ jsx(Command, { className: "[&_[cmdk-group-heading]]:text-muted-foreground **:data-[slot=command-input-wrapper]:h-12 [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group]]:px-2 [&_[cmdk-group]:not([hidden])_~[cmdk-group]]:pt-0 [&_[cmdk-input-wrapper]_svg]:h-5 [&_[cmdk-input-wrapper]_svg]:w-5 [&_[cmdk-input]]:h-12 [&_[cmdk-item]]:px-2 [&_[cmdk-item]]:py-3 [&_[cmdk-item]_svg]:h-5 [&_[cmdk-item]_svg]:w-5", children }) })
  ] });
}
function CommandInput({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    "div",
    {
      "data-slot": "command-input-wrapper",
      className: "flex h-9 items-center gap-2 border-b px-3",
      children: [
        /* @__PURE__ */ jsx(SearchIcon, { className: "size-4 shrink-0 opacity-50" }),
        /* @__PURE__ */ jsx(
          Command$1.Input,
          {
            "data-slot": "command-input",
            className: cn(
              "placeholder:text-muted-foreground flex h-10 w-full rounded-md bg-transparent py-3 text-sm outline-hidden disabled:cursor-not-allowed disabled:opacity-50",
              className
            ),
            ...props
          }
        )
      ]
    }
  );
}
function CommandList({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    Command$1.List,
    {
      "data-slot": "command-list",
      className: cn(
        "max-h-[300px] scroll-py-1 overflow-x-hidden overflow-y-auto",
        className
      ),
      ...props
    }
  );
}
function CommandEmpty({
  ...props
}) {
  return /* @__PURE__ */ jsx(
    Command$1.Empty,
    {
      "data-slot": "command-empty",
      className: "py-6 text-center text-sm",
      ...props
    }
  );
}
function CommandGroup({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    Command$1.Group,
    {
      "data-slot": "command-group",
      className: cn(
        "text-foreground [&_[cmdk-group-heading]]:text-muted-foreground overflow-hidden p-1 [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-medium",
        className
      ),
      ...props
    }
  );
}
function CommandSeparator({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    Command$1.Separator,
    {
      "data-slot": "command-separator",
      className: cn("bg-border -mx-1 h-px", className),
      ...props
    }
  );
}
function CommandItem({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    Command$1.Item,
    {
      "data-slot": "command-item",
      className: cn(
        "data-[selected=true]:bg-accent data-[selected=true]:text-accent-foreground [&_svg:not([class*='text-'])]:text-muted-foreground relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none data-[disabled=true]:pointer-events-none data-[disabled=true]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      ...props
    }
  );
}
function CommandShortcut({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "span",
    {
      "data-slot": "command-shortcut",
      className: cn(
        "text-muted-foreground ml-auto text-xs tracking-widest",
        className
      ),
      ...props
    }
  );
}
function Combobox({
  options,
  value,
  onChange,
  placeholder = "Ch\u1ECDn...",
  searchPlaceholder = "T\xECm ki\u1EBFm...",
  emptyText = "Kh\xF4ng t\xECm th\u1EA5y k\u1EBFt qu\u1EA3.",
  className,
  disabled,
  clearable = false,
  error
}) {
  const [open, setOpen] = React2.useState(false);
  const selectedOption = options.find((option) => option.value === value);
  const handleClear = (e) => {
    e.stopPropagation();
    onChange?.("");
  };
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsxs(Popover, { open, onOpenChange: setOpen, children: [
      /* @__PURE__ */ jsx(PopoverTrigger, { asChild: true, children: /* @__PURE__ */ jsxs(
        Button,
        {
          "data-slot": "combobox",
          variant: "outline",
          role: "combobox",
          "aria-expanded": open,
          "aria-invalid": error ? true : void 0,
          disabled,
          className: cn(
            "w-full justify-between",
            !value && "text-muted-foreground",
            className
          ),
          children: [
            /* @__PURE__ */ jsx("span", { className: "truncate", children: selectedOption ? selectedOption.label : placeholder }),
            /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-1 ml-2", children: [
              clearable && value && /* @__PURE__ */ jsx(
                X,
                {
                  className: "h-4 w-4 opacity-50 hover:opacity-100",
                  onClick: handleClear
                }
              ),
              /* @__PURE__ */ jsx(ChevronsUpDown, { className: "h-4 w-4 shrink-0 opacity-50" })
            ] })
          ]
        }
      ) }),
      /* @__PURE__ */ jsx(PopoverContent, { className: "w-[--radix-popover-trigger-width] p-0", align: "start", children: /* @__PURE__ */ jsxs(Command, { children: [
        /* @__PURE__ */ jsx(CommandInput, { placeholder: searchPlaceholder }),
        /* @__PURE__ */ jsxs(CommandList, { children: [
          /* @__PURE__ */ jsx(CommandEmpty, { children: emptyText }),
          /* @__PURE__ */ jsx(CommandGroup, { children: options.map((option) => /* @__PURE__ */ jsxs(
            CommandItem,
            {
              value: option.value,
              disabled: option.disabled,
              onSelect: (currentValue) => {
                onChange?.(currentValue === value ? "" : currentValue);
                setOpen(false);
              },
              children: [
                /* @__PURE__ */ jsx(
                  Check,
                  {
                    className: cn(
                      "mr-2 h-4 w-4",
                      value === option.value ? "opacity-100" : "opacity-0"
                    )
                  }
                ),
                option.label
              ]
            },
            option.value
          )) })
        ] })
      ] }) })
    ] }),
    error ? /* @__PURE__ */ jsx("p", { "data-slot": "combobox-error", className: "mt-1 text-sm text-destructive", children: error }) : null
  ] });
}
function MultiCombobox({
  options,
  value = [],
  onChange,
  placeholder = "Ch\u1ECDn...",
  searchPlaceholder = "T\xECm ki\u1EBFm...",
  emptyText = "Kh\xF4ng t\xECm th\u1EA5y k\u1EBFt qu\u1EA3.",
  className,
  disabled,
  maxSelected,
  error
}) {
  const [open, setOpen] = React2.useState(false);
  const selectedLabels = value.map((v) => options.find((opt) => opt.value === v)?.label).filter(Boolean);
  const handleSelect = (selectedValue) => {
    const newValue = value.includes(selectedValue) ? value.filter((v) => v !== selectedValue) : maxSelected && value.length >= maxSelected ? value : [...value, selectedValue];
    onChange?.(newValue);
  };
  const handleClearAll = (e) => {
    e.stopPropagation();
    onChange?.([]);
  };
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsxs(Popover, { open, onOpenChange: setOpen, children: [
      /* @__PURE__ */ jsx(PopoverTrigger, { asChild: true, children: /* @__PURE__ */ jsxs(
        Button,
        {
          "data-slot": "multi-combobox",
          variant: "outline",
          role: "combobox",
          "aria-expanded": open,
          "aria-invalid": error ? true : void 0,
          disabled,
          className: cn(
            "w-full justify-between",
            !value.length && "text-muted-foreground",
            className
          ),
          children: [
            /* @__PURE__ */ jsx("span", { className: "truncate", children: selectedLabels.length > 0 ? selectedLabels.length === 1 ? selectedLabels[0] : `${selectedLabels.length} m\u1EE5c \u0111\xE3 ch\u1ECDn` : placeholder }),
            /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-1 ml-2", children: [
              value.length > 0 && /* @__PURE__ */ jsx(
                X,
                {
                  className: "h-4 w-4 opacity-50 hover:opacity-100",
                  onClick: handleClearAll
                }
              ),
              /* @__PURE__ */ jsx(ChevronsUpDown, { className: "h-4 w-4 shrink-0 opacity-50" })
            ] })
          ]
        }
      ) }),
      /* @__PURE__ */ jsx(PopoverContent, { className: "w-[--radix-popover-trigger-width] p-0", align: "start", children: /* @__PURE__ */ jsxs(Command, { children: [
        /* @__PURE__ */ jsx(CommandInput, { placeholder: searchPlaceholder }),
        /* @__PURE__ */ jsxs(CommandList, { children: [
          /* @__PURE__ */ jsx(CommandEmpty, { children: emptyText }),
          /* @__PURE__ */ jsx(CommandGroup, { children: options.map((option) => {
            const isSelected = value.includes(option.value);
            const isDisabled = option.disabled || !isSelected && maxSelected && value.length >= maxSelected;
            return /* @__PURE__ */ jsxs(
              CommandItem,
              {
                value: option.value,
                disabled: !!isDisabled,
                onSelect: () => handleSelect(option.value),
                children: [
                  /* @__PURE__ */ jsx(
                    Check,
                    {
                      className: cn(
                        "mr-2 h-4 w-4",
                        isSelected ? "opacity-100" : "opacity-0"
                      )
                    }
                  ),
                  option.label
                ]
              },
              option.value
            );
          }) })
        ] })
      ] }) })
    ] }),
    error ? /* @__PURE__ */ jsx("p", { "data-slot": "multi-combobox-error", className: "mt-1 text-sm text-destructive", children: error }) : null
  ] });
}
function ContextMenu({
  ...props
}) {
  return /* @__PURE__ */ jsx(ContextMenuPrimitive.Root, { "data-slot": "context-menu", ...props });
}
function ContextMenuTrigger({
  ...props
}) {
  return /* @__PURE__ */ jsx(ContextMenuPrimitive.Trigger, { "data-slot": "context-menu-trigger", ...props });
}
function ContextMenuGroup({
  ...props
}) {
  return /* @__PURE__ */ jsx(ContextMenuPrimitive.Group, { "data-slot": "context-menu-group", ...props });
}
function ContextMenuPortal({
  ...props
}) {
  return /* @__PURE__ */ jsx(ContextMenuPrimitive.Portal, { "data-slot": "context-menu-portal", ...props });
}
function ContextMenuSub({
  ...props
}) {
  return /* @__PURE__ */ jsx(ContextMenuPrimitive.Sub, { "data-slot": "context-menu-sub", ...props });
}
function ContextMenuRadioGroup({
  ...props
}) {
  return /* @__PURE__ */ jsx(
    ContextMenuPrimitive.RadioGroup,
    {
      "data-slot": "context-menu-radio-group",
      ...props
    }
  );
}
function ContextMenuSubTrigger({
  className,
  inset,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    ContextMenuPrimitive.SubTrigger,
    {
      "data-slot": "context-menu-sub-trigger",
      "data-inset": inset,
      className: cn(
        "focus:bg-accent focus:text-accent-foreground data-[state=open]:bg-accent data-[state=open]:text-accent-foreground flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-hidden select-none data-[inset]:pl-8 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      ...props,
      children: [
        children,
        /* @__PURE__ */ jsx(ChevronRightIcon, { className: "ml-auto" })
      ]
    }
  );
}
function ContextMenuSubContent({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    ContextMenuPrimitive.SubContent,
    {
      "data-slot": "context-menu-sub-content",
      className: cn(
        "bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 min-w-[8rem] origin-(--radix-context-menu-content-transform-origin) overflow-hidden rounded-md border p-1 shadow-lg",
        className
      ),
      ...props
    }
  );
}
function ContextMenuContent({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(ContextMenuPrimitive.Portal, { children: /* @__PURE__ */ jsx(
    ContextMenuPrimitive.Content,
    {
      "data-slot": "context-menu-content",
      className: cn(
        "bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 max-h-(--radix-context-menu-content-available-height) min-w-[8rem] origin-(--radix-context-menu-content-transform-origin) overflow-x-hidden overflow-y-auto rounded-md border p-1 shadow-md",
        className
      ),
      ...props
    }
  ) });
}
function ContextMenuItem({
  className,
  inset,
  variant = "default",
  ...props
}) {
  return /* @__PURE__ */ jsx(
    ContextMenuPrimitive.Item,
    {
      "data-slot": "context-menu-item",
      "data-inset": inset,
      "data-variant": variant,
      className: cn(
        "focus:bg-accent focus:text-accent-foreground data-[variant=destructive]:text-destructive data-[variant=destructive]:focus:bg-destructive/10 dark:data-[variant=destructive]:focus:bg-destructive/20 data-[variant=destructive]:focus:text-destructive data-[variant=destructive]:*:[svg]:!text-destructive [&_svg:not([class*='text-'])]:text-muted-foreground relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[inset]:pl-8 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      ...props
    }
  );
}
function ContextMenuCheckboxItem({
  className,
  children,
  checked,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    ContextMenuPrimitive.CheckboxItem,
    {
      "data-slot": "context-menu-checkbox-item",
      className: cn(
        "focus:bg-accent focus:text-accent-foreground relative flex cursor-default items-center gap-2 rounded-sm py-1.5 pr-2 pl-8 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      checked,
      ...props,
      children: [
        /* @__PURE__ */ jsx("span", { className: "pointer-events-none absolute left-2 flex size-3.5 items-center justify-center", children: /* @__PURE__ */ jsx(ContextMenuPrimitive.ItemIndicator, { children: /* @__PURE__ */ jsx(CheckIcon, { className: "size-4" }) }) }),
        children
      ]
    }
  );
}
function ContextMenuRadioItem({
  className,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    ContextMenuPrimitive.RadioItem,
    {
      "data-slot": "context-menu-radio-item",
      className: cn(
        "focus:bg-accent focus:text-accent-foreground relative flex cursor-default items-center gap-2 rounded-sm py-1.5 pr-2 pl-8 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      ...props,
      children: [
        /* @__PURE__ */ jsx("span", { className: "pointer-events-none absolute left-2 flex size-3.5 items-center justify-center", children: /* @__PURE__ */ jsx(ContextMenuPrimitive.ItemIndicator, { children: /* @__PURE__ */ jsx(CircleIcon, { className: "size-2 fill-current" }) }) }),
        children
      ]
    }
  );
}
function ContextMenuLabel({
  className,
  inset,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    ContextMenuPrimitive.Label,
    {
      "data-slot": "context-menu-label",
      "data-inset": inset,
      className: cn(
        "text-foreground px-2 py-1.5 text-sm font-medium data-[inset]:pl-8",
        className
      ),
      ...props
    }
  );
}
function ContextMenuSeparator({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    ContextMenuPrimitive.Separator,
    {
      "data-slot": "context-menu-separator",
      className: cn("bg-border -mx-1 my-1 h-px", className),
      ...props
    }
  );
}
function ContextMenuShortcut({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "span",
    {
      "data-slot": "context-menu-shortcut",
      className: cn(
        "text-muted-foreground ml-auto text-xs tracking-widest",
        className
      ),
      ...props
    }
  );
}

// src/internal/timezone.ts
function toZonedTime(date, timeZone) {
  return new Date(date.toLocaleString("en-US", { timeZone }));
}
function DatePicker({
  value,
  onChange,
  placeholder = "Select date",
  className,
  disabled,
  locale: localeProp,
  error
}) {
  const contextLocale = useDateFnsLocale();
  const locale = localeProp ?? contextLocale;
  const { timezone } = useTimezone();
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsxs(Popover, { children: [
      /* @__PURE__ */ jsx(PopoverTrigger, { asChild: true, children: /* @__PURE__ */ jsxs(
        Button,
        {
          variant: "outline",
          disabled,
          "aria-invalid": error ? true : void 0,
          className: cn(
            "w-full justify-start text-left font-normal",
            !value && "text-muted-foreground",
            className
          ),
          children: [
            /* @__PURE__ */ jsx(Calendar$1, { className: "mr-2 h-4 w-4" }),
            value ? format(toZonedTime(value, timezone), "PPP", locale ? { locale } : void 0) : /* @__PURE__ */ jsx("span", { children: placeholder })
          ]
        }
      ) }),
      /* @__PURE__ */ jsx(PopoverContent, { className: "w-auto p-0", align: "start", children: /* @__PURE__ */ jsx(
        Calendar,
        {
          mode: "single",
          selected: value,
          onSelect: onChange,
          autoFocus: true,
          locale
        }
      ) })
    ] }),
    error ? /* @__PURE__ */ jsx("p", { "data-slot": "date-picker-error", className: "mt-1 text-sm text-destructive", children: error }) : null
  ] });
}
function DateRangePicker({
  value,
  onChange,
  placeholder = "Select date range",
  className,
  disabled,
  locale: localeProp,
  error
}) {
  const contextLocale = useDateFnsLocale();
  const locale = localeProp ?? contextLocale;
  const { timezone } = useTimezone();
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsxs(Popover, { children: [
      /* @__PURE__ */ jsx(PopoverTrigger, { asChild: true, children: /* @__PURE__ */ jsxs(
        Button,
        {
          variant: "outline",
          disabled,
          "aria-invalid": error ? true : void 0,
          className: cn(
            "w-full justify-start text-left font-normal",
            !value && "text-muted-foreground",
            className
          ),
          children: [
            /* @__PURE__ */ jsx(Calendar$1, { className: "mr-2 h-4 w-4" }),
            value?.from ? value.to ? /* @__PURE__ */ jsxs(Fragment, { children: [
              format(toZonedTime(value.from, timezone), "PPP", locale ? { locale } : void 0),
              " -",
              " ",
              format(toZonedTime(value.to, timezone), "PPP", locale ? { locale } : void 0)
            ] }) : format(toZonedTime(value.from, timezone), "PPP", locale ? { locale } : void 0) : /* @__PURE__ */ jsx("span", { children: placeholder })
          ]
        }
      ) }),
      /* @__PURE__ */ jsx(PopoverContent, { className: "w-auto p-0", align: "start", children: /* @__PURE__ */ jsx(
        Calendar,
        {
          mode: "range",
          selected: value,
          onSelect: onChange,
          numberOfMonths: 2,
          autoFocus: true,
          locale
        }
      ) })
    ] }),
    error ? /* @__PURE__ */ jsx("p", { "data-slot": "date-range-picker-error", className: "mt-1 text-sm text-destructive", children: error }) : null
  ] });
}
function Drawer({
  ...props
}) {
  return /* @__PURE__ */ jsx(Drawer$1.Root, { "data-slot": "drawer", ...props });
}
function DrawerTrigger({
  ...props
}) {
  return /* @__PURE__ */ jsx(Drawer$1.Trigger, { "data-slot": "drawer-trigger", ...props });
}
function DrawerPortal({
  ...props
}) {
  return /* @__PURE__ */ jsx(Drawer$1.Portal, { "data-slot": "drawer-portal", ...props });
}
function DrawerClose({
  ...props
}) {
  return /* @__PURE__ */ jsx(Drawer$1.Close, { "data-slot": "drawer-close", ...props });
}
function DrawerOverlay({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    Drawer$1.Overlay,
    {
      "data-slot": "drawer-overlay",
      className: cn(
        "data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/50",
        className
      ),
      ...props
    }
  );
}
function DrawerContent({
  className,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsxs(DrawerPortal, { "data-slot": "drawer-portal", children: [
    /* @__PURE__ */ jsx(DrawerOverlay, {}),
    /* @__PURE__ */ jsxs(
      Drawer$1.Content,
      {
        "data-slot": "drawer-content",
        className: cn(
          "group/drawer-content bg-background fixed z-50 flex h-auto flex-col",
          "data-[vaul-drawer-direction=top]:inset-x-0 data-[vaul-drawer-direction=top]:top-0 data-[vaul-drawer-direction=top]:mb-24 data-[vaul-drawer-direction=top]:max-h-[80vh] data-[vaul-drawer-direction=top]:rounded-b-lg data-[vaul-drawer-direction=top]:border-b",
          "data-[vaul-drawer-direction=bottom]:inset-x-0 data-[vaul-drawer-direction=bottom]:bottom-0 data-[vaul-drawer-direction=bottom]:mt-24 data-[vaul-drawer-direction=bottom]:max-h-[80vh] data-[vaul-drawer-direction=bottom]:rounded-t-lg data-[vaul-drawer-direction=bottom]:border-t",
          "data-[vaul-drawer-direction=right]:inset-y-0 data-[vaul-drawer-direction=right]:right-0 data-[vaul-drawer-direction=right]:w-3/4 data-[vaul-drawer-direction=right]:border-l data-[vaul-drawer-direction=right]:sm:max-w-sm",
          "data-[vaul-drawer-direction=left]:inset-y-0 data-[vaul-drawer-direction=left]:left-0 data-[vaul-drawer-direction=left]:w-3/4 data-[vaul-drawer-direction=left]:border-r data-[vaul-drawer-direction=left]:sm:max-w-sm",
          className
        ),
        ...props,
        children: [
          /* @__PURE__ */ jsx("div", { className: "bg-muted mx-auto mt-4 hidden h-2 w-[100px] shrink-0 rounded-full group-data-[vaul-drawer-direction=bottom]/drawer-content:block" }),
          children
        ]
      }
    )
  ] });
}
function DrawerHeader({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "drawer-header",
      className: cn("flex flex-col gap-1.5 p-dialog", className),
      ...props
    }
  );
}
function DrawerBody({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "drawer-body",
      className: cn("flex-1 overflow-y-auto p-dialog", className),
      ...props
    }
  );
}
function DrawerFooter({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "drawer-footer",
      className: cn("mt-auto flex flex-col gap-2 p-dialog", className),
      ...props
    }
  );
}
function DrawerTitle({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    Drawer$1.Title,
    {
      "data-slot": "drawer-title",
      className: cn("text-foreground font-semibold", className),
      ...props
    }
  );
}
function DrawerDescription({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    Drawer$1.Description,
    {
      "data-slot": "drawer-description",
      className: cn("text-muted-foreground text-sm", className),
      ...props
    }
  );
}
function DropdownMenu({
  ...props
}) {
  return /* @__PURE__ */ jsx(DropdownMenuPrimitive.Root, { "data-slot": "dropdown-menu", ...props });
}
function DropdownMenuPortal({
  ...props
}) {
  return /* @__PURE__ */ jsx(DropdownMenuPrimitive.Portal, { "data-slot": "dropdown-menu-portal", ...props });
}
function DropdownMenuTrigger({
  ...props
}) {
  return /* @__PURE__ */ jsx(
    DropdownMenuPrimitive.Trigger,
    {
      "data-slot": "dropdown-menu-trigger",
      ...props
    }
  );
}
function DropdownMenuContent({
  className,
  sideOffset = 4,
  ...props
}) {
  return /* @__PURE__ */ jsx(DropdownMenuPrimitive.Portal, { children: /* @__PURE__ */ jsx(
    DropdownMenuPrimitive.Content,
    {
      "data-slot": "dropdown-menu-content",
      sideOffset,
      className: cn(
        "bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 max-h-(--radix-dropdown-menu-content-available-height) min-w-[8rem] origin-(--radix-dropdown-menu-content-transform-origin) overflow-x-hidden overflow-y-auto rounded-md border p-1 shadow-md",
        className
      ),
      ...props
    }
  ) });
}
function DropdownMenuGroup({
  ...props
}) {
  return /* @__PURE__ */ jsx(DropdownMenuPrimitive.Group, { "data-slot": "dropdown-menu-group", ...props });
}
function DropdownMenuItem({
  className,
  inset,
  variant = "default",
  ...props
}) {
  return /* @__PURE__ */ jsx(
    DropdownMenuPrimitive.Item,
    {
      "data-slot": "dropdown-menu-item",
      "data-inset": inset,
      "data-variant": variant,
      className: cn(
        "focus:bg-accent focus:text-accent-foreground data-[variant=destructive]:text-destructive data-[variant=destructive]:focus:bg-destructive/10 dark:data-[variant=destructive]:focus:bg-destructive/20 data-[variant=destructive]:focus:text-destructive data-[variant=destructive]:*:[svg]:!text-destructive [&_svg:not([class*='text-'])]:text-muted-foreground relative flex cursor-default items-center gap-2 rounded-sm px-2 py-[var(--density-menu-item-py)] text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[inset]:pl-8 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      ...props
    }
  );
}
function DropdownMenuCheckboxItem({
  className,
  children,
  checked,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    DropdownMenuPrimitive.CheckboxItem,
    {
      "data-slot": "dropdown-menu-checkbox-item",
      className: cn(
        "focus:bg-accent focus:text-accent-foreground relative flex cursor-default items-center gap-2 rounded-sm py-1.5 pr-2 pl-8 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      checked,
      ...props,
      children: [
        /* @__PURE__ */ jsx("span", { className: "pointer-events-none absolute left-2 flex size-3.5 items-center justify-center", children: /* @__PURE__ */ jsx(DropdownMenuPrimitive.ItemIndicator, { children: /* @__PURE__ */ jsx(CheckIcon, { className: "size-4" }) }) }),
        children
      ]
    }
  );
}
function DropdownMenuRadioGroup({
  ...props
}) {
  return /* @__PURE__ */ jsx(
    DropdownMenuPrimitive.RadioGroup,
    {
      "data-slot": "dropdown-menu-radio-group",
      ...props
    }
  );
}
function DropdownMenuRadioItem({
  className,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    DropdownMenuPrimitive.RadioItem,
    {
      "data-slot": "dropdown-menu-radio-item",
      className: cn(
        "focus:bg-accent focus:text-accent-foreground relative flex cursor-default items-center gap-2 rounded-sm py-1.5 pr-2 pl-8 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      ...props,
      children: [
        /* @__PURE__ */ jsx("span", { className: "pointer-events-none absolute left-2 flex size-3.5 items-center justify-center", children: /* @__PURE__ */ jsx(DropdownMenuPrimitive.ItemIndicator, { children: /* @__PURE__ */ jsx(CircleIcon, { className: "size-2 fill-current" }) }) }),
        children
      ]
    }
  );
}
function DropdownMenuLabel({
  className,
  inset,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    DropdownMenuPrimitive.Label,
    {
      "data-slot": "dropdown-menu-label",
      "data-inset": inset,
      className: cn(
        "px-2 py-1.5 text-sm font-medium data-[inset]:pl-8",
        className
      ),
      ...props
    }
  );
}
function DropdownMenuSeparator({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    DropdownMenuPrimitive.Separator,
    {
      "data-slot": "dropdown-menu-separator",
      className: cn("bg-border -mx-1 my-1 h-px", className),
      ...props
    }
  );
}
function DropdownMenuShortcut({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "span",
    {
      "data-slot": "dropdown-menu-shortcut",
      className: cn(
        "text-muted-foreground ml-auto text-xs tracking-widest",
        className
      ),
      ...props
    }
  );
}
function DropdownMenuSub({
  ...props
}) {
  return /* @__PURE__ */ jsx(DropdownMenuPrimitive.Sub, { "data-slot": "dropdown-menu-sub", ...props });
}
function DropdownMenuSubTrigger({
  className,
  inset,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    DropdownMenuPrimitive.SubTrigger,
    {
      "data-slot": "dropdown-menu-sub-trigger",
      "data-inset": inset,
      className: cn(
        "focus:bg-accent focus:text-accent-foreground data-[state=open]:bg-accent data-[state=open]:text-accent-foreground flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-hidden select-none data-[inset]:pl-8",
        className
      ),
      ...props,
      children: [
        children,
        /* @__PURE__ */ jsx(ChevronRightIcon, { className: "ml-auto size-4" })
      ]
    }
  );
}
function DropdownMenuSubContent({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    DropdownMenuPrimitive.SubContent,
    {
      "data-slot": "dropdown-menu-sub-content",
      className: cn(
        "bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 min-w-[8rem] origin-(--radix-dropdown-menu-content-transform-origin) overflow-hidden rounded-md border p-1 shadow-lg",
        className
      ),
      ...props
    }
  );
}
function getFileIcon(file) {
  if (file.type.startsWith("image/")) return FileImage;
  if (file.type.startsWith("video/")) return FileVideo;
  if (file.type.startsWith("text/")) return FileText;
  return File;
}
function formatFileSize(bytes) {
  if (bytes === 0) return "0 B";
  const k = 1024;
  const sizes = ["B", "KB", "MB", "GB"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + " " + sizes[i];
}
function FileUpload({
  value = [],
  onChange,
  accept,
  multiple = false,
  maxSize = 10 * 1024 * 1024,
  maxFiles,
  disabled,
  className,
  showPreview = true,
  variant = "dropzone",
  placeholder,
  hint
}) {
  const inputRef = React2.useRef(null);
  const [dragActive, setDragActive] = React2.useState(false);
  const [error, setError] = React2.useState("");
  const previewUrls = React2.useRef(/* @__PURE__ */ new Map());
  React2.useEffect(() => {
    return () => {
      for (const url of previewUrls.current.values()) URL.revokeObjectURL(url);
      previewUrls.current.clear();
    };
  }, []);
  const getPreviewUrl = React2.useCallback((file, index) => {
    const key = `${file.name}-${file.size}-${index}`;
    if (!previewUrls.current.has(key)) previewUrls.current.set(key, URL.createObjectURL(file));
    return previewUrls.current.get(key);
  }, []);
  const handleDrag = (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (e.type === "dragenter" || e.type === "dragover") setDragActive(true);
    else if (e.type === "dragleave") setDragActive(false);
  };
  const validateFiles = (files) => {
    setError("");
    if (maxFiles && value.length + files.length > maxFiles) return { valid: [], error: `Maximum ${maxFiles} file(s) allowed` };
    const oversized = files.filter((f) => f.size > maxSize);
    if (oversized.length > 0) return { valid: [], error: `File exceeds ${Math.round(maxSize / 1024 / 1024)}MB limit` };
    return { valid: files };
  };
  const addFiles = (files) => {
    const { valid, error: err } = validateFiles(files);
    if (err) {
      setError(err);
      return;
    }
    onChange?.([...value, ...valid]);
  };
  const handleDrop = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setDragActive(false);
    if (disabled) return;
    addFiles(Array.from(e.dataTransfer.files));
  };
  const handleChange = (e) => {
    if (disabled) return;
    addFiles(Array.from(e.target.files || []));
    if (inputRef.current) inputRef.current.value = "";
  };
  const handleRemove = (index) => {
    const f = value[index];
    const key = `${f.name}-${f.size}-${index}`;
    const url = previewUrls.current.get(key);
    if (url) {
      URL.revokeObjectURL(url);
      previewUrls.current.delete(key);
    }
    onChange?.(value.filter((_, i) => i !== index));
  };
  const triggerPick = () => !disabled && inputRef.current?.click();
  const hiddenInput = /* @__PURE__ */ jsx("input", { ref: inputRef, type: "file", accept, multiple, onChange: handleChange, disabled, className: "hidden" });
  const errorEl = error ? /* @__PURE__ */ jsxs("p", { className: "mt-1.5 flex items-center gap-1.5 text-xs font-medium text-destructive", role: "alert", children: [
    /* @__PURE__ */ jsx("span", { className: "inline-block h-1 w-1 rounded-full bg-destructive" }),
    error
  ] }) : null;
  if (variant === "avatar") {
    const file = value[0];
    const previewUrl = file?.type.startsWith("image/") ? getPreviewUrl(file, 0) : null;
    return /* @__PURE__ */ jsxs("div", { className: cn("inline-flex flex-col items-center gap-2", className), children: [
      hiddenInput,
      /* @__PURE__ */ jsxs(
        "button",
        {
          type: "button",
          onClick: triggerPick,
          disabled,
          onDragEnter: handleDrag,
          onDragLeave: handleDrag,
          onDragOver: handleDrag,
          onDrop: handleDrop,
          className: cn("relative h-24 w-24 overflow-hidden rounded-full border-2 border-dashed transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring", dragActive ? "border-primary bg-primary/10" : "border-muted-foreground/25 hover:border-primary/50", disabled && "pointer-events-none opacity-50"),
          children: [
            previewUrl ? /* @__PURE__ */ jsx("img", { src: previewUrl, alt: "Avatar", className: "h-full w-full object-cover" }) : /* @__PURE__ */ jsx("div", { className: "flex h-full w-full items-center justify-center bg-muted", children: /* @__PURE__ */ jsx(ImagePlus, { className: "h-8 w-8 text-muted-foreground" }) }),
            /* @__PURE__ */ jsx("div", { className: "absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition-opacity hover:opacity-100", children: /* @__PURE__ */ jsx(Upload, { className: "h-5 w-5 text-white" }) })
          ]
        }
      ),
      file && /* @__PURE__ */ jsx("button", { type: "button", onClick: () => handleRemove(0), disabled, className: "text-xs text-muted-foreground hover:text-destructive transition-colors", children: "Remove" }),
      errorEl
    ] });
  }
  if (variant === "compact") {
    return /* @__PURE__ */ jsxs("div", { className: cn("flex flex-col gap-1.5", className), children: [
      hiddenInput,
      /* @__PURE__ */ jsxs("div", { className: "flex items-center gap-2", children: [
        /* @__PURE__ */ jsxs(Button, { type: "button", variant: "outline", size: "sm", onClick: triggerPick, disabled, className: "shrink-0", children: [
          /* @__PURE__ */ jsx(Upload, { className: "mr-1.5 h-3.5 w-3.5" }),
          placeholder ?? "Choose file"
        ] }),
        value.length > 0 ? /* @__PURE__ */ jsx("span", { className: "truncate text-sm text-muted-foreground", children: value.length === 1 ? value[0].name : `${value.length} files selected` }) : /* @__PURE__ */ jsx("span", { className: "text-sm text-muted-foreground", children: hint ?? "No file chosen" })
      ] }),
      showPreview && value.length > 0 && /* @__PURE__ */ jsx("ul", { className: "space-y-1", children: value.map((file, i) => /* @__PURE__ */ jsxs("li", { className: "flex items-center gap-2 text-sm", children: [
        /* @__PURE__ */ jsx(Paperclip, { className: "h-3.5 w-3.5 shrink-0 text-muted-foreground" }),
        /* @__PURE__ */ jsx("span", { className: "truncate flex-1", children: file.name }),
        /* @__PURE__ */ jsx("span", { className: "shrink-0 text-xs text-muted-foreground", children: formatFileSize(file.size) }),
        /* @__PURE__ */ jsx("button", { type: "button", onClick: () => handleRemove(i), disabled, className: "shrink-0 text-muted-foreground hover:text-destructive", children: /* @__PURE__ */ jsx(X, { className: "h-3.5 w-3.5" }) })
      ] }, `${file.name}-${i}`)) }),
      errorEl
    ] });
  }
  if (variant === "inline") {
    const file = value[0];
    return /* @__PURE__ */ jsxs("div", { className: cn("flex items-center gap-2", className), children: [
      hiddenInput,
      file ? /* @__PURE__ */ jsxs(Fragment, { children: [
        /* @__PURE__ */ jsx(Paperclip, { className: "h-4 w-4 shrink-0 text-muted-foreground" }),
        /* @__PURE__ */ jsx("span", { className: "truncate text-sm flex-1", children: file.name }),
        /* @__PURE__ */ jsx("span", { className: "shrink-0 text-xs text-muted-foreground", children: formatFileSize(file.size) }),
        /* @__PURE__ */ jsx("button", { type: "button", onClick: triggerPick, disabled, className: "shrink-0 text-xs text-primary hover:underline", children: "Change" }),
        /* @__PURE__ */ jsx("button", { type: "button", onClick: () => handleRemove(0), disabled, className: "shrink-0 text-xs text-muted-foreground hover:text-destructive", children: "Remove" })
      ] }) : /* @__PURE__ */ jsxs("button", { type: "button", onClick: triggerPick, disabled, className: cn("flex items-center gap-1.5 text-sm text-primary hover:underline", disabled && "opacity-50 pointer-events-none"), children: [
        /* @__PURE__ */ jsx(Paperclip, { className: "h-4 w-4" }),
        placeholder ?? "Attach file"
      ] }),
      errorEl
    ] });
  }
  if (variant === "gallery") {
    const canAdd = !maxFiles || value.length < maxFiles;
    return /* @__PURE__ */ jsxs("div", { className: cn("flex flex-col gap-2", className), children: [
      hiddenInput,
      /* @__PURE__ */ jsxs("div", { className: "flex flex-wrap gap-2", children: [
        value.map((file, i) => {
          const isImage = file.type.startsWith("image/");
          const url = isImage ? getPreviewUrl(file, i) : null;
          const Icon2 = getFileIcon(file);
          return /* @__PURE__ */ jsxs("div", { className: "group/thumb relative h-20 w-20 overflow-hidden rounded-lg border bg-muted", children: [
            url ? /* @__PURE__ */ jsx("img", { src: url, alt: file.name, className: "h-full w-full object-cover" }) : /* @__PURE__ */ jsx("div", { className: "flex h-full w-full items-center justify-center", children: /* @__PURE__ */ jsx(Icon2, { className: "h-6 w-6 text-muted-foreground" }) }),
            /* @__PURE__ */ jsx("button", { type: "button", onClick: () => handleRemove(i), disabled, className: "absolute top-0.5 right-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-black/60 text-white opacity-0 transition-opacity group-hover/thumb:opacity-100", children: /* @__PURE__ */ jsx(X, { className: "h-3 w-3" }) })
          ] }, `${file.name}-${i}`);
        }),
        canAdd && /* @__PURE__ */ jsx(
          "button",
          {
            type: "button",
            onClick: triggerPick,
            disabled,
            onDragEnter: handleDrag,
            onDragLeave: handleDrag,
            onDragOver: handleDrag,
            onDrop: handleDrop,
            className: cn("flex h-20 w-20 items-center justify-center rounded-lg border-2 border-dashed transition-all", dragActive ? "border-primary bg-primary/10" : "border-muted-foreground/25 hover:border-primary/50 hover:bg-accent/50", disabled && "pointer-events-none opacity-50"),
            children: /* @__PURE__ */ jsx(Plus, { className: "h-6 w-6 text-muted-foreground" })
          }
        )
      ] }),
      maxFiles && /* @__PURE__ */ jsxs("p", { className: "text-xs text-muted-foreground", children: [
        value.length,
        "/",
        maxFiles,
        " files"
      ] }),
      errorEl
    ] });
  }
  return /* @__PURE__ */ jsxs("div", { className: cn("w-full", className), children: [
    hiddenInput,
    /* @__PURE__ */ jsxs(
      "div",
      {
        onDragEnter: handleDrag,
        onDragLeave: handleDrag,
        onDragOver: handleDrag,
        onDrop: handleDrop,
        onClick: triggerPick,
        className: cn("group relative flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-8 cursor-pointer transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2", dragActive ? "border-primary bg-primary/10 text-primary" : "border-muted-foreground/25 hover:border-primary/50 hover:bg-accent/50", disabled && "pointer-events-none opacity-50", error && "border-destructive/50 hover:border-destructive"),
        tabIndex: disabled ? -1 : 0,
        role: "button",
        onKeyDown: (e) => {
          if (!disabled && (e.key === "Enter" || e.key === " ")) {
            e.preventDefault();
            triggerPick();
          }
        },
        children: [
          /* @__PURE__ */ jsx("div", { className: cn("flex h-12 w-12 items-center justify-center rounded-full transition-colors duration-200", dragActive ? "bg-primary/15 text-primary" : "bg-muted text-muted-foreground group-hover:bg-primary/10 group-hover:text-primary"), children: /* @__PURE__ */ jsx(Upload, { className: "h-6 w-6" }) }),
          /* @__PURE__ */ jsxs("div", { className: "text-center", children: [
            /* @__PURE__ */ jsxs("p", { className: "text-sm", children: [
              /* @__PURE__ */ jsx("span", { className: "font-semibold text-primary", children: placeholder ?? "Click to upload" }),
              /* @__PURE__ */ jsxs("span", { className: "text-muted-foreground", children: [
                " ",
                hint ?? "or drag and drop"
              ] })
            ] }),
            /* @__PURE__ */ jsxs("p", { className: "mt-1.5 text-xs text-muted-foreground", children: [
              accept && /* @__PURE__ */ jsxs("span", { children: [
                accept,
                " \xB7 "
              ] }),
              /* @__PURE__ */ jsxs("span", { children: [
                "Max: ",
                Math.round(maxSize / 1024 / 1024),
                "MB"
              ] }),
              maxFiles && /* @__PURE__ */ jsxs("span", { children: [
                " \xB7 ",
                maxFiles,
                " file(s)"
              ] })
            ] })
          ] })
        ]
      }
    ),
    errorEl,
    showPreview && value.length > 0 && /* @__PURE__ */ jsx("ul", { className: "mt-3 space-y-2", children: value.map((file, index) => {
      const FileIcon = getFileIcon(file);
      const isImage = file.type.startsWith("image/");
      const previewUrl = isImage ? getPreviewUrl(file, index) : null;
      return /* @__PURE__ */ jsxs("li", { className: "group/item flex items-center gap-3 rounded-lg border bg-card p-2.5 transition-colors duration-150 hover:bg-accent/50", children: [
        previewUrl ? /* @__PURE__ */ jsx("img", { src: previewUrl, alt: file.name, className: "h-10 w-10 flex-shrink-0 rounded-md border object-cover" }) : /* @__PURE__ */ jsx("div", { className: "flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-md border bg-muted", children: /* @__PURE__ */ jsx(FileIcon, { className: "h-5 w-5 text-muted-foreground" }) }),
        /* @__PURE__ */ jsxs("div", { className: "min-w-0 flex-1", children: [
          /* @__PURE__ */ jsx("p", { className: "truncate text-sm font-medium leading-tight", children: file.name }),
          /* @__PURE__ */ jsx("p", { className: "text-xs text-muted-foreground", children: formatFileSize(file.size) })
        ] }),
        /* @__PURE__ */ jsxs(Button, { type: "button", variant: "ghost", size: "icon", onClick: (e) => {
          e.stopPropagation();
          handleRemove(index);
        }, disabled, className: "h-8 w-8 flex-shrink-0 opacity-0 transition-opacity group-hover/item:opacity-100 hover:bg-destructive/10 hover:text-destructive focus-visible:opacity-100", children: [
          /* @__PURE__ */ jsx(X, { className: "h-4 w-4" }),
          /* @__PURE__ */ jsxs("span", { className: "sr-only", children: [
            "Remove ",
            file.name
          ] })
        ] })
      ] }, `${file.name}-${index}`);
    }) })
  ] });
}
var Label3 = React2.forwardRef(({ className, ...props }, ref) => {
  return /* @__PURE__ */ jsx(
    LabelPrimitive.Root,
    {
      ref,
      "data-slot": "label",
      className: cn(
        "flex items-center gap-2 text-sm leading-none font-medium select-none group-data-[disabled=true]:pointer-events-none group-data-[disabled=true]:opacity-50 peer-disabled:cursor-not-allowed peer-disabled:opacity-50",
        className
      ),
      ...props
    }
  );
});
Label3.displayName = LabelPrimitive.Root.displayName;
var Form = FormProvider;
var FormFieldContext = React2.createContext(
  {}
);
var FormField = ({
  ...props
}) => {
  return /* @__PURE__ */ jsx(FormFieldContext.Provider, { value: { name: props.name }, children: /* @__PURE__ */ jsx(Controller, { ...props }) });
};
var useFormField = () => {
  const fieldContext = React2.useContext(FormFieldContext);
  const itemContext = React2.useContext(FormItemContext);
  const { getFieldState } = useFormContext();
  const formState = useFormState({ name: fieldContext.name });
  const fieldState = getFieldState(fieldContext.name, formState);
  if (!fieldContext) {
    throw new Error("useFormField should be used within <FormField>");
  }
  const { id } = itemContext;
  return {
    id,
    name: fieldContext.name,
    formItemId: `${id}-form-item`,
    formDescriptionId: `${id}-form-item-description`,
    formMessageId: `${id}-form-item-message`,
    ...fieldState
  };
};
var FormItemContext = React2.createContext(
  {}
);
function FormItem({ className, ...props }) {
  const id = React2.useId();
  return /* @__PURE__ */ jsx(FormItemContext.Provider, { value: { id }, children: /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "form-item",
      className: cn("grid gap-2", className),
      ...props
    }
  ) });
}
function FormLabel({
  className,
  ...props
}) {
  const { error, formItemId } = useFormField();
  return /* @__PURE__ */ jsx(
    Label3,
    {
      "data-slot": "form-label",
      "data-error": !!error,
      className: cn("data-[error=true]:text-destructive", className),
      htmlFor: formItemId,
      ...props
    }
  );
}
function FormControl({ ...props }) {
  const { error, formItemId, formDescriptionId, formMessageId } = useFormField();
  return /* @__PURE__ */ jsx(
    Slot,
    {
      "data-slot": "form-control",
      id: formItemId,
      "aria-describedby": !error ? `${formDescriptionId}` : `${formDescriptionId} ${formMessageId}`,
      "aria-invalid": !!error,
      ...props
    }
  );
}
function FormDescription({ className, ...props }) {
  const { formDescriptionId } = useFormField();
  return /* @__PURE__ */ jsx(
    "p",
    {
      "data-slot": "form-description",
      id: formDescriptionId,
      className: cn("text-muted-foreground text-sm", className),
      ...props
    }
  );
}
function FormMessage({ className, ...props }) {
  const { error, formMessageId } = useFormField();
  const body = error ? String(error?.message ?? "") : props.children;
  if (!body) {
    return null;
  }
  return /* @__PURE__ */ jsx(
    "p",
    {
      "data-slot": "form-message",
      id: formMessageId,
      className: cn("text-destructive text-sm", className),
      ...props,
      children: body
    }
  );
}
function HoverCard({
  ...props
}) {
  return /* @__PURE__ */ jsx(HoverCardPrimitive.Root, { "data-slot": "hover-card", ...props });
}
function HoverCardTrigger({
  ...props
}) {
  return /* @__PURE__ */ jsx(HoverCardPrimitive.Trigger, { "data-slot": "hover-card-trigger", ...props });
}
function HoverCardContent({
  className,
  align = "center",
  sideOffset = 4,
  ...props
}) {
  return /* @__PURE__ */ jsx(HoverCardPrimitive.Portal, { "data-slot": "hover-card-portal", children: /* @__PURE__ */ jsx(
    HoverCardPrimitive.Content,
    {
      "data-slot": "hover-card-content",
      align,
      sideOffset,
      className: cn(
        "bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 w-64 origin-(--radix-hover-card-content-transform-origin) rounded-md border p-4 shadow-md outline-hidden",
        className
      ),
      ...props
    }
  ) });
}
function InputOTP({
  className,
  containerClassName,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    OTPInput,
    {
      "data-slot": "input-otp",
      containerClassName: cn(
        "flex items-center gap-2 has-disabled:opacity-50",
        containerClassName
      ),
      className: cn("disabled:cursor-not-allowed", className),
      ...props
    }
  );
}
function InputOTPGroup({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "input-otp-group",
      className: cn("flex items-center gap-1", className),
      ...props
    }
  );
}
function InputOTPSlot({
  index,
  className,
  ...props
}) {
  const inputOTPContext = React2.useContext(OTPInputContext);
  const { char, hasFakeCaret, isActive } = inputOTPContext?.slots[index] ?? {};
  return /* @__PURE__ */ jsxs(
    "div",
    {
      "data-slot": "input-otp-slot",
      "data-active": isActive,
      className: cn(
        "data-[active=true]:border-ring data-[active=true]:ring-ring/50 data-[active=true]:aria-invalid:ring-destructive/20 dark:data-[active=true]:aria-invalid:ring-destructive/40 aria-invalid:border-destructive data-[active=true]:aria-invalid:border-destructive dark:bg-input/30 border-input relative flex h-9 w-9 items-center justify-center border-y border-r text-sm bg-input-background transition-all outline-none first:rounded-l-md first:border-l last:rounded-r-md data-[active=true]:z-10 data-[active=true]:ring-[3px]",
        className
      ),
      ...props,
      children: [
        char,
        hasFakeCaret && /* @__PURE__ */ jsx("div", { className: "pointer-events-none absolute inset-0 flex items-center justify-center", children: /* @__PURE__ */ jsx("div", { className: "animate-caret-blink bg-foreground h-4 w-px duration-1000" }) })
      ]
    }
  );
}
function InputOTPSeparator({ ...props }) {
  return /* @__PURE__ */ jsx("div", { "data-slot": "input-otp-separator", role: "separator", ...props, children: /* @__PURE__ */ jsx(MinusIcon, {}) });
}
function Menubar({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    MenubarPrimitive.Root,
    {
      "data-slot": "menubar",
      className: cn(
        "bg-background flex h-9 items-center gap-1 rounded-md border p-1 shadow-xs",
        className
      ),
      ...props
    }
  );
}
function MenubarMenu({
  ...props
}) {
  return /* @__PURE__ */ jsx(MenubarPrimitive.Menu, { "data-slot": "menubar-menu", ...props });
}
function MenubarGroup({
  ...props
}) {
  return /* @__PURE__ */ jsx(MenubarPrimitive.Group, { "data-slot": "menubar-group", ...props });
}
function MenubarPortal({
  ...props
}) {
  return /* @__PURE__ */ jsx(MenubarPrimitive.Portal, { "data-slot": "menubar-portal", ...props });
}
function MenubarRadioGroup({
  ...props
}) {
  return /* @__PURE__ */ jsx(MenubarPrimitive.RadioGroup, { "data-slot": "menubar-radio-group", ...props });
}
function MenubarTrigger({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    MenubarPrimitive.Trigger,
    {
      "data-slot": "menubar-trigger",
      className: cn(
        "focus:bg-accent focus:text-accent-foreground data-[state=open]:bg-accent data-[state=open]:text-accent-foreground flex items-center rounded-sm px-2 py-1 text-sm font-medium outline-hidden select-none",
        className
      ),
      ...props
    }
  );
}
function MenubarContent({
  className,
  align = "start",
  alignOffset = -4,
  sideOffset = 8,
  ...props
}) {
  return /* @__PURE__ */ jsx(MenubarPortal, { children: /* @__PURE__ */ jsx(
    MenubarPrimitive.Content,
    {
      "data-slot": "menubar-content",
      align,
      alignOffset,
      sideOffset,
      className: cn(
        "bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 min-w-[12rem] origin-(--radix-menubar-content-transform-origin) overflow-hidden rounded-md border p-1 shadow-md",
        className
      ),
      ...props
    }
  ) });
}
function MenubarItem({
  className,
  inset,
  variant = "default",
  ...props
}) {
  return /* @__PURE__ */ jsx(
    MenubarPrimitive.Item,
    {
      "data-slot": "menubar-item",
      "data-inset": inset,
      "data-variant": variant,
      className: cn(
        "focus:bg-accent focus:text-accent-foreground data-[variant=destructive]:text-destructive data-[variant=destructive]:focus:bg-destructive/10 dark:data-[variant=destructive]:focus:bg-destructive/20 data-[variant=destructive]:focus:text-destructive data-[variant=destructive]:*:[svg]:!text-destructive [&_svg:not([class*='text-'])]:text-muted-foreground relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[inset]:pl-8 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      ...props
    }
  );
}
function MenubarCheckboxItem({
  className,
  children,
  checked,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    MenubarPrimitive.CheckboxItem,
    {
      "data-slot": "menubar-checkbox-item",
      className: cn(
        "focus:bg-accent focus:text-accent-foreground relative flex cursor-default items-center gap-2 rounded-xs py-1.5 pr-2 pl-8 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      checked,
      ...props,
      children: [
        /* @__PURE__ */ jsx("span", { className: "pointer-events-none absolute left-2 flex size-3.5 items-center justify-center", children: /* @__PURE__ */ jsx(MenubarPrimitive.ItemIndicator, { children: /* @__PURE__ */ jsx(CheckIcon, { className: "size-4" }) }) }),
        children
      ]
    }
  );
}
function MenubarRadioItem({
  className,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    MenubarPrimitive.RadioItem,
    {
      "data-slot": "menubar-radio-item",
      className: cn(
        "focus:bg-accent focus:text-accent-foreground relative flex cursor-default items-center gap-2 rounded-xs py-1.5 pr-2 pl-8 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      ...props,
      children: [
        /* @__PURE__ */ jsx("span", { className: "pointer-events-none absolute left-2 flex size-3.5 items-center justify-center", children: /* @__PURE__ */ jsx(MenubarPrimitive.ItemIndicator, { children: /* @__PURE__ */ jsx(CircleIcon, { className: "size-2 fill-current" }) }) }),
        children
      ]
    }
  );
}
function MenubarLabel({
  className,
  inset,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    MenubarPrimitive.Label,
    {
      "data-slot": "menubar-label",
      "data-inset": inset,
      className: cn(
        "px-2 py-1.5 text-sm font-medium data-[inset]:pl-8",
        className
      ),
      ...props
    }
  );
}
function MenubarSeparator({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    MenubarPrimitive.Separator,
    {
      "data-slot": "menubar-separator",
      className: cn("bg-border -mx-1 my-1 h-px", className),
      ...props
    }
  );
}
function MenubarShortcut({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "span",
    {
      "data-slot": "menubar-shortcut",
      className: cn(
        "text-muted-foreground ml-auto text-xs tracking-widest",
        className
      ),
      ...props
    }
  );
}
function MenubarSub({
  ...props
}) {
  return /* @__PURE__ */ jsx(MenubarPrimitive.Sub, { "data-slot": "menubar-sub", ...props });
}
function MenubarSubTrigger({
  className,
  inset,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    MenubarPrimitive.SubTrigger,
    {
      "data-slot": "menubar-sub-trigger",
      "data-inset": inset,
      className: cn(
        "focus:bg-accent focus:text-accent-foreground data-[state=open]:bg-accent data-[state=open]:text-accent-foreground flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-none select-none data-[inset]:pl-8",
        className
      ),
      ...props,
      children: [
        children,
        /* @__PURE__ */ jsx(ChevronRightIcon, { className: "ml-auto h-4 w-4" })
      ]
    }
  );
}
function MenubarSubContent({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    MenubarPrimitive.SubContent,
    {
      "data-slot": "menubar-sub-content",
      className: cn(
        "bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 min-w-[8rem] origin-(--radix-menubar-content-transform-origin) overflow-hidden rounded-md border p-1 shadow-lg",
        className
      ),
      ...props
    }
  );
}
function NavigationMenu({
  className,
  children,
  viewport = true,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    NavigationMenuPrimitive.Root,
    {
      "data-slot": "navigation-menu",
      "data-viewport": viewport,
      className: cn(
        "group/navigation-menu relative flex max-w-max flex-1 items-center justify-center",
        className
      ),
      ...props,
      children: [
        children,
        viewport && /* @__PURE__ */ jsx(NavigationMenuViewport, {})
      ]
    }
  );
}
function NavigationMenuList({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    NavigationMenuPrimitive.List,
    {
      "data-slot": "navigation-menu-list",
      className: cn(
        "group flex flex-1 list-none items-center justify-center gap-1",
        className
      ),
      ...props
    }
  );
}
function NavigationMenuItem({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    NavigationMenuPrimitive.Item,
    {
      "data-slot": "navigation-menu-item",
      className: cn("relative", className),
      ...props
    }
  );
}
var navigationMenuTriggerStyle = cva(
  "group inline-flex h-9 w-max items-center justify-center rounded-md bg-background px-4 py-2 text-sm font-medium hover:bg-accent hover:text-accent-foreground focus:bg-accent focus:text-accent-foreground disabled:pointer-events-none disabled:opacity-50 data-[state=open]:hover:bg-accent data-[state=open]:text-accent-foreground data-[state=open]:focus:bg-accent data-[state=open]:bg-accent/50 focus-visible:ring-ring/50 outline-none transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:outline-1"
);
function NavigationMenuTrigger({
  className,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    NavigationMenuPrimitive.Trigger,
    {
      "data-slot": "navigation-menu-trigger",
      className: cn(navigationMenuTriggerStyle(), "group", className),
      ...props,
      children: [
        children,
        " ",
        /* @__PURE__ */ jsx(
          ChevronDownIcon,
          {
            className: "relative top-[1px] ml-1 size-3 transition duration-300 group-data-[state=open]:rotate-180",
            "aria-hidden": "true"
          }
        )
      ]
    }
  );
}
function NavigationMenuContent({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    NavigationMenuPrimitive.Content,
    {
      "data-slot": "navigation-menu-content",
      className: cn(
        "data-[motion^=from-]:animate-in data-[motion^=to-]:animate-out data-[motion^=from-]:fade-in data-[motion^=to-]:fade-out data-[motion=from-end]:slide-in-from-right-52 data-[motion=from-start]:slide-in-from-left-52 data-[motion=to-end]:slide-out-to-right-52 data-[motion=to-start]:slide-out-to-left-52 top-0 left-0 w-full p-2 pr-2.5 md:absolute md:w-auto",
        "group-data-[viewport=false]/navigation-menu:bg-popover group-data-[viewport=false]/navigation-menu:text-popover-foreground group-data-[viewport=false]/navigation-menu:data-[state=open]:animate-in group-data-[viewport=false]/navigation-menu:data-[state=closed]:animate-out group-data-[viewport=false]/navigation-menu:data-[state=closed]:zoom-out-95 group-data-[viewport=false]/navigation-menu:data-[state=open]:zoom-in-95 group-data-[viewport=false]/navigation-menu:data-[state=open]:fade-in-0 group-data-[viewport=false]/navigation-menu:data-[state=closed]:fade-out-0 group-data-[viewport=false]/navigation-menu:top-full group-data-[viewport=false]/navigation-menu:mt-1.5 group-data-[viewport=false]/navigation-menu:overflow-hidden group-data-[viewport=false]/navigation-menu:rounded-md group-data-[viewport=false]/navigation-menu:border group-data-[viewport=false]/navigation-menu:shadow group-data-[viewport=false]/navigation-menu:duration-200 **:data-[slot=navigation-menu-link]:focus:ring-0 **:data-[slot=navigation-menu-link]:focus:outline-none",
        className
      ),
      ...props
    }
  );
}
function NavigationMenuViewport({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      className: cn(
        "absolute top-full left-0 isolate z-50 flex justify-center"
      ),
      children: /* @__PURE__ */ jsx(
        NavigationMenuPrimitive.Viewport,
        {
          "data-slot": "navigation-menu-viewport",
          className: cn(
            "origin-top-center bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-90 relative mt-1.5 h-[var(--radix-navigation-menu-viewport-height)] w-full overflow-hidden rounded-md border shadow md:w-[var(--radix-navigation-menu-viewport-width)]",
            className
          ),
          ...props
        }
      )
    }
  );
}
function NavigationMenuLink({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    NavigationMenuPrimitive.Link,
    {
      "data-slot": "navigation-menu-link",
      className: cn(
        "data-[active=true]:focus:bg-accent data-[active=true]:hover:bg-accent data-[active=true]:bg-accent/50 data-[active=true]:text-accent-foreground hover:bg-accent hover:text-accent-foreground focus:bg-accent focus:text-accent-foreground focus-visible:ring-ring/50 [&_svg:not([class*='text-'])]:text-muted-foreground flex flex-col gap-1 rounded-sm p-2 text-sm transition-all outline-none focus-visible:ring-[3px] focus-visible:outline-1 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      ...props
    }
  );
}
function NavigationMenuIndicator({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    NavigationMenuPrimitive.Indicator,
    {
      "data-slot": "navigation-menu-indicator",
      className: cn(
        "data-[state=visible]:animate-in data-[state=hidden]:animate-out data-[state=hidden]:fade-out data-[state=visible]:fade-in top-full z-[1] flex h-1.5 items-end justify-center overflow-hidden",
        className
      ),
      ...props,
      children: /* @__PURE__ */ jsx("div", { className: "bg-border relative top-[60%] h-2 w-2 rotate-45 rounded-tl-sm shadow-md" })
    }
  );
}
function Separator4({
  className,
  orientation = "horizontal",
  decorative = true,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    SeparatorPrimitive.Root,
    {
      "data-slot": "separator-root",
      decorative,
      orientation,
      className: cn(
        "bg-border shrink-0 data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px",
        className
      ),
      ...props
    }
  );
}
function PageContainer({
  title,
  subtitle,
  extra,
  children,
  footer,
  sidebar,
  sidebarPosition = "right",
  sidebarWidth = "w-80",
  variant = "standard",
  className = "",
  contentClassName = "",
  showHeaderSeparator = true
}) {
  if (variant === "full") {
    return /* @__PURE__ */ jsxs("div", { className: `h-full flex flex-col ${className}`, children: [
      children,
      footer && /* @__PURE__ */ jsx("div", { className: "border-t border-border", children: footer })
    ] });
  }
  if (variant === "split") {
    return /* @__PURE__ */ jsxs("div", { className: `h-full flex flex-col ${className}`, children: [
      (title || extra) && /* @__PURE__ */ jsx("div", { className: "px-page py-3 border-b border-border", children: /* @__PURE__ */ jsxs("div", { className: "flex items-start justify-between gap-4", children: [
        /* @__PURE__ */ jsxs("div", { className: "flex-1 min-w-0", children: [
          title && /* @__PURE__ */ jsx("h1", { className: "text-page-title font-semibold mb-1", children: title }),
          subtitle && /* @__PURE__ */ jsx("p", { className: "text-sm text-muted-foreground", children: subtitle })
        ] }),
        extra && /* @__PURE__ */ jsx("div", { className: "flex-shrink-0", children: extra })
      ] }) }),
      /* @__PURE__ */ jsxs("div", { className: "flex-1 flex overflow-hidden", children: [
        sidebar && sidebarPosition === "left" && /* @__PURE__ */ jsx("aside", { className: `${sidebarWidth} border-r border-border overflow-y-auto flex-shrink-0`, children: sidebar }),
        /* @__PURE__ */ jsx("main", { className: `flex-1 overflow-y-auto ${contentClassName}`, children }),
        sidebar && sidebarPosition === "right" && /* @__PURE__ */ jsx("aside", { className: `${sidebarWidth} border-l border-border overflow-y-auto flex-shrink-0`, children: sidebar })
      ] }),
      footer && /* @__PURE__ */ jsx("div", { className: "border-t border-border", children: footer })
    ] });
  }
  return /* @__PURE__ */ jsxs("div", { className: `h-full flex flex-col ${className}`, children: [
    (title || extra) && /* @__PURE__ */ jsxs(Fragment, { children: [
      /* @__PURE__ */ jsx("div", { className: "px-page py-3", children: /* @__PURE__ */ jsxs("div", { className: "flex items-start justify-between gap-4", children: [
        /* @__PURE__ */ jsxs("div", { className: "flex-1 min-w-0", children: [
          title && /* @__PURE__ */ jsx("h1", { className: "text-page-title font-semibold mb-1", children: title }),
          subtitle && /* @__PURE__ */ jsx("p", { className: "text-sm text-muted-foreground", children: subtitle })
        ] }),
        extra && /* @__PURE__ */ jsx("div", { className: "flex-shrink-0", children: extra })
      ] }) }),
      showHeaderSeparator && /* @__PURE__ */ jsx(Separator4, {})
    ] }),
    /* @__PURE__ */ jsx("main", { className: `flex-1 overflow-y-auto p-page ${contentClassName}`, children }),
    footer && /* @__PURE__ */ jsxs(Fragment, { children: [
      /* @__PURE__ */ jsx(Separator4, {}),
      /* @__PURE__ */ jsx("div", { className: "px-page py-3", children: footer })
    ] })
  ] });
}
function StandardPageContainer(props) {
  return /* @__PURE__ */ jsx(PageContainer, { ...props, variant: "standard" });
}
function SplitPageContainer(props) {
  return /* @__PURE__ */ jsx(PageContainer, { ...props, variant: "split" });
}
function FullWidthPageContainer(props) {
  return /* @__PURE__ */ jsx(PageContainer, { ...props, variant: "full" });
}
function Pagination({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "nav",
    {
      role: "navigation",
      "aria-label": "pagination",
      "data-slot": "pagination",
      className: cn("mx-auto flex w-full justify-center", className),
      ...props
    }
  );
}
function PaginationContent({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "ul",
    {
      "data-slot": "pagination-content",
      className: cn("flex flex-row items-center gap-1", className),
      ...props
    }
  );
}
function PaginationItem({ ...props }) {
  return /* @__PURE__ */ jsx("li", { "data-slot": "pagination-item", ...props });
}
function PaginationLink({
  className,
  isActive,
  size = "icon",
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "a",
    {
      "aria-current": isActive ? "page" : void 0,
      "data-slot": "pagination-link",
      "data-active": isActive,
      className: cn(
        buttonVariants({
          variant: isActive ? "outline" : "ghost",
          size
        }),
        className
      ),
      ...props
    }
  );
}
function PaginationPrevious({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    PaginationLink,
    {
      "aria-label": "Go to previous page",
      size: "default",
      className: cn("gap-1 px-2.5 sm:pl-2.5", className),
      ...props,
      children: [
        /* @__PURE__ */ jsx(ChevronLeftIcon, {}),
        /* @__PURE__ */ jsx("span", { className: "hidden sm:block", children: "Previous" })
      ]
    }
  );
}
function PaginationNext({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    PaginationLink,
    {
      "aria-label": "Go to next page",
      size: "default",
      className: cn("gap-1 px-2.5 sm:pr-2.5", className),
      ...props,
      children: [
        /* @__PURE__ */ jsx("span", { className: "hidden sm:block", children: "Next" }),
        /* @__PURE__ */ jsx(ChevronRightIcon, {})
      ]
    }
  );
}
function PaginationEllipsis({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    "span",
    {
      "aria-hidden": true,
      "data-slot": "pagination-ellipsis",
      className: cn("flex size-9 items-center justify-center", className),
      ...props,
      children: [
        /* @__PURE__ */ jsx(MoreHorizontalIcon, { className: "size-4" }),
        /* @__PURE__ */ jsx("span", { className: "sr-only", children: "More pages" })
      ]
    }
  );
}
var PasswordInput = React2.forwardRef(
  ({ className, size, error, ...props }, ref) => {
    const [visible, setVisible] = React2.useState(false);
    return /* @__PURE__ */ jsxs("div", { "data-slot": "password-input", children: [
      /* @__PURE__ */ jsxs("div", { className: "relative", children: [
        /* @__PURE__ */ jsx(
          "input",
          {
            type: visible ? "text" : "password",
            ref,
            "data-slot": "input",
            "aria-invalid": error ? true : void 0,
            className: cn(inputVariants({ size, className: cn("pr-10 [&::-ms-reveal]:hidden [&::-webkit-credentials-auto-fill-button]:hidden", className) })),
            ...props
          }
        ),
        /* @__PURE__ */ jsx(
          "button",
          {
            type: "button",
            tabIndex: -1,
            className: "absolute right-0 top-0 flex h-full w-10 items-center justify-center text-muted-foreground hover:text-foreground transition-colors",
            onClick: () => setVisible((v) => !v),
            "aria-label": visible ? "Hide password" : "Show password",
            children: visible ? /* @__PURE__ */ jsx(EyeOff, { className: "h-4 w-4" }) : /* @__PURE__ */ jsx(Eye, { className: "h-4 w-4" })
          }
        )
      ] }),
      error ? /* @__PURE__ */ jsx("p", { className: "text-[11px] text-red-500 mt-1", children: error }) : null
    ] });
  }
);
PasswordInput.displayName = "PasswordInput";
function Progress({
  className,
  value,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    ProgressPrimitive.Root,
    {
      "data-slot": "progress",
      className: cn(
        "bg-primary/20 relative h-2 w-full overflow-hidden rounded-full",
        className
      ),
      ...props,
      children: /* @__PURE__ */ jsx(
        ProgressPrimitive.Indicator,
        {
          "data-slot": "progress-indicator",
          className: "bg-primary h-full w-full flex-1 transition-all",
          style: { transform: `translateX(-${100 - (value || 0)}%)` }
        }
      )
    }
  );
}
function RadioGroup4({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    RadioGroupPrimitive.Root,
    {
      "data-slot": "radio-group",
      className: cn("grid gap-3", className),
      ...props
    }
  );
}
function RadioGroupItem({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    RadioGroupPrimitive.Item,
    {
      "data-slot": "radio-group-item",
      className: cn(
        "border-input text-primary focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:bg-input/30 aspect-square size-4 shrink-0 rounded-full border shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50",
        className
      ),
      ...props,
      children: /* @__PURE__ */ jsx(
        RadioGroupPrimitive.Indicator,
        {
          "data-slot": "radio-group-indicator",
          className: "relative flex items-center justify-center",
          children: /* @__PURE__ */ jsx(CircleIcon, { className: "fill-primary absolute top-1/2 left-1/2 size-2 -translate-x-1/2 -translate-y-1/2" })
        }
      )
    }
  );
}
function Rating({
  value = 0,
  onChange,
  max = 5,
  size = "md",
  readonly = false,
  allowHalf = false,
  className
}) {
  const [hoverValue, setHoverValue] = React2.useState(null);
  const sizeClasses = {
    sm: "w-4 h-4",
    md: "w-5 h-5",
    lg: "w-6 h-6"
  };
  const handleClick = (index, isHalf) => {
    if (readonly) return;
    const newValue = isHalf ? index + 0.5 : index + 1;
    onChange?.(newValue);
  };
  const handleMouseMove = (index, e) => {
    if (readonly || !allowHalf) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const isHalf = x < rect.width / 2;
    setHoverValue(isHalf ? index + 0.5 : index + 1);
  };
  const handleMouseEnter = (index) => {
    if (readonly) return;
    if (!allowHalf) {
      setHoverValue(index + 1);
    }
  };
  const handleMouseLeave = () => {
    setHoverValue(null);
  };
  const getStarFill = (index) => {
    const currentValue = hoverValue !== null ? hoverValue : value;
    if (currentValue >= index + 1) {
      return "full";
    } else if (allowHalf && currentValue >= index + 0.5) {
      return "half";
    } else {
      return "empty";
    }
  };
  return /* @__PURE__ */ jsxs("div", { className: cn("flex items-center gap-1", className), children: [
    Array.from({ length: max }).map((_, index) => {
      const fill = getStarFill(index);
      return /* @__PURE__ */ jsx(
        "button",
        {
          type: "button",
          onClick: (e) => {
            if (!allowHalf) {
              handleClick(index, false);
              return;
            }
            const rect = e.currentTarget.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const isHalf = x < rect.width / 2;
            handleClick(index, isHalf);
          },
          onMouseMove: (e) => handleMouseMove(index, e),
          onMouseEnter: () => handleMouseEnter(index),
          onMouseLeave: handleMouseLeave,
          disabled: readonly,
          className: cn(
            "relative transition-transform hover:scale-110",
            !readonly && "cursor-pointer",
            readonly && "cursor-default"
          ),
          children: fill === "half" ? /* @__PURE__ */ jsxs("div", { className: "relative", children: [
            /* @__PURE__ */ jsx(
              Star,
              {
                className: cn(
                  sizeClasses[size],
                  "text-muted-foreground/40"
                )
              }
            ),
            /* @__PURE__ */ jsx("div", { className: "absolute inset-0 overflow-hidden w-1/2", children: /* @__PURE__ */ jsx(
              Star,
              {
                className: cn(
                  sizeClasses[size],
                  "text-yellow-400 fill-yellow-400"
                )
              }
            ) })
          ] }) : /* @__PURE__ */ jsx(
            Star,
            {
              className: cn(
                sizeClasses[size],
                fill === "full" ? "text-yellow-400 fill-yellow-400" : "text-muted-foreground/40"
              )
            }
          )
        },
        index
      );
    }),
    value > 0 && /* @__PURE__ */ jsx("span", { className: "ml-2 text-sm text-muted-foreground", children: value.toFixed(allowHalf ? 1 : 0) })
  ] });
}
function ResizablePanelGroup({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    ResizablePrimitive.PanelGroup,
    {
      "data-slot": "resizable-panel-group",
      className: cn(
        "flex h-full w-full data-[panel-group-direction=vertical]:flex-col",
        className
      ),
      ...props
    }
  );
}
function ResizablePanel({
  ...props
}) {
  return /* @__PURE__ */ jsx(ResizablePrimitive.Panel, { "data-slot": "resizable-panel", ...props });
}
function ResizableHandle({
  withHandle,
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    ResizablePrimitive.PanelResizeHandle,
    {
      "data-slot": "resizable-handle",
      className: cn(
        "bg-border focus-visible:ring-ring relative flex w-px items-center justify-center after:absolute after:inset-y-0 after:left-1/2 after:w-1 after:-translate-x-1/2 focus-visible:ring-1 focus-visible:ring-offset-1 focus-visible:outline-hidden data-[panel-group-direction=vertical]:h-px data-[panel-group-direction=vertical]:w-full data-[panel-group-direction=vertical]:after:left-0 data-[panel-group-direction=vertical]:after:h-1 data-[panel-group-direction=vertical]:after:w-full data-[panel-group-direction=vertical]:after:-translate-y-1/2 data-[panel-group-direction=vertical]:after:translate-x-0 [&[data-panel-group-direction=vertical]>div]:rotate-90",
        className
      ),
      ...props,
      children: withHandle && /* @__PURE__ */ jsx("div", { className: "bg-border z-10 flex h-4 w-3 items-center justify-center rounded-xs border", children: /* @__PURE__ */ jsx(GripVerticalIcon, { className: "size-2.5" }) })
    }
  );
}
function RichTextEditor({
  value,
  onChange,
  editable = true,
  className
}) {
  const editor = useEditor({
    extensions: [StarterKit],
    content: value ?? "",
    editable,
    immediatelyRender: false,
    editorProps: {
      attributes: {
        class: cn(
          "prose prose-sm dark:prose-invert max-w-none min-h-32 px-3 py-2",
          "focus:outline-none",
          "[&_p]:my-1 [&_h1]:mt-3 [&_h2]:mt-3 [&_h3]:mt-2"
        )
      }
    },
    onUpdate: ({ editor: editor2 }) => {
      onChange?.(editor2.getHTML());
    }
  });
  useEffect(() => {
    if (!editor) return;
    const next = value ?? "";
    if (next === editor.getHTML()) return;
    editor.commands.setContent(next, { emitUpdate: false });
  }, [editor, value]);
  if (!editor) {
    return /* @__PURE__ */ jsx(
      "div",
      {
        "data-slot": "rich-text-editor",
        className: cn(
          "rounded-md border border-input bg-background",
          "h-40 animate-pulse",
          className
        )
      }
    );
  }
  return /* @__PURE__ */ jsxs(
    "div",
    {
      "data-slot": "rich-text-editor",
      className: cn(
        "rounded-md border border-input bg-background",
        "focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50",
        className
      ),
      children: [
        editable ? /* @__PURE__ */ jsx(EditorToolbar, { editor }) : null,
        /* @__PURE__ */ jsx(EditorContent, { editor })
      ]
    }
  );
}
function EditorToolbar({ editor }) {
  return /* @__PURE__ */ jsxs("div", { className: "flex flex-wrap items-center gap-0.5 border-b border-border px-1.5 py-1", children: [
    /* @__PURE__ */ jsx(
      ToolbarButton,
      {
        onClick: () => editor.chain().focus().toggleBold().run(),
        active: editor.isActive("bold"),
        label: "Bold",
        children: /* @__PURE__ */ jsx(Bold, { className: "size-3.5" })
      }
    ),
    /* @__PURE__ */ jsx(
      ToolbarButton,
      {
        onClick: () => editor.chain().focus().toggleItalic().run(),
        active: editor.isActive("italic"),
        label: "Italic",
        children: /* @__PURE__ */ jsx(Italic, { className: "size-3.5" })
      }
    ),
    /* @__PURE__ */ jsx(
      ToolbarButton,
      {
        onClick: () => editor.chain().focus().toggleStrike().run(),
        active: editor.isActive("strike"),
        label: "Strikethrough",
        children: /* @__PURE__ */ jsx(Strikethrough, { className: "size-3.5" })
      }
    ),
    /* @__PURE__ */ jsx(
      ToolbarButton,
      {
        onClick: () => editor.chain().focus().toggleCode().run(),
        active: editor.isActive("code"),
        label: "Inline code",
        children: /* @__PURE__ */ jsx(Code, { className: "size-3.5" })
      }
    ),
    /* @__PURE__ */ jsx(Separator4, { orientation: "vertical", className: "mx-1 h-5" }),
    /* @__PURE__ */ jsx(
      ToolbarButton,
      {
        onClick: () => editor.chain().focus().toggleHeading({ level: 1 }).run(),
        active: editor.isActive("heading", { level: 1 }),
        label: "Heading 1",
        children: /* @__PURE__ */ jsx(Heading1, { className: "size-3.5" })
      }
    ),
    /* @__PURE__ */ jsx(
      ToolbarButton,
      {
        onClick: () => editor.chain().focus().toggleHeading({ level: 2 }).run(),
        active: editor.isActive("heading", { level: 2 }),
        label: "Heading 2",
        children: /* @__PURE__ */ jsx(Heading2, { className: "size-3.5" })
      }
    ),
    /* @__PURE__ */ jsx(
      ToolbarButton,
      {
        onClick: () => editor.chain().focus().toggleHeading({ level: 3 }).run(),
        active: editor.isActive("heading", { level: 3 }),
        label: "Heading 3",
        children: /* @__PURE__ */ jsx(Heading3, { className: "size-3.5" })
      }
    ),
    /* @__PURE__ */ jsx(Separator4, { orientation: "vertical", className: "mx-1 h-5" }),
    /* @__PURE__ */ jsx(
      ToolbarButton,
      {
        onClick: () => editor.chain().focus().toggleBulletList().run(),
        active: editor.isActive("bulletList"),
        label: "Bullet list",
        children: /* @__PURE__ */ jsx(List, { className: "size-3.5" })
      }
    ),
    /* @__PURE__ */ jsx(
      ToolbarButton,
      {
        onClick: () => editor.chain().focus().toggleOrderedList().run(),
        active: editor.isActive("orderedList"),
        label: "Ordered list",
        children: /* @__PURE__ */ jsx(ListOrdered, { className: "size-3.5" })
      }
    ),
    /* @__PURE__ */ jsx(
      ToolbarButton,
      {
        onClick: () => editor.chain().focus().toggleBlockquote().run(),
        active: editor.isActive("blockquote"),
        label: "Quote",
        children: /* @__PURE__ */ jsx(Quote, { className: "size-3.5" })
      }
    ),
    /* @__PURE__ */ jsx(Separator4, { orientation: "vertical", className: "mx-1 h-5" }),
    /* @__PURE__ */ jsx(
      ToolbarButton,
      {
        onClick: () => editor.chain().focus().undo().run(),
        disabled: !editor.can().undo(),
        label: "Undo",
        children: /* @__PURE__ */ jsx(Undo2, { className: "size-3.5" })
      }
    ),
    /* @__PURE__ */ jsx(
      ToolbarButton,
      {
        onClick: () => editor.chain().focus().redo().run(),
        disabled: !editor.can().redo(),
        label: "Redo",
        children: /* @__PURE__ */ jsx(Redo2, { className: "size-3.5" })
      }
    )
  ] });
}
function ToolbarButton({
  onClick,
  active,
  disabled,
  label,
  children
}) {
  return /* @__PURE__ */ jsx(
    Button,
    {
      type: "button",
      variant: "ghost",
      size: "xs",
      "aria-label": label,
      "aria-pressed": active,
      disabled,
      onClick,
      className: cn("size-6 p-0", active && "bg-muted text-foreground"),
      children
    }
  );
}
function ScrollArea({
  className,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    ScrollAreaPrimitive.Root,
    {
      "data-slot": "scroll-area",
      className: cn("relative", className),
      ...props,
      children: [
        /* @__PURE__ */ jsx(
          ScrollAreaPrimitive.Viewport,
          {
            "data-slot": "scroll-area-viewport",
            className: "focus-visible:ring-ring/50 size-full rounded-[inherit] transition-[color,box-shadow] outline-none focus-visible:ring-[3px] focus-visible:outline-1",
            children
          }
        ),
        /* @__PURE__ */ jsx(ScrollBar, {}),
        /* @__PURE__ */ jsx(ScrollAreaPrimitive.Corner, {})
      ]
    }
  );
}
function ScrollBar({
  className,
  orientation = "vertical",
  ...props
}) {
  return /* @__PURE__ */ jsx(
    ScrollAreaPrimitive.ScrollAreaScrollbar,
    {
      "data-slot": "scroll-area-scrollbar",
      orientation,
      className: cn(
        "flex touch-none p-px transition-colors select-none",
        orientation === "vertical" && "h-full w-2.5 border-l border-l-transparent",
        orientation === "horizontal" && "h-2.5 flex-col border-t border-t-transparent",
        className
      ),
      ...props,
      children: /* @__PURE__ */ jsx(
        ScrollAreaPrimitive.ScrollAreaThumb,
        {
          "data-slot": "scroll-area-thumb",
          className: "bg-border relative flex-1 rounded-full"
        }
      )
    }
  );
}
function Select({
  ...props
}) {
  return /* @__PURE__ */ jsx(SelectPrimitive.Root, { "data-slot": "select", ...props });
}
function SelectGroup({
  ...props
}) {
  return /* @__PURE__ */ jsx(SelectPrimitive.Group, { "data-slot": "select-group", ...props });
}
function SelectValue({
  ...props
}) {
  return /* @__PURE__ */ jsx(SelectPrimitive.Value, { "data-slot": "select-value", ...props });
}
var SelectTrigger = React2.forwardRef(({ className, size = "default", children, ...props }, ref) => {
  return /* @__PURE__ */ jsxs(
    SelectPrimitive.Trigger,
    {
      ref,
      "data-slot": "select-trigger",
      "data-size": size,
      className: cn(
        "border-input data-[placeholder]:text-muted-foreground [&_svg:not([class*='text-'])]:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:bg-input/30 dark:hover:bg-input/50 flex w-full items-center justify-between gap-2 rounded-md border bg-input-background px-3 py-2 text-sm whitespace-nowrap transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 data-[size=default]:h-element data-[size=sm]:h-element-sm *:data-[slot=select-value]:line-clamp-1 *:data-[slot=select-value]:flex *:data-[slot=select-value]:items-center *:data-[slot=select-value]:gap-2 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      ...props,
      children: [
        children,
        /* @__PURE__ */ jsx(SelectPrimitive.Icon, { asChild: true, children: /* @__PURE__ */ jsx(ChevronDownIcon, { className: "size-4 opacity-50" }) })
      ]
    }
  );
});
SelectTrigger.displayName = SelectPrimitive.Trigger.displayName;
function SelectContent({
  className,
  children,
  position = "popper",
  ...props
}) {
  return /* @__PURE__ */ jsx(SelectPrimitive.Portal, { children: /* @__PURE__ */ jsxs(
    SelectPrimitive.Content,
    {
      "data-slot": "select-content",
      className: cn(
        "bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 relative z-50 max-h-(--radix-select-content-available-height) min-w-[8rem] origin-(--radix-select-content-transform-origin) overflow-x-hidden overflow-y-auto rounded-md border shadow-md",
        position === "popper" && "data-[side=bottom]:translate-y-1 data-[side=left]:-translate-x-1 data-[side=right]:translate-x-1 data-[side=top]:-translate-y-1",
        className
      ),
      position,
      ...props,
      children: [
        /* @__PURE__ */ jsx(SelectScrollUpButton, {}),
        /* @__PURE__ */ jsx(
          SelectPrimitive.Viewport,
          {
            className: cn(
              "p-1",
              position === "popper" && "h-[var(--radix-select-trigger-height)] w-full min-w-[var(--radix-select-trigger-width)] scroll-my-1"
            ),
            children
          }
        ),
        /* @__PURE__ */ jsx(SelectScrollDownButton, {})
      ]
    }
  ) });
}
function SelectLabel({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    SelectPrimitive.Label,
    {
      "data-slot": "select-label",
      className: cn("text-muted-foreground px-2 py-1.5 text-xs", className),
      ...props
    }
  );
}
function SelectItem({
  className,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsxs(
    SelectPrimitive.Item,
    {
      "data-slot": "select-item",
      className: cn(
        "focus:bg-accent focus:text-accent-foreground [&_svg:not([class*='text-'])]:text-muted-foreground relative flex w-full cursor-default items-center gap-2 rounded-sm py-1.5 pr-8 pl-2 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4 *:[span]:last:flex *:[span]:last:items-center *:[span]:last:gap-2",
        className
      ),
      ...props,
      children: [
        /* @__PURE__ */ jsx("span", { className: "absolute right-2 flex size-3.5 items-center justify-center", children: /* @__PURE__ */ jsx(SelectPrimitive.ItemIndicator, { children: /* @__PURE__ */ jsx(CheckIcon, { className: "size-4" }) }) }),
        /* @__PURE__ */ jsx(SelectPrimitive.ItemText, { children })
      ]
    }
  );
}
function SelectSeparator({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    SelectPrimitive.Separator,
    {
      "data-slot": "select-separator",
      className: cn("bg-border pointer-events-none -mx-1 my-1 h-px", className),
      ...props
    }
  );
}
function SelectScrollUpButton({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    SelectPrimitive.ScrollUpButton,
    {
      "data-slot": "select-scroll-up-button",
      className: cn(
        "flex cursor-default items-center justify-center py-1",
        className
      ),
      ...props,
      children: /* @__PURE__ */ jsx(ChevronUpIcon, { className: "size-4" })
    }
  );
}
function SelectScrollDownButton({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    SelectPrimitive.ScrollDownButton,
    {
      "data-slot": "select-scroll-down-button",
      className: cn(
        "flex cursor-default items-center justify-center py-1",
        className
      ),
      ...props,
      children: /* @__PURE__ */ jsx(ChevronDownIcon, { className: "size-4" })
    }
  );
}
function Sheet({ ...props }) {
  return /* @__PURE__ */ jsx(DialogPrimitive.Root, { "data-slot": "sheet", ...props });
}
function SheetTrigger({
  ...props
}) {
  return /* @__PURE__ */ jsx(DialogPrimitive.Trigger, { "data-slot": "sheet-trigger", ...props });
}
function SheetClose({
  ...props
}) {
  return /* @__PURE__ */ jsx(DialogPrimitive.Close, { "data-slot": "sheet-close", ...props });
}
function SheetPortal({
  ...props
}) {
  return /* @__PURE__ */ jsx(DialogPrimitive.Portal, { "data-slot": "sheet-portal", ...props });
}
function SheetOverlay({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    DialogPrimitive.Overlay,
    {
      "data-slot": "sheet-overlay",
      className: cn(
        "data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/50",
        className
      ),
      ...props
    }
  );
}
function SheetContent({
  className,
  children,
  side = "right",
  ...props
}) {
  return /* @__PURE__ */ jsxs(SheetPortal, { children: [
    /* @__PURE__ */ jsx(SheetOverlay, {}),
    /* @__PURE__ */ jsxs(
      DialogPrimitive.Content,
      {
        "data-slot": "sheet-content",
        className: cn(
          "bg-background data-[state=open]:animate-in data-[state=closed]:animate-out fixed z-50 flex flex-col gap-4 shadow-lg transition ease-in-out data-[state=closed]:duration-300 data-[state=open]:duration-500",
          side === "right" && "data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right inset-y-0 right-0 h-full w-3/4 border-l sm:max-w-sm",
          side === "left" && "data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left inset-y-0 left-0 h-full w-3/4 border-r sm:max-w-sm",
          side === "top" && "data-[state=closed]:slide-out-to-top data-[state=open]:slide-in-from-top inset-x-0 top-0 h-auto border-b",
          side === "bottom" && "data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom inset-x-0 bottom-0 h-auto border-t",
          className
        ),
        ...props,
        children: [
          children,
          /* @__PURE__ */ jsxs(DialogPrimitive.Close, { className: "ring-offset-background focus:ring-ring data-[state=open]:bg-secondary absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none", children: [
            /* @__PURE__ */ jsx(XIcon, { className: "size-4" }),
            /* @__PURE__ */ jsx("span", { className: "sr-only", children: "Close" })
          ] })
        ]
      }
    )
  ] });
}
function SheetHeader({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "sheet-header",
      className: cn("flex flex-col gap-1.5 p-[var(--density-sheet)]", className),
      ...props
    }
  );
}
function SheetFooter({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "sheet-footer",
      className: cn("mt-auto flex flex-col gap-2 p-[var(--density-sheet)]", className),
      ...props
    }
  );
}
function SheetTitle({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    DialogPrimitive.Title,
    {
      "data-slot": "sheet-title",
      className: cn("text-foreground font-semibold", className),
      ...props
    }
  );
}
function SheetDescription({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    DialogPrimitive.Description,
    {
      "data-slot": "sheet-description",
      className: cn("text-muted-foreground text-sm", className),
      ...props
    }
  );
}
var MOBILE_BREAKPOINT = 768;
function useIsMobile() {
  const [isMobile, setIsMobile] = React2.useState(
    void 0
  );
  React2.useEffect(() => {
    const mql = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`);
    const onChange = () => {
      setIsMobile(window.innerWidth < MOBILE_BREAKPOINT);
    };
    mql.addEventListener("change", onChange);
    setIsMobile(window.innerWidth < MOBILE_BREAKPOINT);
    return () => mql.removeEventListener("change", onChange);
  }, []);
  return !!isMobile;
}
function Skeleton({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "skeleton",
      className: cn("bg-accent animate-pulse rounded-md", className),
      ...props
    }
  );
}
function TooltipProvider({
  delayDuration = 0,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    TooltipPrimitive.Provider,
    {
      "data-slot": "tooltip-provider",
      delayDuration,
      ...props
    }
  );
}
function Tooltip({
  ...props
}) {
  return /* @__PURE__ */ jsx(TooltipProvider, { children: /* @__PURE__ */ jsx(TooltipPrimitive.Root, { "data-slot": "tooltip", ...props }) });
}
function TooltipTrigger({
  ...props
}) {
  return /* @__PURE__ */ jsx(TooltipPrimitive.Trigger, { "data-slot": "tooltip-trigger", ...props });
}
function TooltipContent({
  className,
  sideOffset = 0,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsx(TooltipPrimitive.Portal, { children: /* @__PURE__ */ jsxs(
    TooltipPrimitive.Content,
    {
      "data-slot": "tooltip-content",
      sideOffset,
      className: cn(
        "bg-primary text-primary-foreground animate-in fade-in-0 zoom-in-95 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 w-fit origin-(--radix-tooltip-content-transform-origin) rounded-md px-3 py-1.5 text-xs text-balance",
        className
      ),
      ...props,
      children: [
        children,
        /* @__PURE__ */ jsx(TooltipPrimitive.Arrow, { className: "bg-primary fill-primary z-50 size-2.5 translate-y-[calc(-50%_-_2px)] rotate-45 rounded-[2px]" })
      ]
    }
  ) });
}
var SIDEBAR_COOKIE_NAME = "sidebar_state";
var SIDEBAR_COOKIE_MAX_AGE = 60 * 60 * 24 * 7;
var SIDEBAR_WIDTH = "16rem";
var SIDEBAR_WIDTH_MOBILE = "18rem";
var SIDEBAR_WIDTH_ICON = "3rem";
var SIDEBAR_KEYBOARD_SHORTCUT = "b";
var SidebarContext = React2.createContext(null);
function useSidebar() {
  const context = React2.useContext(SidebarContext);
  if (!context) {
    throw new Error("useSidebar must be used within a SidebarProvider.");
  }
  return context;
}
function SidebarProvider({
  defaultOpen = true,
  open: openProp,
  onOpenChange: setOpenProp,
  className,
  style,
  children,
  ...props
}) {
  const isMobile = useIsMobile();
  const [openMobile, setOpenMobile] = React2.useState(false);
  const [_open, _setOpen] = React2.useState(defaultOpen);
  const open = openProp ?? _open;
  const setOpen = React2.useCallback(
    (value) => {
      const openState = typeof value === "function" ? value(open) : value;
      if (setOpenProp) {
        setOpenProp(openState);
      } else {
        _setOpen(openState);
      }
      document.cookie = `${SIDEBAR_COOKIE_NAME}=${openState}; path=/; max-age=${SIDEBAR_COOKIE_MAX_AGE}`;
    },
    [setOpenProp, open]
  );
  const toggleSidebar = React2.useCallback(() => {
    return isMobile ? setOpenMobile((open2) => !open2) : setOpen((open2) => !open2);
  }, [isMobile, setOpen, setOpenMobile]);
  React2.useEffect(() => {
    const handleKeyDown = (event) => {
      if (event.key === SIDEBAR_KEYBOARD_SHORTCUT && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        toggleSidebar();
      }
    };
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [toggleSidebar]);
  const state = open ? "expanded" : "collapsed";
  const contextValue = React2.useMemo(
    () => ({
      state,
      open,
      setOpen,
      isMobile,
      openMobile,
      setOpenMobile,
      toggleSidebar
    }),
    [state, open, setOpen, isMobile, openMobile, setOpenMobile, toggleSidebar]
  );
  return /* @__PURE__ */ jsx(SidebarContext.Provider, { value: contextValue, children: /* @__PURE__ */ jsx(TooltipProvider, { delayDuration: 0, children: /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "sidebar-wrapper",
      style: {
        "--sidebar-width": SIDEBAR_WIDTH,
        "--sidebar-width-icon": SIDEBAR_WIDTH_ICON,
        ...style
      },
      className: cn(
        "group/sidebar-wrapper flex min-h-svh w-full has-data-[variant=inset]:bg-sidebar",
        className
      ),
      ...props,
      children
    }
  ) }) });
}
function Sidebar({
  side = "left",
  variant = "sidebar",
  collapsible = "offcanvas",
  className,
  children,
  ...props
}) {
  const { isMobile, state, openMobile, setOpenMobile } = useSidebar();
  if (collapsible === "none") {
    return /* @__PURE__ */ jsx(
      "div",
      {
        "data-slot": "sidebar",
        className: cn(
          "flex h-full w-(--sidebar-width) flex-col bg-sidebar text-sidebar-foreground",
          className
        ),
        ...props,
        children
      }
    );
  }
  if (isMobile) {
    return /* @__PURE__ */ jsx(Sheet, { open: openMobile, onOpenChange: setOpenMobile, ...props, children: /* @__PURE__ */ jsxs(
      SheetContent,
      {
        "data-sidebar": "sidebar",
        "data-slot": "sidebar",
        "data-mobile": "true",
        className: "w-(--sidebar-width) bg-sidebar p-0 text-sidebar-foreground [&>button]:hidden",
        style: {
          "--sidebar-width": SIDEBAR_WIDTH_MOBILE
        },
        side,
        children: [
          /* @__PURE__ */ jsxs(SheetHeader, { className: "sr-only", children: [
            /* @__PURE__ */ jsx(SheetTitle, { children: "Sidebar" }),
            /* @__PURE__ */ jsx(SheetDescription, { children: "Displays the mobile sidebar." })
          ] }),
          /* @__PURE__ */ jsx("div", { className: "flex h-full w-full flex-col", children })
        ]
      }
    ) });
  }
  return /* @__PURE__ */ jsxs(
    "div",
    {
      className: "group peer hidden text-sidebar-foreground md:block",
      "data-state": state,
      "data-collapsible": state === "collapsed" ? collapsible : "",
      "data-variant": variant,
      "data-side": side,
      "data-slot": "sidebar",
      children: [
        /* @__PURE__ */ jsx(
          "div",
          {
            "data-slot": "sidebar-gap",
            className: cn(
              "relative w-(--sidebar-width) bg-transparent transition-[width] duration-200 ease-linear",
              "group-data-[collapsible=offcanvas]:w-0",
              "group-data-[side=right]:rotate-180",
              variant === "floating" || variant === "inset" ? "group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4)))]" : "group-data-[collapsible=icon]:w-(--sidebar-width-icon)"
            )
          }
        ),
        /* @__PURE__ */ jsx(
          "div",
          {
            "data-slot": "sidebar-container",
            className: cn(
              "fixed inset-y-0 z-10 hidden h-svh w-(--sidebar-width) transition-[left,right,width] duration-200 ease-linear md:flex",
              side === "left" ? "left-0 group-data-[collapsible=offcanvas]:left-[calc(var(--sidebar-width)*-1)]" : "right-0 group-data-[collapsible=offcanvas]:right-[calc(var(--sidebar-width)*-1)]",
              // Adjust the padding for floating and inset variants.
              variant === "floating" || variant === "inset" ? "p-2 group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4))+2px)]" : "group-data-[collapsible=icon]:w-(--sidebar-width-icon) group-data-[side=left]:border-r group-data-[side=right]:border-l",
              className
            ),
            ...props,
            children: /* @__PURE__ */ jsx(
              "div",
              {
                "data-sidebar": "sidebar",
                "data-slot": "sidebar-inner",
                className: "flex h-full w-full flex-col bg-sidebar group-data-[variant=floating]:rounded-lg group-data-[variant=floating]:border group-data-[variant=floating]:border-sidebar-border group-data-[variant=floating]:shadow-sm",
                children
              }
            )
          }
        )
      ]
    }
  );
}
function SidebarTrigger({
  className,
  onClick,
  ...props
}) {
  const { toggleSidebar } = useSidebar();
  return /* @__PURE__ */ jsxs(
    Button,
    {
      "data-sidebar": "trigger",
      "data-slot": "sidebar-trigger",
      variant: "ghost",
      size: "icon",
      className: cn("size-7", className),
      onClick: (event) => {
        onClick?.(event);
        toggleSidebar();
      },
      ...props,
      children: [
        /* @__PURE__ */ jsx(PanelLeftIcon, {}),
        /* @__PURE__ */ jsx("span", { className: "sr-only", children: "Toggle Sidebar" })
      ]
    }
  );
}
function SidebarRail({ className, ...props }) {
  const { toggleSidebar } = useSidebar();
  return /* @__PURE__ */ jsx(
    "button",
    {
      "data-sidebar": "rail",
      "data-slot": "sidebar-rail",
      "aria-label": "Toggle Sidebar",
      tabIndex: -1,
      onClick: toggleSidebar,
      title: "Toggle Sidebar",
      className: cn(
        "absolute inset-y-0 z-20 hidden w-4 -translate-x-1/2 transition-all ease-linear group-data-[side=left]:-right-4 group-data-[side=right]:left-0 after:absolute after:inset-y-0 after:left-1/2 after:w-[2px] hover:after:bg-sidebar-border sm:flex",
        "in-data-[side=left]:cursor-w-resize in-data-[side=right]:cursor-e-resize",
        "[[data-side=left][data-state=collapsed]_&]:cursor-e-resize [[data-side=right][data-state=collapsed]_&]:cursor-w-resize",
        "group-data-[collapsible=offcanvas]:translate-x-0 group-data-[collapsible=offcanvas]:after:left-full hover:group-data-[collapsible=offcanvas]:bg-sidebar",
        "[[data-side=left][data-collapsible=offcanvas]_&]:-right-2",
        "[[data-side=right][data-collapsible=offcanvas]_&]:-left-2",
        className
      ),
      ...props
    }
  );
}
function SidebarInset({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "main",
    {
      "data-slot": "sidebar-inset",
      className: cn(
        "relative flex w-full flex-1 flex-col bg-background",
        "md:peer-data-[variant=inset]:m-2 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-xl md:peer-data-[variant=inset]:shadow-sm md:peer-data-[variant=inset]:peer-data-[state=collapsed]:ml-2",
        className
      ),
      ...props
    }
  );
}
function SidebarInput({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    Input,
    {
      "data-slot": "sidebar-input",
      "data-sidebar": "input",
      className: cn("h-8 w-full bg-background shadow-none", className),
      ...props
    }
  );
}
function SidebarHeader({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "sidebar-header",
      "data-sidebar": "header",
      className: cn("flex flex-col gap-2 p-2", className),
      ...props
    }
  );
}
function SidebarFooter({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "sidebar-footer",
      "data-sidebar": "footer",
      className: cn("flex flex-col gap-2 p-2", className),
      ...props
    }
  );
}
function SidebarSeparator({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    Separator4,
    {
      "data-slot": "sidebar-separator",
      "data-sidebar": "separator",
      className: cn("mx-2 w-auto bg-sidebar-border", className),
      ...props
    }
  );
}
function SidebarContent({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "sidebar-content",
      "data-sidebar": "content",
      className: cn(
        "flex min-h-0 flex-1 flex-col gap-2 overflow-auto group-data-[collapsible=icon]:overflow-hidden",
        className
      ),
      ...props
    }
  );
}
function SidebarGroup({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "sidebar-group",
      "data-sidebar": "group",
      className: cn("relative flex w-full min-w-0 flex-col p-2", className),
      ...props
    }
  );
}
function SidebarGroupLabel({
  className,
  asChild = false,
  ...props
}) {
  const Comp = asChild ? Slot : "div";
  return /* @__PURE__ */ jsx(
    Comp,
    {
      "data-slot": "sidebar-group-label",
      "data-sidebar": "group-label",
      className: cn(
        "flex h-8 shrink-0 items-center rounded-md px-2 text-xs font-medium text-sidebar-foreground/70 ring-sidebar-ring outline-hidden transition-[margin,opacity] duration-200 ease-linear focus-visible:ring-2 [&>svg]:size-4 [&>svg]:shrink-0",
        "group-data-[collapsible=icon]:-mt-8 group-data-[collapsible=icon]:opacity-0",
        className
      ),
      ...props
    }
  );
}
function SidebarGroupAction({
  className,
  asChild = false,
  ...props
}) {
  const Comp = asChild ? Slot : "button";
  return /* @__PURE__ */ jsx(
    Comp,
    {
      "data-slot": "sidebar-group-action",
      "data-sidebar": "group-action",
      className: cn(
        "absolute top-3.5 right-3 flex aspect-square w-5 items-center justify-center rounded-md p-0 text-sidebar-foreground ring-sidebar-ring outline-hidden transition-transform hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 [&>svg]:size-4 [&>svg]:shrink-0",
        // Increases the hit area of the button on mobile.
        "after:absolute after:-inset-2 md:after:hidden",
        "group-data-[collapsible=icon]:hidden",
        className
      ),
      ...props
    }
  );
}
function SidebarGroupContent({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "sidebar-group-content",
      "data-sidebar": "group-content",
      className: cn("w-full text-sm", className),
      ...props
    }
  );
}
function SidebarMenu({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "ul",
    {
      "data-slot": "sidebar-menu",
      "data-sidebar": "menu",
      className: cn("flex w-full min-w-0 flex-col gap-1", className),
      ...props
    }
  );
}
function SidebarMenuItem({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "li",
    {
      "data-slot": "sidebar-menu-item",
      "data-sidebar": "menu-item",
      className: cn("group/menu-item relative", className),
      ...props
    }
  );
}
var sidebarMenuButtonVariants = cva(
  "peer/menu-button flex w-full items-center gap-2 overflow-hidden rounded-md p-2 text-left text-sm ring-sidebar-ring outline-hidden transition-[width,height,padding] group-has-data-[sidebar=menu-action]/menu-item:pr-8 group-data-[collapsible=icon]:size-8! group-data-[collapsible=icon]:p-2! hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 active:bg-sidebar-accent active:text-sidebar-accent-foreground disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 data-[active=true]:bg-sidebar-accent data-[active=true]:font-medium data-[active=true]:text-sidebar-accent-foreground data-[state=open]:hover:bg-sidebar-accent data-[state=open]:hover:text-sidebar-accent-foreground [&>span:last-child]:truncate [&>svg]:size-4 [&>svg]:shrink-0",
  {
    variants: {
      variant: {
        default: "hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
        outline: "bg-background shadow-[0_0_0_1px_hsl(var(--sidebar-border))] hover:bg-sidebar-accent hover:text-sidebar-accent-foreground hover:shadow-[0_0_0_1px_hsl(var(--sidebar-accent))]"
      },
      size: {
        default: "h-8 text-sm",
        sm: "h-7 text-xs",
        lg: "h-12 text-sm group-data-[collapsible=icon]:p-0!"
      }
    },
    defaultVariants: {
      variant: "default",
      size: "default"
    }
  }
);
function SidebarMenuButton({
  asChild = false,
  isActive = false,
  variant = "default",
  size = "default",
  tooltip,
  className,
  ...props
}) {
  const Comp = asChild ? Slot : "button";
  const { isMobile, state } = useSidebar();
  const button = /* @__PURE__ */ jsx(
    Comp,
    {
      "data-slot": "sidebar-menu-button",
      "data-sidebar": "menu-button",
      "data-size": size,
      "data-active": isActive,
      className: cn(sidebarMenuButtonVariants({ variant, size }), className),
      ...props
    }
  );
  if (!tooltip) {
    return button;
  }
  if (typeof tooltip === "string") {
    tooltip = {
      children: tooltip
    };
  }
  return /* @__PURE__ */ jsxs(Tooltip, { children: [
    /* @__PURE__ */ jsx(TooltipTrigger, { asChild: true, children: button }),
    /* @__PURE__ */ jsx(
      TooltipContent,
      {
        side: "right",
        align: "center",
        hidden: state !== "collapsed" || isMobile,
        ...tooltip
      }
    )
  ] });
}
function SidebarMenuAction({
  className,
  asChild = false,
  showOnHover = false,
  ...props
}) {
  const Comp = asChild ? Slot : "button";
  return /* @__PURE__ */ jsx(
    Comp,
    {
      "data-slot": "sidebar-menu-action",
      "data-sidebar": "menu-action",
      className: cn(
        "absolute top-1.5 right-1 flex aspect-square w-5 items-center justify-center rounded-md p-0 text-sidebar-foreground ring-sidebar-ring outline-hidden transition-transform peer-hover/menu-button:text-sidebar-accent-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 [&>svg]:size-4 [&>svg]:shrink-0",
        // Increases the hit area of the button on mobile.
        "after:absolute after:-inset-2 md:after:hidden",
        "peer-data-[size=sm]/menu-button:top-1",
        "peer-data-[size=default]/menu-button:top-1.5",
        "peer-data-[size=lg]/menu-button:top-2.5",
        "group-data-[collapsible=icon]:hidden",
        showOnHover && "group-focus-within/menu-item:opacity-100 group-hover/menu-item:opacity-100 peer-data-[active=true]/menu-button:text-sidebar-accent-foreground data-[state=open]:opacity-100 md:opacity-0",
        className
      ),
      ...props
    }
  );
}
function SidebarMenuBadge({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "sidebar-menu-badge",
      "data-sidebar": "menu-badge",
      className: cn(
        "pointer-events-none absolute right-1 flex h-5 min-w-5 items-center justify-center rounded-md px-1 text-xs font-medium text-sidebar-foreground tabular-nums select-none",
        "peer-hover/menu-button:text-sidebar-accent-foreground peer-data-[active=true]/menu-button:text-sidebar-accent-foreground",
        "peer-data-[size=sm]/menu-button:top-1",
        "peer-data-[size=default]/menu-button:top-1.5",
        "peer-data-[size=lg]/menu-button:top-2.5",
        "group-data-[collapsible=icon]:hidden",
        className
      ),
      ...props
    }
  );
}
function SidebarMenuSkeleton({
  className,
  showIcon = false,
  ...props
}) {
  const [width, setWidth] = React2.useState("70%");
  React2.useEffect(() => {
    setWidth(`${Math.floor(Math.random() * 40) + 50}%`);
  }, []);
  return /* @__PURE__ */ jsxs(
    "div",
    {
      "data-slot": "sidebar-menu-skeleton",
      "data-sidebar": "menu-skeleton",
      className: cn("flex h-8 items-center gap-2 rounded-md px-2", className),
      ...props,
      children: [
        showIcon && /* @__PURE__ */ jsx(
          Skeleton,
          {
            className: "size-4 rounded-md",
            "data-sidebar": "menu-skeleton-icon"
          }
        ),
        /* @__PURE__ */ jsx(
          Skeleton,
          {
            className: "h-4 max-w-(--skeleton-width) flex-1",
            "data-sidebar": "menu-skeleton-text",
            style: {
              "--skeleton-width": width
            }
          }
        )
      ]
    }
  );
}
function SidebarMenuSub({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "ul",
    {
      "data-slot": "sidebar-menu-sub",
      "data-sidebar": "menu-sub",
      className: cn(
        "mx-3.5 flex min-w-0 translate-x-px flex-col gap-1 border-l border-sidebar-border px-2.5 py-0.5",
        "group-data-[collapsible=icon]:hidden",
        className
      ),
      ...props
    }
  );
}
function SidebarMenuSubItem({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "li",
    {
      "data-slot": "sidebar-menu-sub-item",
      "data-sidebar": "menu-sub-item",
      className: cn("group/menu-sub-item relative", className),
      ...props
    }
  );
}
function SidebarMenuSubButton({
  asChild = false,
  size = "md",
  isActive = false,
  className,
  ...props
}) {
  const Comp = asChild ? Slot : "a";
  return /* @__PURE__ */ jsx(
    Comp,
    {
      "data-slot": "sidebar-menu-sub-button",
      "data-sidebar": "menu-sub-button",
      "data-size": size,
      "data-active": isActive,
      className: cn(
        "flex h-7 min-w-0 -translate-x-px items-center gap-2 overflow-hidden rounded-md px-2 text-sidebar-foreground ring-sidebar-ring outline-hidden hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 active:bg-sidebar-accent active:text-sidebar-accent-foreground disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 [&>span:last-child]:truncate [&>svg]:size-4 [&>svg]:shrink-0 [&>svg]:text-sidebar-accent-foreground",
        "data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground",
        size === "sm" && "text-xs",
        size === "md" && "text-sm",
        "group-data-[collapsible=icon]:hidden",
        className
      ),
      ...props
    }
  );
}
function Slider({
  className,
  defaultValue,
  value,
  min = 0,
  max = 100,
  ...props
}) {
  const _values = React2.useMemo(
    () => Array.isArray(value) ? value : Array.isArray(defaultValue) ? defaultValue : [min, max],
    [value, defaultValue, min, max]
  );
  return /* @__PURE__ */ jsxs(
    SliderPrimitive.Root,
    {
      "data-slot": "slider",
      defaultValue,
      value,
      min,
      max,
      className: cn(
        "relative flex w-full touch-none items-center select-none data-[disabled]:opacity-50 data-[orientation=vertical]:h-full data-[orientation=vertical]:min-h-44 data-[orientation=vertical]:w-auto data-[orientation=vertical]:flex-col",
        className
      ),
      ...props,
      children: [
        /* @__PURE__ */ jsx(
          SliderPrimitive.Track,
          {
            "data-slot": "slider-track",
            className: cn(
              "bg-muted relative grow overflow-hidden rounded-full data-[orientation=horizontal]:h-4 data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-1.5"
            ),
            children: /* @__PURE__ */ jsx(
              SliderPrimitive.Range,
              {
                "data-slot": "slider-range",
                className: cn(
                  "bg-primary absolute data-[orientation=horizontal]:h-full data-[orientation=vertical]:w-full"
                )
              }
            )
          }
        ),
        Array.from({ length: _values.length }, (_, index) => /* @__PURE__ */ jsx(
          SliderPrimitive.Thumb,
          {
            "data-slot": "slider-thumb",
            className: "border-primary bg-background ring-ring/50 block size-4 shrink-0 rounded-full border shadow-sm transition-[color,box-shadow] hover:ring-4 focus-visible:ring-4 focus-visible:outline-hidden disabled:pointer-events-none disabled:opacity-50"
          },
          index
        ))
      ]
    }
  );
}
var defaultLabels = {
  slug: "Slug",
  autoGenerated: "Auto-generated from title",
  placeholder: "enter-slug-here"
};
function generateSlug(text) {
  return text.toLowerCase().replace(/[àáạảãâầấậẩẫăằắặẳẵ]/g, "a").replace(/[èéẹẻẽêềếệểễ]/g, "e").replace(/[ìíịỉĩ]/g, "i").replace(/[òóọỏõôồốộổỗơờớợởỡ]/g, "o").replace(/[ùúụủũưừứựửữ]/g, "u").replace(/[ỳýỵỷỹ]/g, "y").replace(/đ/g, "d").replace(/[^a-z0-9\s-]/g, "").replace(/\s+/g, "-").replace(/-+/g, "-").replace(/^-|-$/g, "");
}
function SlugInput({
  title,
  slug,
  onSlugChange,
  disabled = false,
  labels: labelOverrides,
  error
}) {
  const labels = { ...defaultLabels, ...labelOverrides };
  useEffect(() => {
    if (!disabled && title) {
      onSlugChange(generateSlug(title));
    }
  }, [title, disabled, onSlugChange]);
  return /* @__PURE__ */ jsxs("div", { "data-slot": "slug-input", children: [
    /* @__PURE__ */ jsx(Label3, { children: labels.slug }),
    /* @__PURE__ */ jsx(
      Input,
      {
        value: slug,
        onChange: (e) => onSlugChange(e.target.value),
        placeholder: labels.placeholder,
        "aria-invalid": error ? true : void 0,
        className: "h-element-sm mt-1 font-mono text-sm"
      }
    ),
    error ? /* @__PURE__ */ jsx("p", { className: "text-[11px] text-red-500 mt-1", children: error }) : /* @__PURE__ */ jsx("p", { className: "text-xs text-muted-foreground mt-1", children: labels.autoGenerated })
  ] });
}
var Toaster = ({ ...props }) => {
  return /* @__PURE__ */ jsx(
    Toaster$1,
    {
      theme: "light",
      className: "toaster group",
      style: {
        "--normal-bg": "var(--popover)",
        "--normal-text": "var(--popover-foreground)",
        "--normal-border": "var(--border)"
      },
      ...props
    }
  );
};
function Spinner({ className, ...props }) {
  return /* @__PURE__ */ jsx(Loader2Icon, { role: "status", "aria-label": "Loading", className: cn("size-4 animate-spin", className), ...props });
}
var statusStyles = {
  draft: "border-transparent bg-muted text-muted-foreground",
  pending: "border-transparent bg-amber-50 text-amber-700",
  approved: "border-transparent bg-blue-50 text-blue-700",
  active: "border-transparent bg-green-50 text-green-700",
  inactive: "border-transparent bg-muted text-muted-foreground",
  rejected: "border-transparent bg-red-50 text-red-700",
  deleted: "border-transparent bg-red-50 text-red-400 line-through",
  completed: "border-transparent bg-green-50 text-green-700",
  cancelled: "border-transparent bg-muted text-muted-foreground line-through",
  hidden: "border-transparent bg-amber-50 text-amber-700",
  visible: "border-transparent bg-green-50 text-green-700",
  in_progress: "border-transparent bg-blue-50 text-blue-700",
  in_transit: "border-transparent bg-blue-50 text-blue-700",
  pending_approval: "border-transparent bg-amber-50 text-amber-700"
};
function StatusBadge({ status, className }) {
  const style = statusStyles[status] ?? statusStyles.draft;
  const label = status.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
  return /* @__PURE__ */ jsx(
    Badge,
    {
      "data-slot": "status-badge",
      variant: "outline",
      className: `h-5 px-1.5 text-xs font-medium ${style} ${className ?? ""}`,
      children: label
    }
  );
}
function Switch({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    SwitchPrimitives.Root,
    {
      "data-slot": "switch",
      className: cn(
        "peer data-[state=checked]:bg-primary data-[state=unchecked]:bg-switch-background focus-visible:border-ring focus-visible:ring-ring/50 dark:data-[state=unchecked]:bg-input/80 inline-flex h-[1.15rem] w-8 shrink-0 items-center rounded-full border border-transparent transition-all outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50",
        className
      ),
      ...props,
      children: /* @__PURE__ */ jsx(
        SwitchPrimitives.Thumb,
        {
          "data-slot": "switch-thumb",
          className: cn(
            "bg-card dark:data-[state=unchecked]:bg-card-foreground dark:data-[state=checked]:bg-primary-foreground pointer-events-none block size-4 rounded-full ring-0 transition-transform data-[state=checked]:translate-x-[calc(100%-2px)] data-[state=unchecked]:translate-x-0"
          )
        }
      )
    }
  );
}
function Table({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "div",
    {
      "data-slot": "table-container",
      className: "relative w-full overflow-x-auto",
      children: /* @__PURE__ */ jsx(
        "table",
        {
          "data-slot": "table",
          className: cn("w-full caption-bottom text-sm", className),
          ...props
        }
      )
    }
  );
}
function TableHeader({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "thead",
    {
      "data-slot": "table-header",
      className: cn("[&_tr]:border-b", className),
      ...props
    }
  );
}
function TableBody({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "tbody",
    {
      "data-slot": "table-body",
      className: cn("[&_tr:last-child]:border-0", className),
      ...props
    }
  );
}
function TableFooter({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "tfoot",
    {
      "data-slot": "table-footer",
      className: cn(
        "bg-muted/50 border-t font-medium [&>tr]:last:border-b-0",
        className
      ),
      ...props
    }
  );
}
function TableRow({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "tr",
    {
      "data-slot": "table-row",
      className: cn(
        "hover:bg-muted/50 data-[state=selected]:bg-muted border-b transition-colors",
        className
      ),
      ...props
    }
  );
}
function TableHead({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "th",
    {
      "data-slot": "table-head",
      className: cn(
        "text-foreground h-table-head px-2 text-left align-middle font-medium whitespace-nowrap [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[2px]",
        className
      ),
      ...props
    }
  );
}
function TableCell({ className, ...props }) {
  return /* @__PURE__ */ jsx(
    "td",
    {
      "data-slot": "table-cell",
      className: cn(
        "p-2 align-middle whitespace-nowrap [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[2px]",
        className
      ),
      ...props
    }
  );
}
function TableCaption({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    "caption",
    {
      "data-slot": "table-caption",
      className: cn("text-muted-foreground mt-4 text-sm", className),
      ...props
    }
  );
}
function Tabs({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    TabsPrimitive.Root,
    {
      "data-slot": "tabs",
      className: cn("flex flex-col gap-2", className),
      ...props
    }
  );
}
function TabsList({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    TabsPrimitive.List,
    {
      "data-slot": "tabs-list",
      className: cn(
        "bg-muted text-muted-foreground inline-flex h-element w-fit items-center justify-center rounded-xl p-[3px] flex",
        className
      ),
      ...props
    }
  );
}
function TabsTrigger({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    TabsPrimitive.Trigger,
    {
      "data-slot": "tabs-trigger",
      className: cn(
        "data-[state=active]:bg-card dark:data-[state=active]:text-foreground focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:outline-ring dark:data-[state=active]:border-input dark:data-[state=active]:bg-input/30 text-foreground dark:text-muted-foreground inline-flex h-[calc(100%-1px)] flex-1 items-center justify-center gap-1.5 rounded-xl border border-transparent px-2 py-1 text-sm font-medium whitespace-nowrap transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:outline-1 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className
      ),
      ...props
    }
  );
}
function TabsContent({
  className,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    TabsPrimitive.Content,
    {
      "data-slot": "tabs-content",
      className: cn("flex-1 outline-none", className),
      ...props
    }
  );
}
function TagInput({
  value = [],
  onChange,
  placeholder = "Type and press Enter...",
  className,
  disabled,
  maxTags,
  allowDuplicates = false,
  delimiter = ",",
  error
}) {
  const [inputValue, setInputValue] = React2.useState("");
  const inputRef = React2.useRef(null);
  const handleInputChange = (e) => {
    setInputValue(e.target.value);
  };
  const addTag = (tag) => {
    const trimmedTag = tag.trim();
    if (!trimmedTag) return;
    if (maxTags && value.length >= maxTags) return;
    if (!allowDuplicates && value.includes(trimmedTag)) return;
    onChange?.([...value, trimmedTag]);
    setInputValue("");
  };
  const handleKeyDown = (e) => {
    if (e.key === "Enter" || e.key === delimiter) {
      e.preventDefault();
      addTag(inputValue);
    } else if (e.key === "Backspace" && !inputValue && value.length > 0) {
      onChange?.(value.slice(0, -1));
    }
  };
  const handlePaste = (e) => {
    e.preventDefault();
    const pastedText = e.clipboardData.getData("text");
    const tags = pastedText.split(delimiter).map((tag) => tag.trim()).filter(Boolean);
    const newTags = allowDuplicates ? tags : tags.filter((tag) => !value.includes(tag));
    const tagsToAdd = maxTags ? newTags.slice(0, maxTags - value.length) : newTags;
    onChange?.([...value, ...tagsToAdd]);
    setInputValue("");
  };
  const removeTag = (index) => {
    onChange?.(value.filter((_, i) => i !== index));
  };
  return /* @__PURE__ */ jsxs("div", { "data-slot": "tag-input-wrapper", children: [
    /* @__PURE__ */ jsxs(
      "div",
      {
        "data-slot": "tag-input",
        className: cn(
          "flex flex-wrap gap-2 p-2 border rounded-lg bg-background min-h-[42px] cursor-text",
          disabled && "opacity-50 cursor-not-allowed bg-muted",
          error && "border-destructive ring-2 ring-destructive/20",
          className
        ),
        onClick: () => !disabled && inputRef.current?.focus(),
        children: [
          value.map((tag, index) => /* @__PURE__ */ jsxs(
            Badge,
            {
              variant: "secondary",
              className: "gap-1 pl-2 pr-1 py-1 h-auto",
              children: [
                /* @__PURE__ */ jsx("span", { children: tag }),
                !disabled && /* @__PURE__ */ jsx(
                  "button",
                  {
                    type: "button",
                    onClick: (e) => {
                      e.stopPropagation();
                      removeTag(index);
                    },
                    className: "rounded-full hover:bg-muted-foreground/30 p-0.5 transition-colors",
                    children: /* @__PURE__ */ jsx(X, { className: "h-3 w-3" })
                  }
                )
              ]
            },
            index
          )),
          /* @__PURE__ */ jsx(
            "input",
            {
              ref: inputRef,
              type: "text",
              value: inputValue,
              onChange: handleInputChange,
              onKeyDown: handleKeyDown,
              onPaste: handlePaste,
              disabled: disabled || (maxTags ? value.length >= maxTags : false),
              placeholder: value.length === 0 ? placeholder : "",
              "aria-invalid": error ? true : void 0,
              className: "flex-1 outline-none bg-transparent min-w-[120px] text-sm disabled:cursor-not-allowed"
            }
          )
        ]
      }
    ),
    error ? /* @__PURE__ */ jsx("p", { className: "text-[11px] text-red-500 mt-1", children: error }) : null
  ] });
}
var textareaClass = "resize-none border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:bg-input/30 flex field-sizing-content min-h-16 w-full rounded-md border bg-input-background px-3 py-2 text-base transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 md:text-sm";
var Textarea = React2.forwardRef(
  (props, ref) => {
    const { className, translatable, ...rest } = props;
    const providerLocales = useUILocales();
    if (translatable !== void 0) {
      const config = resolveTranslatableConfig(translatable, providerLocales);
      if (!config) {
        const { value: _v, onChange: _oc, ...textareaRest3 } = rest;
        return /* @__PURE__ */ jsx(
          "textarea",
          {
            ref,
            "data-slot": "textarea",
            className: cn(textareaClass, className),
            ...textareaRest3
          }
        );
      }
      const { value: value2 = {}, onChange: onChange2, errors, ...textareaRest2 } = rest;
      return /* @__PURE__ */ jsx(
        TranslatableField,
        {
          config,
          value: value2,
          onChange: onChange2 ?? (() => {
          }),
          errors,
          children: ({ value: localeValue, onChange: localeChange, fallbackPlaceholder, hasError }) => /* @__PURE__ */ jsx(
            "textarea",
            {
              ref,
              "data-slot": "textarea",
              "data-translatable": true,
              className: cn(textareaClass, className),
              value: localeValue,
              placeholder: fallbackPlaceholder ?? textareaRest2.placeholder,
              onChange: (e) => localeChange(e.target.value),
              ...textareaRest2,
              "aria-invalid": hasError || textareaRest2["aria-invalid"] || void 0
            }
          )
        }
      );
    }
    const { value, onChange, error, ...textareaRest } = rest;
    const ariaInvalid = error !== void 0 && error !== "" ? true : textareaRest["aria-invalid"];
    return /* @__PURE__ */ jsxs(Fragment, { children: [
      /* @__PURE__ */ jsx(
        "textarea",
        {
          ref,
          "data-slot": "textarea",
          className: cn(textareaClass, className),
          value,
          onChange,
          "aria-invalid": ariaInvalid,
          ...textareaRest
        }
      ),
      error ? /* @__PURE__ */ jsx("p", { "data-slot": "textarea-error", className: "mt-1 text-sm text-destructive", children: error }) : null
    ] });
  }
);
Textarea.displayName = "Textarea";
function TimePicker({
  value,
  onChange,
  placeholder = "Ch\u1ECDn gi\u1EDD",
  className,
  disabled,
  format24h = true,
  error
}) {
  const [open, setOpen] = React2.useState(false);
  const hours = format24h ? Array.from({ length: 24 }, (_, i) => i.toString().padStart(2, "0")) : Array.from({ length: 12 }, (_, i) => (i + 1).toString().padStart(2, "0"));
  const minutes = Array.from(
    { length: 60 },
    (_, i) => i.toString().padStart(2, "0")
  );
  const [selectedHour, selectedMinute] = value?.split(":") || ["", ""];
  const handleHourSelect = (hour) => {
    const newTime = `${hour}:${selectedMinute || "00"}`;
    onChange?.(newTime);
  };
  const handleMinuteSelect = (minute) => {
    const newTime = `${selectedHour || "00"}:${minute}`;
    onChange?.(newTime);
    setOpen(false);
  };
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsxs(Popover, { open, onOpenChange: setOpen, children: [
      /* @__PURE__ */ jsx(PopoverTrigger, { asChild: true, children: /* @__PURE__ */ jsxs(
        Button,
        {
          variant: "outline",
          disabled,
          "aria-invalid": error ? true : void 0,
          className: cn(
            "w-full justify-start",
            !value && "text-muted-foreground",
            className
          ),
          children: [
            /* @__PURE__ */ jsx(Clock, { className: "mr-2 h-4 w-4" }),
            value || placeholder
          ]
        }
      ) }),
      /* @__PURE__ */ jsx(PopoverContent, { className: "w-auto p-0", align: "start", children: /* @__PURE__ */ jsxs("div", { className: "flex", children: [
        /* @__PURE__ */ jsx(ScrollArea, { className: "h-60 w-20 border-r", children: /* @__PURE__ */ jsx("div", { className: "p-1", children: hours.map((hour) => /* @__PURE__ */ jsx(
          "button",
          {
            type: "button",
            onClick: () => handleHourSelect(hour),
            className: cn(
              "w-full px-3 py-2 text-sm rounded hover:bg-accent transition-colors text-center",
              selectedHour === hour && "bg-primary/10 text-primary font-semibold"
            ),
            children: hour
          },
          hour
        )) }) }),
        /* @__PURE__ */ jsx(ScrollArea, { className: "h-60 w-20", children: /* @__PURE__ */ jsx("div", { className: "p-1", children: minutes.map((minute) => /* @__PURE__ */ jsx(
          "button",
          {
            type: "button",
            onClick: () => handleMinuteSelect(minute),
            className: cn(
              "w-full px-3 py-2 text-sm rounded hover:bg-accent transition-colors text-center",
              selectedMinute === minute && "bg-primary/10 text-primary font-semibold"
            ),
            children: minute
          },
          minute
        )) }) })
      ] }) })
    ] }),
    error ? /* @__PURE__ */ jsx("p", { "data-slot": "time-picker-error", className: "mt-1 text-sm text-destructive", children: error }) : null
  ] });
}
function TimeInput({
  value = "",
  onChange,
  className,
  disabled,
  error
}) {
  const [localValue, setLocalValue] = React2.useState(value);
  const handleChange = (e) => {
    let input = e.target.value.replace(/\D/g, "");
    if (input.length >= 2) {
      const hours = parseInt(input.substring(0, 2));
      if (hours > 23) input = "23" + input.substring(2);
    }
    if (input.length >= 4) {
      const minutes = parseInt(input.substring(2, 4));
      if (minutes > 59) input = input.substring(0, 2) + "59";
    }
    if (input.length >= 2) {
      input = input.substring(0, 2) + ":" + input.substring(2, 4);
    }
    setLocalValue(input);
  };
  const handleBlur = () => {
    const parts = localValue.split(":");
    if (parts.length === 2 && parts[0].length === 2 && parts[1].length === 2) {
      onChange?.(localValue);
    } else {
      setLocalValue(value);
    }
  };
  return /* @__PURE__ */ jsxs(Fragment, { children: [
    /* @__PURE__ */ jsxs("div", { className: "relative", children: [
      /* @__PURE__ */ jsx(
        Input,
        {
          type: "text",
          value: localValue,
          onChange: handleChange,
          onBlur: handleBlur,
          placeholder: "00:00",
          maxLength: 5,
          disabled,
          "aria-invalid": error ? true : void 0,
          className: cn("pr-10", className)
        }
      ),
      /* @__PURE__ */ jsx(Clock, { className: "absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" })
    ] }),
    error ? /* @__PURE__ */ jsx("p", { "data-slot": "time-input-error", className: "mt-1 text-sm text-destructive", children: error }) : null
  ] });
}
var toggleVariants = cva(
  "inline-flex items-center justify-center gap-2 rounded-md text-sm font-medium hover:bg-muted hover:text-muted-foreground disabled:pointer-events-none disabled:opacity-50 data-[state=on]:bg-accent data-[state=on]:text-accent-foreground [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] outline-none transition-[color,box-shadow] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive whitespace-nowrap",
  {
    variants: {
      variant: {
        default: "bg-transparent",
        outline: "border border-input bg-transparent hover:bg-accent hover:text-accent-foreground"
      },
      size: {
        default: "h-9 px-2 min-w-9",
        sm: "h-8 px-1.5 min-w-8",
        lg: "h-10 px-2.5 min-w-10"
      }
    },
    defaultVariants: {
      variant: "default",
      size: "default"
    }
  }
);
function Toggle({
  className,
  variant,
  size,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    TogglePrimitive.Root,
    {
      "data-slot": "toggle",
      className: cn(toggleVariants({ variant, size, className })),
      ...props
    }
  );
}
var ToggleGroupContext = React2.createContext({
  size: "default",
  variant: "default"
});
function ToggleGroup({
  className,
  variant,
  size,
  children,
  ...props
}) {
  return /* @__PURE__ */ jsx(
    ToggleGroupPrimitive.Root,
    {
      "data-slot": "toggle-group",
      "data-variant": variant,
      "data-size": size,
      className: cn(
        "group/toggle-group flex w-fit items-center rounded-md data-[variant=outline]:shadow-xs",
        className
      ),
      ...props,
      children: /* @__PURE__ */ jsx(ToggleGroupContext.Provider, { value: { variant, size }, children })
    }
  );
}
function ToggleGroupItem({
  className,
  children,
  variant,
  size,
  ...props
}) {
  const context = React2.useContext(ToggleGroupContext);
  return /* @__PURE__ */ jsx(
    ToggleGroupPrimitive.Item,
    {
      "data-slot": "toggle-group-item",
      "data-variant": context.variant || variant,
      "data-size": context.size || size,
      className: cn(
        toggleVariants({
          variant: context.variant || variant,
          size: context.size || size
        }),
        "min-w-0 flex-1 shrink-0 rounded-none shadow-none first:rounded-l-md last:rounded-r-md focus:z-10 focus-visible:z-10 data-[variant=outline]:border-l-0 data-[variant=outline]:first:border-l",
        className
      ),
      ...props,
      children
    }
  );
}
function TranslatableRichText({
  value,
  onChange,
  errors,
  className
}) {
  const config = useUILocales();
  if (!config) {
    const firstKey = Object.keys(value)[0] ?? "";
    return /* @__PURE__ */ jsx(
      RichTextEditor,
      {
        value: value[firstKey] ?? "",
        onChange: (html) => onChange({ ...value, [firstKey]: html }),
        className
      }
    );
  }
  return /* @__PURE__ */ jsx(
    TranslatableField,
    {
      config,
      value,
      onChange,
      errors,
      className,
      children: ({ value: localeValue, onChange: localeChange }) => /* @__PURE__ */ jsx(RichTextEditor, { value: localeValue, onChange: localeChange })
    }
  );
}
function loadSavedTheme() {
  if (typeof window === "undefined") return "system";
  const saved = localStorage.getItem("omnify_theme");
  if (saved === "light" || saved === "dark" || saved === "system") return saved;
  return "system";
}
function applyTheme(theme) {
  const root = document.documentElement;
  if (theme === "system") {
    root.classList.toggle("dark", window.matchMedia("(prefers-color-scheme: dark)").matches);
  } else {
    root.classList.toggle("dark", theme === "dark");
  }
}
function UIProvider({
  children,
  defaultTheme,
  locales,
  defaultLocale,
  fallbackLocale,
  dateFnsLocale,
  onLocaleChange,
  timezone: timezoneProp,
  onTimezoneChange
}) {
  const [theme, setThemeState] = useState(() => defaultTheme ?? loadSavedTheme());
  const setTheme = useCallback((t) => setThemeState(t), []);
  useEffect(() => {
    localStorage.setItem("omnify_theme", theme);
    applyTheme(theme);
  }, [theme]);
  useEffect(() => {
    if (theme !== "system") return;
    const mq = window.matchMedia("(prefers-color-scheme: dark)");
    const handler = () => applyTheme("system");
    mq.addEventListener("change", handler);
    return () => mq.removeEventListener("change", handler);
  }, [theme]);
  const firstLocale = locales ? Object.keys(locales)[0] : void 0;
  const resolvedDefaultLocale = defaultLocale ?? firstLocale ?? "";
  const locale = locales && firstLocale ? {
    locales,
    defaultLocale: resolvedDefaultLocale,
    fallbackLocale: fallbackLocale ?? resolvedDefaultLocale
  } : void 0;
  const [currentLocale, setCurrentLocale] = useState(
    () => resolvedDefaultLocale
  );
  const setLocale = useCallback(
    (loc) => {
      setCurrentLocale(loc);
      onLocaleChange?.(loc);
    },
    [onLocaleChange]
  );
  useEffect(() => {
    if (currentLocale) {
      document.documentElement.lang = currentLocale;
    }
  }, [currentLocale]);
  const [timezone, setTimezoneState] = useState(
    () => timezoneProp ?? Intl.DateTimeFormat().resolvedOptions().timeZone
  );
  const setTimezone = useCallback(
    (tz) => {
      setTimezoneState(tz);
      onTimezoneChange?.(tz);
    },
    [onTimezoneChange]
  );
  useEffect(() => {
    if (timezoneProp !== void 0) {
      setTimezoneState(timezoneProp);
    }
  }, [timezoneProp]);
  return /* @__PURE__ */ jsx(UIContext.Provider, { value: { theme, setTheme, locale, currentLocale, setLocale, dateFnsLocale, timezone, setTimezone }, children });
}

export { Accordion, AccordionContent, AccordionItem, AccordionTrigger, Alert, AlertDescription, AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogOverlay, AlertDialogPortal, AlertDialogTitle, AlertDialogTrigger, AlertTitle, AspectRatio, Avatar, AvatarFallback, AvatarImage, Badge, Breadcrumb, BreadcrumbEllipsis, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator, Button, Calendar, Card, CardAction, CardContent, CardDescription, CardFooter, CardHeader, CardMedia, CardTitle, Carousel, CarouselContent, CarouselItem, CarouselNext, CarouselPrevious, Checkbox, Collapsible, CollapsibleContent2 as CollapsibleContent, CollapsibleTrigger2 as CollapsibleTrigger, ColorPicker, Combobox, Command, CommandDialog, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator, CommandShortcut, ContextMenu, ContextMenuCheckboxItem, ContextMenuContent, ContextMenuGroup, ContextMenuItem, ContextMenuLabel, ContextMenuPortal, ContextMenuRadioGroup, ContextMenuRadioItem, ContextMenuSeparator, ContextMenuShortcut, ContextMenuSub, ContextMenuSubContent, ContextMenuSubTrigger, ContextMenuTrigger, DatePicker, DateRangePicker, Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogOverlay, DialogPortal, DialogTitle, DialogTrigger, Drawer, DrawerBody, DrawerClose, DrawerContent, DrawerDescription, DrawerFooter, DrawerHeader, DrawerOverlay, DrawerPortal, DrawerTitle, DrawerTrigger, DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuPortal, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuSeparator, DropdownMenuShortcut, DropdownMenuSub, DropdownMenuSubContent, DropdownMenuSubTrigger, DropdownMenuTrigger, FileUpload, Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage, FullWidthPageContainer, HoverCard, HoverCardContent, HoverCardTrigger, Input, InputOTP, InputOTPGroup, InputOTPSeparator, InputOTPSlot, Label3 as Label, Menubar, MenubarCheckboxItem, MenubarContent, MenubarGroup, MenubarItem, MenubarLabel, MenubarMenu, MenubarPortal, MenubarRadioGroup, MenubarRadioItem, MenubarSeparator, MenubarShortcut, MenubarSub, MenubarSubContent, MenubarSubTrigger, MenubarTrigger, MultiCombobox, NavigationMenu, NavigationMenuContent, NavigationMenuIndicator, NavigationMenuItem, NavigationMenuLink, NavigationMenuList, NavigationMenuTrigger, NavigationMenuViewport, PageContainer, Pagination, PaginationContent, PaginationEllipsis, PaginationItem, PaginationLink, PaginationNext, PaginationPrevious, PasswordInput, Popover, PopoverAnchor, PopoverContent, PopoverTrigger, Progress, RadioGroup4 as RadioGroup, RadioGroupItem, Rating, ResizableHandle, ResizablePanel, ResizablePanelGroup, RichTextEditor, ScrollArea, ScrollBar, Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectScrollDownButton, SelectScrollUpButton, SelectSeparator, SelectTrigger, SelectValue, Separator4 as Separator, Sheet, SheetClose, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger, Sidebar, SidebarContent, SidebarFooter, SidebarGroup, SidebarGroupAction, SidebarGroupContent, SidebarGroupLabel, SidebarHeader, SidebarInput, SidebarInset, SidebarMenu, SidebarMenuAction, SidebarMenuBadge, SidebarMenuButton, SidebarMenuItem, SidebarMenuSkeleton, SidebarMenuSub, SidebarMenuSubButton, SidebarMenuSubItem, SidebarProvider, SidebarRail, SidebarSeparator, SidebarTrigger, Skeleton, Slider, SlugInput, Spinner, SplitPageContainer, StandardPageContainer, StatusBadge, Switch, Table, TableBody, TableCaption, TableCell, TableFooter, TableHead, TableHeader, TableRow, Tabs, TabsContent, TabsList, TabsTrigger, TagInput, Textarea, TimeInput, TimePicker, Toaster, Toggle, ToggleGroup, ToggleGroupItem, Tooltip, TooltipContent, TooltipProvider, TooltipTrigger, TranslatableField, TranslatableRichText, UIProvider, badgeVariants, buttonVariants, generateSlug, inputVariants, navigationMenuTriggerStyle, resolveTranslatableConfig, toggleVariants, useFormField, useLocale, useSidebar, useTheme, useTimezone, useUILocales };
//# sourceMappingURL=index.js.map
//# sourceMappingURL=index.js.map