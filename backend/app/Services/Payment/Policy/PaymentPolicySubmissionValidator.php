<?php

namespace App\Services\Payment\Policy;

use App\Models\PaymentPolicyRevision;
use App\Services\Payment\Configuration\Exceptions\PaymentConfigurationException;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicySubmission;

/** Validates resolver-backed option/revision pairs for new payment commands. */
final class PaymentPolicySubmissionValidator
{
    /**
     * plan-055 T3.4 (#1834) — the error codes this validator can emit.
     *
     * At the top of the class on purpose: their whole job is to be FOUND by the
     * next person adding a throw site.
     *
     * `OrderPaymentService::assertPolicyAllowedOrObserve()` treats exactly these
     * as observable-instead-of-refused while enforcement is optional. A code
     * that exists here but is missing from the list below falls through to
     * fail-closed and silently re-creates the Gate 3 money refusal that fix
     * exists to prevent — so
     * `tests/Feature/Architecture/PaymentPolicyErrorCodesStayObservableTest.php`
     * asserts, by reflection, that every `CODE_*` constant is listed AND that no
     * throw site passes a bare string literal.
     *
     * Scope, so this is not over-read: that guard covers THIS class only.
     * `assertNewPaymentAllowed()` also runs `PaymentPolicyEvaluationService`, and
     * a `PaymentConfigurationException` thrown anywhere on that path would be
     * rethrown by the consumer — nothing there throws one today, but the guard
     * would not notice if one appeared.
     *
     * Where the 12 throw sites are, counted rather than assumed: 9 under
     * `Services/Payment/Configuration/*`, 2 here, and 1 in the consumer's own
     * `handleMissingPolicyOption()` (`POLICY_OPTION_REQUIRED`). That last one
     * sits OUTSIDE the try block, which is why it is correctly absent from the
     * list below.
     */
    public const CODE_STALE = 'PAYMENT_POLICY_STALE';

    public const CODE_DISABLED = 'PAYMENT_OPTION_DISABLED';

    /** @var list<string> */
    public const EMITTED_ERROR_CODES = [
        self::CODE_STALE,
        self::CODE_DISABLED,
    ];

    public function __construct(
        private readonly PaymentPolicyEvaluationService $evaluation,
    ) {}

    public function assertNewPaymentAllowed(PaymentPolicySubmission $submission): void
    {
        $branch = $submission->branch;
        $evaluation = $this->evaluation->effectiveOptions(
            $branch,
            $submission->deviceId,
            $submission->channel,
        );

        $currentRevision = (int) ($evaluation['revision'] ?? 0);
        $currentOption = $this->findPresentedOption($evaluation['options'] ?? [], $submission->gatewayOptionId);

        if ($currentRevision === 0 && $submission->policyRevision > 0) {
            $this->throwStale($submission, $currentRevision, 'No published payment policy revision exists for this branch.');
        }

        if ($this->effectiveOptions($evaluation['options'] ?? []) === [] && $submission->policyRevision >= $currentRevision) {
            $this->throwDisabled('No effective payment options are available for checkout.');
        }

        $submittedRecord = PaymentPolicyRevision::query()
            ->where('branch_id', $branch->id)
            ->where('revision', $submission->policyRevision)
            ->first();

        if ($submittedRecord === null) {
            $this->throwStale($submission, $currentRevision, 'Submitted policy revision was never published for this branch.');
        }

        /** @var array<string, mixed> $submittedSnapshot */
        $submittedSnapshot = $submittedRecord->snapshot;
        $submittedOption = $this->findSnapshotOption($submittedSnapshot, $submission->gatewayOptionId);

        if ($submittedOption === null) {
            $this->throwStale($submission, $currentRevision, 'Submitted gateway option is absent from the referenced policy revision.');
        }

        if (($submittedOption['effective'] ?? false) !== true) {
            $this->throwDisabled('Submitted gateway option was not effective at the referenced policy revision.');
        }

        if ($submission->gatewayConnectionId !== null
            && ($submittedOption['connection_id'] ?? null) !== $submission->gatewayConnectionId) {
            $this->throwStale($submission, $currentRevision, 'Submitted connection does not match the referenced policy revision.');
        }

        if ($submission->policyRevision < $currentRevision) {
            if (! $this->isSafeStaleRevision($submittedOption, $currentOption)) {
                $this->throwStale($submission, $currentRevision, 'Payment policy changed since the client loaded effective options.');
            }

            return;
        }

        if ($submission->policyRevision > $currentRevision) {
            $this->throwStale($submission, $currentRevision, 'Submitted policy revision is newer than the server revision.');
        }

        if ($currentOption === null || ($currentOption['effective'] ?? false) !== true) {
            $this->throwDisabled('Gateway option is disabled by the current effective payment policy.');
        }

        if ($submission->gatewayConnectionId !== null
            && ($currentOption['connection_id'] ?? null) !== $submission->gatewayConnectionId) {
            $this->throwStale($submission, $currentRevision, 'Submitted connection does not match the current effective payment policy.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return list<array<string, mixed>>
     */
    private function effectiveOptions(array $options): array
    {
        return array_values(array_filter(
            $options,
            static fn (array $option): bool => ($option['effective'] ?? false) === true,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    private function findPresentedOption(array $options, string $optionId): ?array
    {
        foreach ($options as $option) {
            if (($option['id'] ?? null) === $optionId) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function findSnapshotOption(array $snapshot, string $optionId): ?array
    {
        foreach ($snapshot['options'] ?? [] as $option) {
            if (($option['option_id'] ?? null) === $optionId) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>|null  $current
     */
    private function isSafeStaleRevision(array $submitted, ?array $current): bool
    {
        if ($current === null || ($current['effective'] ?? false) !== true) {
            return false;
        }

        return ($submitted['connection_id'] ?? null) === ($current['connection_id'] ?? null)
            && ($submitted['owner_scope'] ?? null) === ($current['owner_scope'] ?? null)
            && ($submitted['operator_org_unit_id'] ?? null) === ($current['operator_org_unit_id'] ?? null);
    }

    private function throwStale(PaymentPolicySubmission $submission, int $currentRevision, string $message): never
    {
        throw new PaymentConfigurationException(
            $message,
            self::CODE_STALE,
            422,
            false,
            'refresh_payment_options',
            [
                'submitted_revision' => $submission->policyRevision,
                'current_revision' => $currentRevision,
                'gateway_option_id' => $submission->gatewayOptionId,
            ],
        );
    }

    private function throwDisabled(string $message): never
    {
        throw new PaymentConfigurationException(
            $message,
            self::CODE_DISABLED,
            422,
            false,
            'refresh_payment_options',
        );
    }
}
