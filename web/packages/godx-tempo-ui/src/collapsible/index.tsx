import * as CollapsiblePrimitive from "@radix-ui/react-collapsible";

/**
 * Expandable/collapsible container built on Radix Collapsible.
 *
 * Manages open/closed state for a single content region. For multiple
 * collapsible sections, consider using `Accordion` instead.
 *
 * @example
 * ```tsx
 * <Collapsible>
 *   <CollapsibleTrigger asChild>
 *     <Button variant="ghost">Toggle details</Button>
 *   </CollapsibleTrigger>
 *   <CollapsibleContent>
 *     <p>Hidden content revealed on toggle.</p>
 *   </CollapsibleContent>
 * </Collapsible>
 * ```
 */
function Collapsible({
  ...props
}: React.ComponentProps<typeof CollapsiblePrimitive.Root>) {
  return <CollapsiblePrimitive.Root data-slot="collapsible" {...props} />;
}

/** Button or element that toggles the collapsible open/closed state. Supports `asChild` for custom trigger elements. */
function CollapsibleTrigger({
  ...props
}: React.ComponentProps<typeof CollapsiblePrimitive.CollapsibleTrigger>) {
  return (
    <CollapsiblePrimitive.CollapsibleTrigger
      data-slot="collapsible-trigger"
      {...props}
    />
  );
}

/** Content region that shows/hides when the collapsible is toggled. */
function CollapsibleContent({
  ...props
}: React.ComponentProps<typeof CollapsiblePrimitive.CollapsibleContent>) {
  return (
    <CollapsiblePrimitive.CollapsibleContent
      data-slot="collapsible-content"
      {...props}
    />
  );
}

export { Collapsible, CollapsibleTrigger, CollapsibleContent };
