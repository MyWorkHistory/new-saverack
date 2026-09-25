<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\LeadComment;
use App\Models\LeadFee;
use App\Models\LeadStatusEvent;

class LeadCsvExport
{
    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            'Lead ID',
            'Status',
            'Status Label',
            'Referral',
            'Referral Label',
            'Company Name',
            'Contact Name',
            'Email',
            'Website',
            'Comment',
            'Follow Up Days',
            'Follow Up Date',
            'Follow Up Label',
            'Last Sent Template ID',
            'Last Sent Template Name',
            'Created At',
            'Updated At',
            'Fees',
            'Notes',
            'Status History',
        ];
    }

    /**
     * @param  resource  $out
     */
    public static function writeLead($out, Lead $lead): void
    {
        $lead->loadMissing(['latestTemplateSendEvent', 'feeItems', 'comments', 'statusEvents']);

        $lastTemplate = $lead->latestTemplateSendEvent;
        $followUpDays = $lead->follow_up_days !== null ? (int) $lead->follow_up_days : null;

        $fees = $lead->feeItems
            ->map(function (LeadFee $fee) {
                $label = trim((string) $fee->label);
                $amount = $fee->amount !== null ? (string) $fee->amount : '';

                return trim($label.($amount !== '' ? ': '.$amount : ''));
            })
            ->filter(static fn (string $s) => $s !== '')
            ->implode('; ');

        $notes = $lead->comments
            ->map(static fn (LeadComment $c) => trim((string) $c->body))
            ->filter(static fn (string $s) => $s !== '')
            ->implode(' | ');

        $history = $lead->statusEvents
            ->sortBy('id')
            ->map(function (LeadStatusEvent $event) {
                $status = Lead::statusLabel((string) $event->status);
                $at = $event->created_at !== null ? $event->created_at->toDateTimeString() : '';

                return trim($status.($at !== '' ? ' ('.$at.')' : ''));
            })
            ->filter(static fn (string $s) => $s !== '')
            ->implode('; ');

        fputcsv($out, [
            CsvExporter::cell($lead->id),
            CsvExporter::cell($lead->status),
            CsvExporter::cell(Lead::statusLabel((string) $lead->status)),
            CsvExporter::cell(Lead::normalizeReferral($lead->referral ?? Lead::REFERRAL_BIZY)),
            CsvExporter::cell(Lead::referralLabel((string) ($lead->referral ?? Lead::REFERRAL_BIZY))),
            CsvExporter::cell($lead->company_name),
            CsvExporter::cell($lead->name),
            CsvExporter::cell($lead->email),
            CsvExporter::cell($lead->website),
            CsvExporter::cell($lead->comment),
            CsvExporter::cell($followUpDays),
            CsvExporter::cell($lead->follow_up_at),
            CsvExporter::cell(Lead::followUpRemainingLabel($lead->follow_up_at, $followUpDays)),
            CsvExporter::cell($lastTemplate !== null ? $lastTemplate->email_template_id : null),
            CsvExporter::cell($lastTemplate !== null ? $lastTemplate->template_name : null),
            CsvExporter::cell($lead->created_at),
            CsvExporter::cell($lead->updated_at),
            CsvExporter::cell($fees),
            CsvExporter::cell($notes),
            CsvExporter::cell($history),
        ]);
    }
}
