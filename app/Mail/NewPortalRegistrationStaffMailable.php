<?php

namespace App\Mail;

use App\Models\ClientAccount;
use App\Models\User;
use App\Support\CrmUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewPortalRegistrationStaffMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var ClientAccount */
    public $account;

    /** @var User */
    public $user;

    /** @var string */
    public $staffAccountUrl;

    /** @var string */
    public $logoUrl;

    public function __construct(ClientAccount $account, User $user)
    {
        $this->account = $account;
        $this->user = $user;
        $this->staffAccountUrl = CrmUrls::clientAccountStaffUrl((int) $account->id);
        $this->logoUrl = url('/logo.jpg');
    }

    public function build()
    {
        $fromAddress = config('crm.mail_from_address');
        $fromName = config('crm.mail_from_name');

        return $this->from($fromAddress, $fromName)
            ->subject('New 3PL signup: '.$this->account->company_name)
            ->view('emails.new-portal-registration-staff');
    }
}
