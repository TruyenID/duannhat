/**
 * Guard React against third-party DOM re-parenting (Google Translate + some
 * browser extensions / password managers).
 *
 * Google Translate rewrites the page's text nodes: it wraps them in <font>
 * elements and moves them under new parents. React keeps its own references to
 * the ORIGINAL nodes, so when it later unmounts a subtree — e.g. switching the
 * revenue report tab from "商品/売上" to "取消" — it calls
 * `parent.removeChild(node)` on a node the extension has already relocated. The
 * DOM throws `NotFoundError: Failed to execute 'removeChild' on 'Node': The
 * node to be removed is not a child of this node` and the whole React tree
 * crashes into the ErrorBoundary ("Đã xảy ra lỗi").
 *
 * The community-standard mitigation (facebook/react#11538) is to make
 * `removeChild` / `insertBefore` no-op instead of throwing when the target is
 * no longer under the expected parent. React's virtual tree stays consistent;
 * the orphaned translated node is left for the extension (or GC) to reclaim.
 * This deliberately does NOT disable translation — cashiers reading the
 * Japanese POS through Translate keep working, they just stop crashing on tab
 * switches.
 *
 * Runs once, synchronously, before React mounts (imported first in main.tsx).
 * Idempotent: a second call is a no-op so HMR re-imports don't double-wrap.
 */

const GUARD_FLAG = "__tempoDomMutationGuardInstalled";

export function installDomMutationGuard(): void {
  if (typeof Node !== "function" || !Node.prototype) {
    return;
  }
  const proto = Node.prototype as Node & Record<string, unknown>;
  if (proto[GUARD_FLAG]) {
    return;
  }
  proto[GUARD_FLAG] = true;

  const originalRemoveChild = Node.prototype.removeChild;
  Node.prototype.removeChild = function removeChild<T extends Node>(
    this: Node,
    child: T,
  ): T {
    if (child.parentNode !== this) {
      // An extension re-parented (or already removed) the node — there is
      // nothing here for us to remove. Return it so React's fiber commit
      // treats the deletion as done instead of throwing NotFoundError.
      return child;
    }
    return originalRemoveChild.call(this, child) as T;
  };

  const originalInsertBefore = Node.prototype.insertBefore;
  Node.prototype.insertBefore = function insertBefore<T extends Node>(
    this: Node,
    newNode: T,
    referenceNode: Node | null,
  ): T {
    if (referenceNode && referenceNode.parentNode !== this) {
      // The anchor React wanted to insert before was moved by an extension.
      // Append to the end of this parent rather than throwing — the visual
      // order may drift slightly under active translation, but the tree
      // stays alive.
      return originalInsertBefore.call(this, newNode, null) as T;
    }
    return originalInsertBefore.call(this, newNode, referenceNode) as T;
  };
}
