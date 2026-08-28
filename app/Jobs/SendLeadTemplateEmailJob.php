<?php

namespace App\Jobs;

use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadTemplateMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendLeadTemplateEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $leadId;

    /** @var int */
    public $templateId;

    /** @var int|null */
    public $actorId;

    public $timeout = 120;

    public $tries = 2;

    public function __construct(int $leadId, int $templateId, ?int $actorId = null)
    {
        $this->leadId = $leadId;
        $this->templateId = $templateId;
        $this->actorId = $actorId;
        $this->onConnection((string) config('queue.default', 'database'));
    }

    public function handle(LeadTemplateMailService $mailer): void
    {
        $template = EmailTemplate::query()->find($this->templateId);
        if ($template === null) {
            Log::warning('lead_template_email.missing_template', [
                'template_id' => $this->templateId,
                'lead_id' => $this->leadId,
            ]);

            return;
        }

        $lead = Lead::query()->find($this->leadId);
        if ($lead === null) {
            Log::warning('lead_template_email.missing_lead', [
                'template_id' => $this->templateId,
                'lead_id' => $this->leadId,
            ]);

            return;
        }

        $actor = null;
        if ($this->actorId) {
            $actor = User::query()->find($this->actorId);
        }

        $mailer->sendToLeadSafe($lead, $template, $actor);
    }
}
