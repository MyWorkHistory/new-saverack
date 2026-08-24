<?php

namespace App\Jobs;

use App\Mail\AdminBroadcastMailable;
use App\Models\AdminBroadcastEmail;
use App\Services\AdminBroadcastEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAdminBroadcastEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $broadcastId;

    public $timeout = 600;

    public $tries = 1;

    public function __construct(int $broadcastId)
    {
        $this->broadcastId = $broadcastId;
        $this->onConnection((string) config('queue.default', 'database'));
    }

    public function handle(AdminBroadcastEmailService $service): void
    {
        $broadcast = AdminBroadcastEmail::query()->find($this->broadcastId);
        if ($broadcast === null) {
            return;
        }

        $signatureHtml = $service->signatureHtml((string) $broadcast->from_address);
        $sent = 0;
        $failures = 0;

        $service->recipientQuery()->chunkById(100, function ($users) use ($broadcast, $signatureHtml, &$sent, &$failures) {
            foreach ($users as $user) {
                $email = trim((string) ($user->email ?? ''));
                if ($email === '') {
                    continue;
                }

                try {
                    Mail::to($email)->send(new AdminBroadcastMailable($broadcast, $signatureHtml));
                    $sent++;
                    $broadcast->qty_sent = $sent;
                    $broadcast->save();
                } catch (Throwable $e) {
                    $failures++;
                    Log::warning('admin_broadcast.send_failed', [
                        'broadcast_id' => $broadcast->id,
                        'user_id' => $user->id,
                        'email' => $email,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        });

        $broadcast->qty_sent = $sent;
        if ($sent === 0 && $failures > 0) {
            $broadcast->status = AdminBroadcastEmail::STATUS_FAILED;
        } else {
            $broadcast->status = AdminBroadcastEmail::STATUS_SENT;
        }
        $broadcast->save();

        Log::info('admin_broadcast.send_finished', [
            'broadcast_id' => $broadcast->id,
            'qty_sent' => $sent,
            'failures' => $failures,
            'status' => $broadcast->status,
        ]);
    }
}
