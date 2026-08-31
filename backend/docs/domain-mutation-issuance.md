# Domain mutation issuance authority

Trusted mutation values are issued only by exact, named, final verifier classes mapped in `config/domain_mutation.php`. The default mapping is empty and therefore fail-closed.

When a production adapter is implemented, add its exact class under the relevant port and scope during composition-root deployment. Never map an interface, base class, wildcard, closure, anonymous class, or request-selected class name. A verifier replacement is a configuration deployment: add the new final class, clear the Laravel configuration cache, validate issuance and negative adapter tests, then remove the old class. Roll back by restoring the prior exact mapping.

The authority is process-local, bound to the configured adapter object identity, non-serializable, and cannot be reused by another instance or scope.
