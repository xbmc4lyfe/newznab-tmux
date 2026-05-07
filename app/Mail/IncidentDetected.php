<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\HasBrandedSubject;
use App\Models\ServiceIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class IncidentDetected extends Mailable implements ShouldQueue
{
    use HasBrandedSubject, Queueable, SerializesModels;

    public function __construct(
        public readonly ServiceIncident $incident,
        public readonly bool $resolved = false,
    ) {
        // Incident notifications take priority over routine user mail so they
        // are routed onto a dedicated high-priority queue (overridable via env).
        $this->onQueue((string) config('mail.brand.incident_queue', 'incidents'));
    }

    public function build(): static
    {
        $site = (string) config('app.name');
        $status = $this->resolved ? 'Resolved' : 'Detected';
        $services = (string) $this->incident->services->pluck('name')->join(', ');
        $impact = ucfirst($this->incident->impact->value);
        $preheader = $this->resolved
            ? "{$impact} incident on {$services} has been resolved."
            : "{$impact} impact incident detected on {$services}.";

        return $this->from((string) config('mail.from.address'))
            ->brandedSubject("Service incident {$status}: {$services}")
            ->view('emails.incidentDetected')
            ->text('emails.text.incidentDetected')
            ->with([
                'incident' => $this->incident,
                'services' => $services,
                'resolved' => $this->resolved,
                'site' => $site,
                'preheader' => $preheader,
                'statusUrl' => url('/admin/status'),
            ]);
    }
}
