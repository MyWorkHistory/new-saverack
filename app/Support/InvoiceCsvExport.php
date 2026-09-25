<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\InvoiceItem;

class InvoiceCsvExport
{
    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            'Invoice ID',
            'Invoice #',
            'Status',
            'Account',
            'Account ID',
            'Payment Type',
            'Currency',
            'Issued At',
            'Due At',
            'Paid At',
            'Service Period Start',
            'Service Period End',
            'Payment Terms',
            'PO Number',
            'Customer Notes',
            'Internal Notes',
            'Subtotal',
            'Tax',
            'Total',
            'Amount Paid',
            'Balance Due',
            'Subtotal Cents',
            'Tax Cents',
            'Total Cents',
            'Amount Paid Cents',
            'Balance Due Cents',
            'Line ID',
            'Line Category',
            'Line Subtype',
            'Line Group',
            'Line Name',
            'Line Description',
            'SKU',
            'Service Code',
            'Order #',
            'ASN #',
            'RMA #',
            'Quantity',
            'Unit',
            'Unit Price',
            'Line Total',
            'Unit Price Cents',
            'Line Total Cents',
            'Line Metadata',
        ];
    }

    /**
     * Write one row per invoice line (header fields repeated). Empty invoices still get a header-only row.
     *
     * @param  resource  $out
     */
    public static function writeInvoice($out, Invoice $invoice, bool $includeInternalNotes = true): void
    {
        $invoice->loadMissing(['items', 'clientAccount']);

        $header = self::headerCells($invoice, $includeInternalNotes);
        $items = $invoice->items;
        if ($items->isEmpty()) {
            fputcsv($out, array_merge($header, self::emptyLineCells()));

            return;
        }

        foreach ($items as $item) {
            fputcsv($out, array_merge($header, self::lineCells($item)));
        }
    }

    /**
     * @return list<string>
     */
    private static function headerCells(Invoice $invoice, bool $includeInternalNotes): array
    {
        $account = $invoice->clientAccount;
        $accountName = $account !== null ? trim((string) $account->company_name) : '';
        $paymentType = $account !== null ? trim((string) ($account->default_payment_type ?? '')) : '';

        return [
            CsvExporter::cell($invoice->id),
            CsvExporter::cell($invoice->invoice_number),
            CsvExporter::cell($invoice->status),
            CsvExporter::cell($accountName),
            CsvExporter::cell($invoice->client_account_id),
            CsvExporter::cell($paymentType),
            CsvExporter::cell($invoice->currency),
            CsvExporter::cell($invoice->issued_at),
            CsvExporter::cell($invoice->due_at),
            CsvExporter::cell($invoice->paid_at),
            CsvExporter::cell($invoice->billing_period_start),
            CsvExporter::cell($invoice->billing_period_end),
            CsvExporter::cell($invoice->payment_terms),
            CsvExporter::cell($invoice->po_number),
            CsvExporter::cell($invoice->customer_notes),
            CsvExporter::cell($includeInternalNotes ? $invoice->internal_notes : ''),
            CsvExporter::cell(self::dollars($invoice->subtotal_cents)),
            CsvExporter::cell(self::dollars($invoice->tax_cents)),
            CsvExporter::cell(self::dollars($invoice->total_cents)),
            CsvExporter::cell(self::dollars($invoice->amount_paid_cents)),
            CsvExporter::cell(self::dollars($invoice->balance_due_cents)),
            CsvExporter::cell($invoice->subtotal_cents),
            CsvExporter::cell($invoice->tax_cents),
            CsvExporter::cell($invoice->total_cents),
            CsvExporter::cell($invoice->amount_paid_cents),
            CsvExporter::cell($invoice->balance_due_cents),
        ];
    }

    /**
     * @return list<string>
     */
    private static function emptyLineCells(): array
    {
        return array_fill(0, 18, '');
    }

    /**
     * @return list<string>
     */
    private static function lineCells(InvoiceItem $item): array
    {
        $meta = is_array($item->metadata) ? $item->metadata : [];
        $name = trim((string) ($item->display_name ?: $item->description));

        return [
            CsvExporter::cell($item->id),
            CsvExporter::cell($item->category),
            CsvExporter::cell($item->subtype),
            CsvExporter::cell($item->group_key),
            CsvExporter::cell($name),
            CsvExporter::cell($item->description),
            CsvExporter::cell($item->sku),
            CsvExporter::cell($item->service_code),
            CsvExporter::cell(self::metaString($meta, ['order_number', 'order_number_shipment', 'order # (shipment)'])),
            CsvExporter::cell(self::metaString($meta, ['asn_number'])),
            CsvExporter::cell(self::metaString($meta, ['rma_number'])),
            CsvExporter::cell($item->quantity),
            CsvExporter::cell($item->unit),
            CsvExporter::cell(self::dollars($item->unit_price_cents)),
            CsvExporter::cell(self::dollars($item->line_total_cents)),
            CsvExporter::cell($item->unit_price_cents),
            CsvExporter::cell($item->line_total_cents),
            CsvExporter::cell($meta),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $keys
     */
    private static function metaString(array $meta, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  mixed  $cents
     */
    private static function dollars($cents): string
    {
        if ($cents === null || $cents === '') {
            return '';
        }

        return number_format(((int) $cents) / 100, 2, '.', '');
    }
}
