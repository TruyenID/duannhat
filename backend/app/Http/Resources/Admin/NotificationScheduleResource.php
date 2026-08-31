<?php

namespace App\Http\Resources\Admin;

use App\Models\NotificationAudience;
use App\Models\NotificationSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Recurr\Exception\InvalidRRule;
use Recurr\Rule as RecurrRule;
use Recurr\Transformer\ArrayTransformer;

/**
 * Plan-023 M3 T3.5 — admin-facing JSON for a NotificationSchedule.
 *
 * Carries `next_5_occurrences` as a computed field so the admin list
 * page can render the upcoming schedule without a second round-trip.
 * The transformer expands lazily — exception inside an invalid RRULE
 * surfaces as an empty array (the validator already rejects bad rules
 * at create time; this is a belt-and-braces for legacy rows).
 *
 * @mixin NotificationSchedule
 */
class NotificationScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'brand_id' => $this->brand_id,
            'template_key' => $this->template_key,
            'audience_id' => $this->audience_id,
            'audience_name' => $this->resolveAudienceName(),
            'channels' => $this->channels ?? [],
            'priority' => $this->priority ?? 'normal',
            'params' => $this->params,
            'rrule' => $this->rrule,
            'timezone' => $this->timezone,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'occurrences_remaining' => $this->occurrences_remaining,
            'next_occurrence_at' => $this->next_occurrence_at?->toIso8601String(),
            'last_occurrence_at' => $this->last_occurrence_at?->toIso8601String(),
            'status' => $this->status,
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'next_5_occurrences' => $this->computeNext5(),
        ];
    }

    /**
     * Resolve the audience display name. Uses the eager-loaded relation
     * when the controller pre-loaded it (admin index/show); falls back
     * to a single point-lookup for cases where the resource is used
     * outside the controller-paginated path.
     */
    private function resolveAudienceName(): ?string
    {
        if ($this->relationLoaded('audience')) {
            return $this->audience?->name;
        }

        return NotificationAudience::query()->whereKey($this->audience_id)->value('name');
    }

    /**
     * Expand the RRULE into the next 5 occurrences (excluding any already
     * past). Returns an empty array on invalid RRULE — the admin UI
     * renders that as "no upcoming runs" with the bad row flagged.
     *
     * @return array<int, string>
     */
    private function computeNext5(): array
    {
        if ($this->status !== 'active' || $this->next_occurrence_at === null) {
            return [];
        }
        try {
            $tz = new \DateTimeZone($this->timezone ?? 'UTC');
            $rule = new RecurrRule($this->rrule, $this->starts_at?->toDateTime() ?? new \DateTime('now', $tz), $this->ends_at?->toDateTime(), $tz->getName());
        } catch (InvalidRRule) {
            return [];
        }

        $occurrences = (new ArrayTransformer)->transform($rule);
        $cutoff = now()->getTimestamp();
        $result = [];
        foreach ($occurrences as $occurrence) {
            $ts = $occurrence->getStart()->getTimestamp();
            if ($ts < $cutoff) {
                continue;
            }
            $result[] = $occurrence->getStart()->format(\DateTimeInterface::ATOM);
            if (count($result) >= 5) {
                break;
            }
        }

        return $result;
    }
}
