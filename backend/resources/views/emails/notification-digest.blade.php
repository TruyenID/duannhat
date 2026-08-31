{{--
    Plan-023 M5 T5.7 — Markdown digest email rendered by
    NotificationDigestMail. Receives $payload (DigestPayload).

    Body structure (kept scannable, no marketing fluff):
      1. Summary line (X notifications in window)
      2. Priority breakdown table
      3. Per-type bucket counts
      4. Up to SAMPLE_CAP rows (latest first) with title + relative time
      5. Footer link to the full inbox

    Localisation: the per-row title comes from
    notifications.title (rendered server-side via TemplateRenderer at
    NotificationResource::forRecipientRow time elsewhere; this template
    only references already-rendered text).
--}}
<x-mail::message>
# Notification digest — {{ $payload->windowEnd->isoFormat('YYYY-MM-DD') }}

You had **{{ $payload->totalCount }}** notification(s) between
{{ $payload->windowStart->isoFormat('YYYY-MM-DD HH:mm') }} and
{{ $payload->windowEnd->isoFormat('YYYY-MM-DD HH:mm') }}.

@if (! empty($payload->countsByPriority))
## By priority

| Priority | Count |
| :-- | --: |
@foreach ($payload->countsByPriority as $priority => $count)
| {{ ucfirst($priority) }} | {{ $count }} |
@endforeach
@endif

@if (! empty($payload->countsByType))
## By type

| Type | Count |
| :-- | --: |
@foreach ($payload->countsByType as $type => $count)
| `{{ $type }}` | {{ $count }} |
@endforeach
@endif

## Recent activity

@php
    $shown = 0;
    $cap = \App\Services\Notification\DigestBuilderService::SAMPLE_CAP;
@endphp
@foreach ($payload->sample as $row)
    @php
        $notification = $row->notification;
        if ($notification === null) continue;
        $shown++;
        $title = optional($notification->template)?->content['ja']['title']
            ?? $notification->type;
    @endphp
- **{{ $title }}** — {{ optional($notification->created_at)->isoFormat('YYYY-MM-DD HH:mm') }}
@endforeach

@if ($payload->totalCount > $shown)
_…and {{ $payload->totalCount - $shown }} more not shown above._
@endif

<x-mail::button :url="config('app.url').'/inbox?since='.urlencode($payload->windowStart->toIso8601String())">
View full inbox
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
