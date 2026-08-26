<?php

namespace App\Services;

use App\Jobs\SendAdminBroadcastEmailJob;
use App\Mail\AdminBroadcastMailable;
use App\Models\AdminBroadcastEmail;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AdminBroadcastEmailService
{
    /**
     * Primary portal users on non-inactive accounts with a usable email.
     *
     * @return Builder<User>
     */
    public function recipientQuery(): Builder
    {
        return User::query()
            ->where('is_account_primary', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereHas('clientAccount', function (Builder $q) {
                $q->where('status', '!=', ClientAccount::STATUS_INACTIVE);
            })
            ->orderBy('id');
    }

    public function recipientCount(): int
    {
        return (int) $this->recipientQuery()->count();
    }

    /**
     * @return LengthAwarePaginator<AdminBroadcastEmail>
     */
    public function paginate(?string $subjectSearch = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = AdminBroadcastEmail::query()->orderByDesc('id');

        $q = trim((string) $subjectSearch);
        if ($q !== '') {
            $query->where('subject', 'like', '%'.$q.'%');
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array{from_address: string, subject: string, body_html: string}  $data
     */
    public function createAndSend(array $data, ?User $actor): AdminBroadcastEmail
    {
        $fromAddress = strtolower(trim((string) ($data['from_address'] ?? '')));
        $options = config('crm.broadcast_from_options', []);
        if (! is_array($options) || ! array_key_exists($fromAddress, $options)) {
            throw ValidationException::withMessages([
                'from_address' => ['Invalid Email From address.'],
            ]);
        }

        $subject = trim((string) ($data['subject'] ?? ''));
        if ($subject === '') {
            throw ValidationException::withMessages([
                'subject' => ['Subject is required.'],
            ]);
        }

        $bodyHtml = trim((string) ($data['body_html'] ?? ''));
        if ($this->isEmptyHtml($bodyHtml)) {
            throw ValidationException::withMessages([
                'body_html' => ['Body is required.'],
            ]);
        }

        $fromName = trim((string) ($options[$fromAddress]['name'] ?? 'Save Rack'));
        $recipientCount = $this->recipientCount();

        $broadcast = DB::transaction(function () use ($fromAddress, $fromName, $subject, $bodyHtml, $recipientCount, $actor) {
            return AdminBroadcastEmail::query()->create([
                'from_address' => $fromAddress,
                'from_name' => $fromName !== '' ? $fromName : 'Save Rack',
                'subject' => $subject,
                'body_html' => $bodyHtml,
                'qty_sent' => 0,
                'recipient_count' => $recipientCount,
                'status' => AdminBroadcastEmail::STATUS_SENDING,
                'sent_at' => now(),
                'created_by_user_id' => $actor ? $actor->id : null,
            ]);
        });

        SendAdminBroadcastEmailJob::dispatch((int) $broadcast->id);

        return $broadcast->fresh();
    }

    /**
     * Send a one-off preview to a single address. Does not create a broadcast or email recipients.
     *
     * @param  array{from_address: string, subject: string, body_html: string, test_email: string}  $data
     */
    public function sendTest(array $data): void
    {
        $fromAddress = strtolower(trim((string) ($data['from_address'] ?? '')));
        $options = config('crm.broadcast_from_options', []);
        if (! is_array($options) || ! array_key_exists($fromAddress, $options)) {
            throw ValidationException::withMessages([
                'from_address' => ['Invalid Email From address.'],
            ]);
        }

        $subject = trim((string) ($data['subject'] ?? ''));
        if ($subject === '') {
            throw ValidationException::withMessages([
                'subject' => ['Subject is required.'],
            ]);
        }

        $bodyHtml = trim((string) ($data['body_html'] ?? ''));
        if ($this->isEmptyHtml($bodyHtml)) {
            throw ValidationException::withMessages([
                'body_html' => ['Body is required.'],
            ]);
        }

        $testEmail = strtolower(trim((string) ($data['test_email'] ?? '')));
        if ($testEmail === '' || ! filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'test_email' => ['Enter a valid test email address.'],
            ]);
        }

        $fromName = trim((string) ($options[$fromAddress]['name'] ?? 'Save Rack'));
        $broadcast = new AdminBroadcastEmail([
            'from_address' => $fromAddress,
            'from_name' => $fromName !== '' ? $fromName : 'Save Rack',
            'subject' => $subject,
            'body_html' => $bodyHtml,
        ]);

        Mail::to($testEmail)->send(new AdminBroadcastMailable(
            $broadcast,
            $this->signatureHtml($fromAddress)
        ));
    }

    public function delete(AdminBroadcastEmail $broadcast): void
    {
        $broadcast->delete();
    }

    public function signatureHtml(string $fromAddress): string
    {
        $key = strtolower(trim($fromAddress));
        $options = config('crm.broadcast_from_options', []);
        $signature = is_array($options) && isset($options[$key]['signature'])
            ? (string) $options[$key]['signature']
            : 'info';

        if ($signature === 'audi') {
            return '<p style="margin:24px 0 0 0;font-size:14px;line-height:1.5;color:#151515;">'
                .'<strong>Save Rack Fulfillment</strong><br>'
                .'Audi K | Managing Partner<br>'
                .'P: 855-227-2221<br>'
                .'E: audi@saverack.com'
                .'</p>';
        }

        return '<p style="margin:24px 0 0 0;font-size:14px;line-height:1.5;color:#151515;">'
            .'<strong>Save Rack Fulfillment</strong><br>'
            .'Client Updates &amp; Notifications'
            .'</p>';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(AdminBroadcastEmail $broadcast): array
    {
        return [
            'id' => (int) $broadcast->id,
            'from_address' => (string) $broadcast->from_address,
            'from_name' => (string) ($broadcast->from_name ?? ''),
            'subject' => (string) $broadcast->subject,
            'body_html' => (string) $broadcast->body_html,
            'qty_sent' => (int) $broadcast->qty_sent,
            'recipient_count' => (int) $broadcast->recipient_count,
            'status' => (string) $broadcast->status,
            'sent_at' => optional($broadcast->sent_at)->toIso8601String(),
            'created_at' => optional($broadcast->created_at)->toIso8601String(),
            'created_by_user_id' => $broadcast->created_by_user_id
                ? (int) $broadcast->created_by_user_id
                : null,
        ];
    }

    /**
     * @return list<array{address: string, name: string}>
     */
    public function fromOptions(): array
    {
        $options = config('crm.broadcast_from_options', []);
        if (! is_array($options)) {
            return [];
        }

        $out = [];
        foreach ($options as $address => $meta) {
            $out[] = [
                'address' => (string) $address,
                'name' => (string) (is_array($meta) ? ($meta['name'] ?? '') : ''),
            ];
        }

        return $out;
    }

    private function isEmptyHtml(string $html): bool
    {
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $plain === '';
    }
}
