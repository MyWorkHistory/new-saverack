<?php

namespace App\Mail;

use App\Support\CrmUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var string */
    public $resetUrl;

    /** @var string */
    public $greetingName;

    /** @var int */
    public $expireMinutes;

    /** @var string */
    public $logoUrl;

    /**
     * @param  string  $token
     * @param  object  $user  User or notifiable with email, name
     */
    public function __construct(string $token, $user)
    {
        $email = method_exists($user, 'getEmailForPasswordReset')
            ? $user->getEmailForPasswordReset()
            : (string) ($user->email ?? '');
        $this->resetUrl = CrmUrls::resetPassword($token, $email);
        $name = trim((string) ($user->name ?? ''));
        $this->greetingName = $name !== '' ? explode(' ', $name, 2)[0] : 'there';
        $this->expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $this->logoUrl = url('/logo.jpg');
    }

    public function build()
    {
        $fromAddress = config('crm.mail_from_address');
        $fromName = config('crm.mail_from_name');

        return $this->from($fromAddress, $fromName)
            ->subject('Reset your Save Rack password')
            ->view('emails.reset-password');
    }
}
