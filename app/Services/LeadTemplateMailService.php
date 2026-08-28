<?php

namespace App\Services;

use App\Mail\LeadTemplateMailable;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\LeadStatusEvent;
use App\Models\User;
use App\Support\LeadEmailTemplateRenderer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class LeadTemplateMailService
{
    /** @var LeadService */
    private $leads;

    /** @var AdminBroadcastEmailService */
    private $broadcastMail;

    public function __construct(LeadService $leads, AdminBroadcastEmailService $broadcastMail)
    {
        $this->leads = $leads;
        $this->broadcastMail = $broadcastMail;
    }

    /**
     * Send a template email to one lead and apply status / follow-up defaults.
     *
     * @return array{lead: Lead, sent_to: string}
     */
    public function sendToLead(Lead $lead, EmailTemplate $template, ?User $actor = null): array
    {
        $to = $this->sendMailOnly($lead, $template);
        $lead = $this->leads->applyTemplateEmailSend($lead, $template, $actor);

        return [
            'lead' => $lead,
            'sent_to' => $to,
        ];
    }

    /**
     * Mail only — no status update (bulk job after status was applied at queue time).
     */
    public function sendMailOnly(Lead $lead, EmailTemplate $template): string
    {
        $to = strtolower(trim((string) $lead->email));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => ['This lead has no valid email address.'],
            ]);
        }

        $subject = trim((string) ($template->subject ?? ''));
        if ($subject === '') {
            $subject = trim((string) ($template->name ?? 'Save Rack'));
        }

        $bodyHtml = trim((string) ($template->body ?? ''));
        if ($bodyHtml === '') {
            throw ValidationException::withMessages([
                'body' => ['This template has no body content.'],
            ]);
        }

        $subject = LeadEmailTemplateRenderer::renderSubject($subject, $lead);
        $bodyHtml = LeadEmailTemplateRenderer::renderBody($bodyHtml, $lead);

        $fromAddress = strtolower(trim((string) config('crm.lead_template_from_address', 'audi@saverack.com')));
        $options = config('crm.broadcast_from_options', []);
        $fromName = 'Audi K | Save Rack';
        if (is_array($options) && isset($options[$fromAddress]['name'])) {
            $fromName = trim((string) $options[$fromAddress]['name']) ?: $fromName;
        }

        Mail::to($to)->send(new LeadTemplateMailable(
            $subject,
            $bodyHtml,
            $this->signatureHtml($fromAddress),
            $fromAddress,
            $fromName
        ));

        return $to;
    }

    /**
     * @param  list<int>  $leadIds
     * @return array{queued: int, sent: int, skipped: int, skipped_ids: list<int>, failed_ids: list<int>, updated: int}
     */
    public function queueBulk(array $leadIds, EmailTemplate $template, ?User $actor = null): array
    {
        $ids = [];
        foreach ($leadIds as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            throw ValidationException::withMessages([
                'lead_ids' => ['Select at least one lead.'],
            ]);
        }

        $leads = Lead::query()->whereIn('id', $ids)->get();
        $sent = [];
        $skipped = [];
        $failed = [];
        $updated = 0;
        $delaySeconds = max(0, (int) config('crm.lead_bulk_email_delay_seconds', 3));
        $isFirstSend = true;

        foreach ($leads as $lead) {
            $email = strtolower(trim((string) $lead->email));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = (int) $lead->id;
                continue;
            }
            try {
                $this->leads->applyTemplateEmailSend($lead, $template, $actor);
                $updated++;
            } catch (Throwable $e) {
                $failed[] = (int) $lead->id;
                Log::warning('lead_template_email.bulk_status_failed', [
                    'lead_id' => $lead->id,
                    'template_id' => $template->id,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }

            if (! $isFirstSend && $delaySeconds > 0) {
                sleep($delaySeconds);
            }
            $isFirstSend = false;

            if ($this->sendToLeadSafe($lead->fresh() ?? $lead, $template, $actor)) {
                $sent[] = (int) $lead->id;
            } else {
                $failed[] = (int) $lead->id;
            }
        }

        return [
            'queued' => count($sent),
            'sent' => count($sent),
            'skipped' => count($skipped),
            'skipped_ids' => $skipped,
            'failed_ids' => $failed,
            'updated' => $updated,
        ];
    }

    /**
     * Used by the bulk job — swallows per-lead failures so the batch continues.
     */
    public function sendToLeadSafe(Lead $lead, EmailTemplate $template, ?User $actor = null): bool
    {
        try {
            $this->sendMailOnly($lead, $template);

            return true;
        } catch (Throwable $e) {
            Log::warning('lead_template_email.send_failed', [
                'lead_id' => $lead->id,
                'template_id' => $template->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function signatureHtml(string $fromAddress = 'audi@saverack.com'): string
    {
        return $this->broadcastMail->signatureHtml($fromAddress);
    }
}
