<?php

namespace App\Notifications;

use App\Mail\ResetPasswordMailable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CrmResetPasswordNotification extends Notification
{
    use Queueable;

    /** @var string */
    protected $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * @param  mixed  $notifiable
     * @return array<int, string>
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * @param  mixed  $notifiable
     * @return \App\Mail\ResetPasswordMailable
     */
    public function toMail($notifiable)
    {
        $mailable = new ResetPasswordMailable($this->token, $notifiable);
        $email = method_exists($notifiable, 'getEmailForPasswordReset')
            ? $notifiable->getEmailForPasswordReset()
            : (string) ($notifiable->email ?? '');

        return $mailable->to($email);
    }
}
