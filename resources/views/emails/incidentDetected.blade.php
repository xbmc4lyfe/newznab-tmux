@php
    $title = $resolved
        ? "Incident Resolved — {$services}"
        : "Incident Detected — {$services}";
    $impactValue = $incident->impact->value;
    $alertType = $resolved
        ? 'success'
        : ($impactValue === 'critical' ? 'danger' : ($impactValue === 'major' ? 'warning' : 'info'));
@endphp
<x-mail.layout :title="$title" :preheader="$preheader" :site-name="$site">
    @if ($resolved)
        <x-mail.alert type="success">
            <strong>Resolved</strong> — The following incident has been automatically resolved.
        </x-mail.alert>
    @else
        <x-mail.alert :type="$alertType">
            <strong>{{ ucfirst($impactValue) }} impact</strong> — An automated health check has detected a service issue.
        </x-mail.alert>
    @endif

    <x-mail.status-table>
        <tr>
            <td class="status-label">Incident</td>
            <td>{{ $incident->title }}</td>
        </tr>
        <tr>
            <td class="status-label">Affected services</td>
            <td>{{ $services }}</td>
        </tr>
        <tr>
            <td class="status-label">Impact</td>
            <td>{{ ucfirst($impactValue) }}</td>
        </tr>
        <tr>
            <td class="status-label">Status</td>
            <td>{{ ucfirst($incident->status->value) }}</td>
        </tr>
        <tr>
            <td class="status-label">Started</td>
            <td>{{ $incident->started_at->format('M j, Y g:i A T') }}</td>
        </tr>
        @if ($resolved && $incident->resolved_at)
            <tr>
                <td class="status-label">Resolved</td>
                <td>{{ $incident->resolved_at->format('M j, Y g:i A T') }}</td>
            </tr>
            <tr>
                <td class="status-label">Duration</td>
                <td>{{ $incident->started_at->diffForHumans($incident->resolved_at, true) }}</td>
            </tr>
        @endif
    </x-mail.status-table>

    @if ($incident->description)
        <x-mail.info-box>
            <strong>Details:</strong><br>
            {!! nl2br(e($incident->description)) !!}
        </x-mail.info-box>
    @endif

    <x-mail.button :url="$statusUrl" :color="$resolved ? 'success' : 'primary'">
        View status dashboard
    </x-mail.button>

    <div class="signature">
        <p>— {{ $site }} Monitoring</p>
    </div>
</x-mail.layout>
