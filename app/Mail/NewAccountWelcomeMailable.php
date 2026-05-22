<?php

namespace App\Mail;

use App\Models\ClientAccount;
use App\Models\User;
use App\Support\CrmUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewAccountWelcomeMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var string */
    public $greetingName;

    /** @var string */
    public $accountName;

    /** @var string */
    public $loginUrl;

    /** @var string */
    public $logoUrl;

    public function __construct(ClientAccount $account, User $user)
    {
        $name = trim((string) $user->name);
        $this->greetingName = $name !== '' ? explode(' ', $name, 2)[0] : 'there';
        $this->accountName = trim((string) $account->company_name);
        $this->loginUrl = CrmUrls::portalLoginUrl();
        $this->logoUrl = url('/logo.jpg');
    }

    public function build()
    {
        $fromAddress = config('crm.account_welcome_from_address');
        $fromName = config('crm.account_welcome_from_name');

        return $this->from($fromAddress, $fromName)
            ->subject('Your Save Rack Fulfillment account has been created')
            ->view('emails.new-account-welcome');
    }
}
