@php
    $impactValue = $incident->impact->value;
    $headline = $resolved ? 'Incident Resolved' : 'Incident Detected';
@endphp
{{ $headline }} — {{ $services }}
{{ str_repeat('=', max(strlen($headline) + strlen($services) + 3, 30)) }}

@if ($resolved)
✓ Resolved — The following incident has been automatically resolved.
@else
⚠ {{ ucfirst($impactValue) }} impact — An automated health check has detected a service issue.
@endif

  Incident:          {{ $incident->title }}
  Affected services: {{ $services }}
  Impact:            {{ ucfirst($impactValue) }}
  Status:            {{ ucfirst($incident->status->value) }}
  Started:           {{ $incident->started_at->format('M j, Y g:i A T') }}
@if ($resolved && $incident->resolved_at)
  Resolved:          {{ $incident->resolved_at->format('M j, Y g:i A T') }}
  Duration:          {{ $incident->started_at->diffForHumans($incident->resolved_at, true) }}
@endif

@if ($incident->description)
Details:
{{ $incident->description }}

@endif
View status dashboard: {{ $statusUrl }}

— {{ $site }} Monitoring
