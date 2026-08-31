# Payment gateway secret-store runbook

## Scope and invariants

Tempo stores encrypted provider credentials in dedicated server-only tables. Omnify generates hidden
internal models for schema ownership, but no resource, service, controller, route, queue payload,
device snapshot, or generic audit API exposes those rows. Runtime access goes only through the
secret-store boundary. The database never contains a master key. `APP_KEY` is not a payment key and
cannot decrypt this store.

The keyring is a JSON file outside the repository and public web root:

```json
{
  "active_key_id": "payment-master-2026-07-a",
  "keys": {
    "payment-master-2026-07-a": "base64:<exactly-32-random-bytes>"
  }
}
```

Set `PAYMENT_GATEWAY_KEYRING_PATH` to its absolute path. The file must be a regular non-symlink file,
owned/readable by the PHP worker account, mode `0400` or `0600`, and backed up separately from the
database. Never place it in a release directory, shared web directory, image, repository, CI artifact,
Laravel config cache, shell history, ticket, or log.

## Provisioning

1. Generate 32 bytes with an operating-system CSPRNG on the target host; do not derive a key from a
   password or reuse `APP_KEY`.
2. Assign a unique non-secret key ID and write the keyring atomically with mode `0400`/`0600`.
3. Set only the absolute keyring path in the process environment and restart PHP workers.
4. Run migrations, then `php artisan payments:install-gateway-secret-audit-protection` using the
   deployment database identity allowed to create triggers. The command is idempotent. Runtime
   rotation/revocation fails closed if both append-only triggers are not present.
5. Run the secret-store readiness check/tests before onboarding a credential.
6. Back up the keyring to a separately access-controlled recovery location and perform a restore drill.

## Provider credential rotation

Rotate through the server-only service boundary. The service locks the connection, verifies tenant,
provider and environment, inserts a new encrypted immutable version, switches the opaque connection
reference, and appends a redacted audit event in one transaction. API credentials revoke the previous
version immediately. Webhook secrets may keep the previous version readable only for the explicitly
requested overlap (maximum 24 hours); remove the old secret at the provider after the deadline.

Audit entries contain connection/tenant identity, purpose, versions, keyed old/new fingerprints, actor,
correlation ID, key ID, reference hashes and overlap deadline. They never contain plaintext, ciphertext,
nonce, secret reference, provider payload, request headers, PAN, or CVV.

## Master-key rotation

1. Add a freshly generated key under a new ID while retaining all old keys and make it active.
2. Restart workers and verify both new encryption and old-version decryption.
3. Re-encrypt stored active/retiring versions through the maintenance procedure before removing an old
   key. Changing only `active_key_id` does not re-encrypt existing rows.
4. Verify every stored `key_id` exists, take a database backup, and perform a restore test.
5. Remove an old key only after no row references it and the rollback window has elapsed.

## Recovery and incident response

- Missing key, malformed keyring, authentication failure, tenant mismatch, environment mismatch,
  revoked/expired version, or ciphertext tampering fails closed with `PAYMENT_SECRET_RESOLUTION_FAILED`.
- Restore the exact database and matching keyring generation together. A database-only restore cannot
  recover credentials.
- On suspected master-key compromise: stop payment workers, deny new payment operations, preserve audit
  evidence, rotate provider credentials and webhook secrets, provision a new master key, re-encrypt,
  validate provider access, then resume gradually.
- Never bypass the resolver with global Stripe configuration or copy plaintext into a database column.
