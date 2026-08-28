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

class SendLeadBulkTemplateEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var list<int> */
    public $leadIds;

    /** @var int */
    public $templateId;

    /** @var int|null */
    public $actorId;

    public $timeout = 600;

    public $tries = 1;

    /**
     * @param  list<int>  $leadIds
     */
    public function __construct(array $leadIds, int $templateId, ?int $actorId = null)
    {
        $this->leadIds = array_values(array_map('intval', $leadIds));
        $this->templateId = $templateId;
        $this->actorId = $actorId;
        $this->onConnection((string) config('queue.default', 'database'));
    }

    public function handle(LeadTemplateMailService $mailer): void
    {
        $template = EmailTemplate::query()->find($this->templateId);
        if ($template === null) {
            Log::warning('lead_template_email.bulk_missing_template', [
                'template_id' => $this->templateId,
            ]);

            return;
        }

        $actor = null;
        if ($this->actorId) {
            $actor = User::query()->find($this->actorId);
        }

        $sent = 0;
        $failed = 0;
        $delaySeconds = max(0, (int) config('crm.lead_bulk_email_delay_seconds', 3));
        $isFirst = true;

        foreach ($this->leadIds as $leadId) {
            if (! $isFirst && $delaySeconds > 0) {
                sleep($delaySeconds);
            }
            $isFirst = false;

            $lead = Lead::query()->find($leadId);
            if ($lead === null) {
                $failed++;
                continue;
            }
            if ($mailer->sendToLeadSafe($lead, $template, $actor)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        Log::info('lead_template_email.bulk_finished', [
            'template_id' => $this->templateId,
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }
}
