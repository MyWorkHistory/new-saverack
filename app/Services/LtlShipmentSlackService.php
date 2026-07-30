<?php

namespace App\Services;

use App\Models\LtlShipment;
use App\Support\CrmUrls;
use Throwable;

class LtlShipmentSlackService
{
    /** @var SlackDeliveryService */
    private $slack;

    public function __construct(SlackDeliveryService $slack)
    {
        $this->slack = $slack;
    }

    public function notifyQuoteRequest(LtlShipment $shipment): void
    {
        $this->post($shipment, 'LTL Quote Request', [
            $shipment->directionLabel(),
            'Company Name: '.trim((string) $shipment->company_name),
            'Pallets: '.$this->palletCount($shipment),
        ]);
    }

    public function notifyQuoteReady(LtlShipment $shipment): void
    {
        $amount = $shipment->quote_amount_cents !== null
            ? '$'.number_format(((int) $shipment->quote_amount_cents) / 100, 2)
            : '—';
        $this->post($shipment, 'LTL Quote Ready', [
            $shipment->directionLabel(),
            'Company Name: '.trim((string) $shipment->company_name),
            'Pallets: '.$this->palletCount($shipment),
            'Quote: '.$amount,
        ]);
    }

    public function notifyScheduled(LtlShipment $shipment): void
    {
        $pickUp = 'As soon as possible';
        if ($shipment->time_mode === LtlShipment::TIME_SPECIFIC && $shipment->time_from !== null) {
            $pickUp = $shipment->time_from->timezone('America/New_York')->format('m/d/Y g:i A');
        }
        $this->post($shipment, 'LTL Scheduled', [
            $shipment->directionLabel(),
            'Company Name: '.trim((string) $shipment->company_name),
            'Pallets: '.$this->palletCount($shipment),
            'Pick Up Date: '.$pickUp,
        ]);
    }

    private function palletCount(LtlShipment $shipment): int
    {
        if ($shipment->relationLoaded('pallets')) {
            return $shipment->pallets->count();
        }

        return $shipment->pallets()->count();
    }

    /**
     * @param  list<string>  $lines
     */
    private function post(LtlShipment $shipment, string $username, array $lines): void
    {
        $channel = trim((string) (config('ltl.slack_channel') ?: '#ltl-freight'));
        $url = CrmUrls::ltlShipmentStaffUrl((int) $shipment->id);
        $text = implode("\n", array_merge($lines, ['<'.$url.'|View LTL>']));

        $options = [];
        if ($this->slack->hasBotToken()) {
            $options['customize_identity'] = true;
            $options['prefer_bot'] = true;
        }

        try {
            $this->slack->post($channel, $text, $username, $options);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
