<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeadTemplateMailable extends Mailable
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
        string $subjectLine,
        string $bodyHtml,
        string $signatureHtml,
        string $fromAddress,
        string $fromName
    ) {
        $this->subjectLine = $subjectLine;
        $this->bodyHtml = $bodyHtml;
        $this->signatureHtml = $signatureHtml;
        $this->fromAddress = $fromAddress;
        $this->fromName = $fromName !== '' ? $fromName : 'Save Rack';
    }

    public function build()
    {
        return $this->from($this->fromAddress, $this->fromName)
            ->subject($this->subjectLine)
            ->view('emails.lead-template');
    }
}
