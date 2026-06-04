<?php

namespace App\Console\Commands;

use App\Services\SlackDeliveryService;
use App\Services\SlackStatusIconUrlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class VerifySlackStatusSetupCommand extends Command
{
    protected $signature = 'slack:verify-status-setup
                            {--channel= : Optional #channel or C-id to test chat.postMessage (dry-run skips post)}';

    protected $description = 'Verify Slack bot token, truck icon URLs, and storage publish for account status notifications';

    public function handle(SlackStatusIconUrlService $icons, SlackDeliveryService $slack): int
    {
        $ok = true;

        $this->info('Slack account status (truck icon) setup check');
        $this->newLine();

        $token = trim((string) config('billing.slack.bot_token', ''));
        if ($token === '') {
            $this->error('SLACK_BOT_USER_OAUTH_TOKEN is not set.');
            $this->line('  Incoming webhooks cannot show custom icon_url. Add xoxb- token with scopes:');
            $this->line('  chat:write, chat:write.customize, channels:join — then reinstall the app.');
            $ok = false;
        } elseif (! str_starts_with($token, 'xoxb-')) {
            $this->error('SLACK_BOT_USER_OAUTH_TOKEN must start with xoxb- (bot user OAuth token).');
            $ok = false;
        } else {
            $this->info('Bot token: present (xoxb-)');
        }

        $base = rtrim((string) config('billing.slack.public_asset_base_url'), '/')
            ?: rtrim((string) config('app.url'), '/');
        if ($base === '' || str_contains($base, 'localhost') || str_contains($base, '127.0.0.1')) {
            $this->error('SLACK_PUBLIC_ASSET_BASE_URL or APP_URL must be your public HTTPS CRM domain (not localhost).');
            $ok = false;
        } else {
            $this->info('Public base URL: '.$base);
        }

        foreach ([
            'live avatar' => $icons->slackAvatarUrl(true),
            'paused avatar' => $icons->slackAvatarUrl(false),
            'live full' => $icons->liveUrl(),
            'paused full' => $icons->pausedUrl(),
        ] as $label => $url) {
            if ($url === '') {
                $this->error("Icon URL ({$label}): could not build.");
                $ok = false;

                continue;
            }

            $this->line("Icon URL ({$label}): {$url}");
            try {
                $response = Http::timeout(10)->get($url);
                $type = strtolower((string) $response->header('Content-Type'));
                if ($response->successful() && str_contains($type, 'image')) {
                    $this->info("  HTTP {$response->status()} — image OK");
                } else {
                    $this->error("  HTTP {$response->status()} — expected image/png (got: {$type})");
                    $this->line('  Deploy public/images/slack/*.png (including *-thumb.png) to production.');
                    $ok = false;
                }
            } catch (\Throwable $e) {
                $this->error('  Fetch failed: '.$e->getMessage());
                $ok = false;
            }
        }

        $srcLive = public_path('images/slack/shipping-status-live.png');
        if (! is_file($srcLive)) {
            $this->warn('Missing public/images/slack/shipping-status-live.png — deploy icon assets.');
            $ok = false;
        }

        $srcThumb = public_path('images/slack/avatars/shipping-status-live.png');
        if (! is_file($srcThumb)) {
            $this->warn('Missing public/images/slack/avatars/shipping-status-live.png — deploy avatar icons.');
            $ok = false;
        }

        $link = public_path('storage');
        if (! file_exists($link)) {
            $this->line('Note: public/storage symlink not required for /images/slack/ icons.');
        }

        $channel = $this->option('channel');
        if (is_string($channel) && trim($channel) !== '' && $token !== '' && str_starts_with($token, 'xoxb-')) {
            $channel = $slack->normalizeChannelName(trim($channel));
            $this->newLine();
            $this->info("Posting test message to {$channel}…");
            try {
                $result = $slack->post(
                    $channel,
                    'Slack status icon setup test (safe to delete).',
                    'Shipping Status Update',
                    [
                        'icon_url' => $icons->slackAvatarUrl(true),
                        'customize_identity' => true,
                        'prefer_bot' => true,
                    ]
                );
                $this->info('  Posted via '.$result['method'].' — check for green truck avatar.');
            } catch (\Throwable $e) {
                $this->error('  Post failed: '.$e->getMessage());
                $this->line('  Invite the bot: /invite @YourBot in that channel.');
                $ok = false;
            }
        } elseif ($ok) {
            $this->newLine();
            $this->line('Optional: php artisan slack:verify-status-setup --channel=#your-test-channel');
        }

        $this->newLine();
        if ($ok) {
            $this->info('All checks passed. Toggle an account Live/Paused; logs should show delivery: bot.');

            return self::SUCCESS;
        }

        $this->error('Fix the issues above, then php artisan config:clear on production.');

        return self::FAILURE;
    }
}
