<?php

namespace App\Mail;

use App\Models\AdminBroadcastEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminBroadcastMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var string */
    public $subjectLine;

    /** @var string */
    public $bodyHtml;

    /** @var string */
    public $signatureHtml;

    /** @var string */
    public $fromAddress;

    /** @var string */
    public $fromName;

    public function __construct(
        AdminBroadcastEmail $broadcast,
        string $signatureHtml
    ) {
        $this->subjectLine = (string) $broadcast->subject;
        $this->bodyHtml = (string) $broadcast->body_html;
        $this->signatureHtml = $signatureHtml;
        $this->fromAddress = (string) $broadcast->from_address;
        $this->fromName = trim((string) ($broadcast->from_name ?? '')) ?: 'Save Rack';
    }

    public function build()
    {
        return $this->from($this->fromAddress, $this->fromName)
            ->subject($this->subjectLine)
            ->view('emails.admin-broadcast');
    }
}
