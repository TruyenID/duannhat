<?php

namespace App\Services\Payment\Gateway\PayPay;

use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Services\Payment\Gateway\Exceptions\GatewayAuthenticationFailed;
use App\Services\Payment\Gateway\Exceptions\GatewayOperationFailed;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use PayPay\OpenPaymentAPI\Client;
use PayPay\OpenPaymentAPI\Controller\ClientControllerException;
use PayPay\OpenPaymentAPI\Models\CreateQrCodePayload;

/**
 * Dynamic-QR (OPA Web Payment, `/v2/codes`) slice of the PayPay API — plan-054.
 *
 * Sits beside the preauth adapter so `PayPay\OpenPaymentAPI\*` stays inside
 * Gateway/PayPay/; every method returns plain arrays so the neutral layer never
 * sees an SDK type.
 *
 * NOT a PaymentGatewayContract: the contract models a payment lifecycle
 * (authorize/capture/cancel), while this is a code lifecycle (mint/read/invalidate).
 * The QR *payment* still flows through PayPayPaymentGateway.
 */
/*
 * Not `final`, deliberately. This is the only class in the QR path that performs
 * network I/O, and its collaborators (the SDK factory, the credentials resolver)
 * are final with no seam of their own — so with this sealed too, nothing above it
 * could be tested without calling PayPay for real. That is how a bug that made
 * every mint fail reached main: the tests that would have caught it were
 * unwritable, and the ordering invariants had to be pinned against method source
 * instead of behaviour.
 */
class PayPayQrCodeClient
{
    /**
     * Prefix for every merchant payment id this class mints.
     *
     * Load-bearing, not cosmetic: `Code::getPaymentDetails` reads
     * `/v2/codes/payments/{mpid}` while `Payment::getPaymentDetails` reads
     * `/v2/payments/{mpid}`, and the SDK offers no parameter to choose. Call
     * sites that hold only an mpid — the preparePayment idempotency replay and
     * resolvePayPayPaymentId — dispatch on this prefix. A QR payment sent to the
     * payments endpoint 404s, and the SDK throws on >=400, which dead-letters the
     * provider event after five retries (plan-054 R5).
     */
    public const MPID_PREFIX = 'tempoqr-';

    /**
     * Attempt states in which a QR may still be scannable, or may have been
     * scanned without us hearing about it.
     *
     * One definition, because "is this code still outstanding?" is asked from
     * more than one place: the customer's own poll asks it to decide which
     * attempt to read, and the stale sweep asks it to decide which attempts to
     * chase. Two lists that disagree is how an attempt becomes invisible to the
     * side that would have collected its money.
     *
     * `prepared` is in here on purpose: nothing moves an attempt to
     * `provider_pending` at mint time, so a freshly minted QR sits in
     * `prepared` until a provider answer arrives.
     *
     * @var list<string>
     */
    public const LIVE_ATTEMPT_STATES = [
        PaymentAttemptStateEnum::Prepared->value,
        PaymentAttemptStateEnum::ProviderPending->value,
        PaymentAttemptStateEnum::ActionRequired->value,
        PaymentAttemptStateEnum::Processing->value,
    ];

    /**
     * How long PayPay keeps a dynamic QR scannable, in minutes.
     *
     * Observed, not configured: `CreateQrCodePayload` exposes no expiry setter
     * for an ORDER_QR, and sandbox consistently returns `expiryDate` at
     * creation + 301s. Recorded here because callers reason about the code
     * being DEAD, and "dead" is only ever an inference from age — this endpoint
     * never reports expiry (see `create()`'s note on re-minting).
     *
     * If PayPay ever lengthens this, every age-based conclusion drawn from it
     * becomes wrong in the dangerous direction. Treat a change here as a money
     * change.
     */
    public const CODE_LIFETIME_MINUTES = 5;

    /** PayPay truncates order descriptions; keep free text out of it entirely. */
    private const MAX_DESCRIPTION_LENGTH = 255;

    public function __construct(
        private readonly PayPaySdkClientFactory $clientFactory = new PayPaySdkClientFactory,
        private readonly PayPayCredentialsResolver $credentialsResolver = new PayPayCredentialsResolver,
    ) {}

    public static function isQrMerchantPaymentId(string $merchantPaymentId): bool
    {
        return str_starts_with($merchantPaymentId, self::MPID_PREFIX);
    }

    public static function merchantPaymentIdFor(string $attemptId): string
    {
        // Per ATTEMPT, never per order: payment_attempts is unique on
        // (connection_id, environment, provider_object_id), so an order-derived
        // id would make a retry after an expired QR violate the index — and
        // PayPay rejects a repeated merchantPaymentId anyway (plan-054 R6).
        return self::MPID_PREFIX.str_replace('-', '', $attemptId);
    }

    /**
     * @return array{code_id: string, url: string, deeplink: ?string, expires_at: ?int, amount: int, currency: string}
     */
    public function create(
        GatewayConnectionData $connection,
        string $merchantPaymentId,
        int $amount,
        string $currency,
        string $description,
        ?string $redirectUrl,
        string $correlationId,
    ): array {
        $client = $this->client($connection);

        $payload = new CreateQrCodePayload;
        $payload->setMerchantPaymentId($merchantPaymentId);
        $payload->setAmount(['amount' => $amount, 'currency' => $currency]);
        $payload->setCodeType('ORDER_QR');
        $payload->setOrderDescription(mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH));

        if ($redirectUrl !== null && $redirectUrl !== '') {
            $payload->setRedirectUrl($redirectUrl);
            $payload->setRedirectType('WEB_LINK');
        }

        $response = PayPaySdkCallGuard::invoke(
            static fn (): array => $client->code->createQRCode($payload),
            $correlationId,
        );

        $data = $this->requireSuccess($response, $correlationId);

        return [
            'code_id' => (string) ($data['codeId'] ?? ''),
            'url' => (string) ($data['url'] ?? ''),
            'deeplink' => isset($data['deeplink']) ? (string) $data['deeplink'] : null,
            // Unix seconds, PayPay-chosen (~5 min) and NOT configurable —
            // CreateQrCodePayload exposes no expiry setter for a non-authorization
            // code, so the caller must plan to re-mint rather than extend.
            'expires_at' => isset($data['expiryDate']) ? (int) $data['expiryDate'] : null,
            'amount' => (int) ($data['amount']['amount'] ?? $amount),
            'currency' => strtoupper((string) ($data['amount']['currency'] ?? $currency)),
        ];
    }

    /**
     * @return array{status: string, merchant_payment_id: string, paypay_payment_id: ?string, amount: ?int, currency: ?string, expires_at: ?int}
     */
    public function retrieve(
        GatewayConnectionData $connection,
        string $merchantPaymentId,
        string $correlationId,
    ): array {
        $client = $this->client($connection);

        $response = PayPaySdkCallGuard::invoke(
            static fn (): array => $client->code->getPaymentDetails($merchantPaymentId),
            $correlationId,
        );

        $data = $this->requireSuccess($response, $correlationId);
        $paypayPaymentId = (string) ($data['paymentId'] ?? '');

        return [
            'status' => strtoupper((string) ($data['status'] ?? 'UNKNOWN')),
            'merchant_payment_id' => (string) ($data['merchantPaymentId'] ?? $merchantPaymentId),
            'paypay_payment_id' => $paypayPaymentId !== '' ? $paypayPaymentId : null,
            'amount' => isset($data['amount']['amount']) ? (int) $data['amount']['amount'] : null,
            'currency' => isset($data['amount']['currency'])
                ? strtoupper((string) $data['amount']['currency'])
                : null,
            // Echoed when PayPay includes it, so the client never has to decide
            // on its own that a code lapsed — a wallet settling a second after a
            // local countdown hits zero is still a real payment.
            'expires_at' => isset($data['expiryDate']) ? (int) $data['expiryDate'] : null,
        ];
    }

    /**
     * `retrieve()`, but answering null when PayPay has no payment for the code.
     *
     * The common end of a QR's life is that nobody scanned it. PayPay does not
     * report that as a status — it answers HTTP 404 for the merchant payment
     * id, the SDK raises on any status >= 400, and the call guard re-raises
     * anything that is not an auth failure. A caller that only has `retrieve()`
     * therefore cannot tell "the customer never scanned" from "PayPay is
     * unreachable", and must treat both as unknown; the attempt then stays open
     * forever, which is the leak this exists to close.
     *
     * Narrow on purpose: ONLY 404 becomes null. A 5xx, a timeout or a transport
     * fault still throws, because concluding "no payment" from an outage is how
     * a paid order gets written off.
     *
     * @return array{status: string, merchant_payment_id: string, paypay_payment_id: ?string, amount: ?int, currency: ?string, expires_at: ?int}|null
     */
    public function findPayment(
        GatewayConnectionData $connection,
        string $merchantPaymentId,
        string $correlationId,
    ): ?array {
        try {
            return $this->retrieve($connection, $merchantPaymentId, $correlationId);
        } catch (ClientControllerException $exception) {
            if ((int) $exception->getCode() === 404) {
                return null;
            }

            throw $exception;
        } catch (GatewayOperationFailed $exception) {
            // Some PayPay endpoints answer 200 and put the refusal in
            // `resultInfo.code`; requireSuccess() turns that into this
            // exception carrying the provider's own code.
            if (str_contains($exception->providerCode, 'NOT_FOUND')) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Invalidate a code so it can no longer be paid.
     *
     * Best-effort by design: callers use this to close the window on a QR they
     * are about to abandon, and a failure here must not mask the original
     * problem. A code that is already paid or already deleted is not an error.
     */
    public function delete(
        GatewayConnectionData $connection,
        string $codeId,
        string $correlationId,
    ): bool {
        try {
            // Resolving the client is inside the try on purpose: an unconfigured
            // or unresolvable connection throws, and callers use this while
            // already handling a failure. Letting it escape would mask the
            // original error AND skip the attempt cancellation that follows.
            $client = $this->client($connection);

            $response = PayPaySdkCallGuard::invoke(
                static fn (): array => $client->code->deleteQRCode($codeId),
                $correlationId,
            );
        } catch (\Throwable) {
            return false;
        }

        return strtoupper((string) ($response['resultInfo']['code'] ?? '')) === 'SUCCESS';
    }

    /**
     * PayPay answers HTTP 200 with the real outcome in `resultInfo.code`.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function requireSuccess(array $response, string $correlationId): array
    {
        $code = strtoupper((string) ($response['resultInfo']['code'] ?? ''));

        if ($code !== 'SUCCESS') {
            throw new GatewayOperationFailed($correlationId, $code !== '' ? $code : 'UNKNOWN');
        }

        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    private function client(GatewayConnectionData $connection): Client
    {
        $credentials = $this->credentialsResolver->forConnection($connection);

        if (! $credentials->isConfigured()) {
            throw new GatewayAuthenticationFailed('paypay:qr-client:unconfigured');
        }

        return $this->clientFactory->forConnection($connection, $credentials);
    }
}
