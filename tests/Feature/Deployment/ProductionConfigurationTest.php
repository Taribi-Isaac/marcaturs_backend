<?php

namespace Tests\Feature\Deployment;

use App\Models\Campaign;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Support\Campaigns\OfficialPaymentShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * Production runs with `php artisan config:cache`, where env() outside config
 * files returns null under PHP-FPM. Links must therefore come from config().
 */
class ProductionConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCTION_APP = 'https://app.marcaturshub.com.ng';

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml sets FRONTEND_URL=http://localhost:3000; a config override
        // proves the code no longer reads the environment directly.
        config(['app.frontend_url' => self::PRODUCTION_APP]);
    }

    public function test_password_reset_link_uses_configured_frontend_url(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.test']);

        $mail = (new ResetPasswordNotification('reset-token'))->toMail($user);

        $this->assertStringStartsWith(self::PRODUCTION_APP.'/reset-password?', $mail->actionUrl);
    }

    public function test_browser_email_verification_redirects_to_configured_frontend_url(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->get($url, ['Accept' => 'text/html'])
            ->assertRedirect(self::PRODUCTION_APP.'/login?email_verified=1');
    }

    public function test_official_payment_share_url_uses_configured_frontend_url(): void
    {
        $campaign = Campaign::factory()->create();

        $references = OfficialPaymentShare::references($campaign);

        $this->assertSame(self::PRODUCTION_APP.'/pay/'.$references['token'], $references['share_url']);
    }

    public function test_frontend_url_is_read_from_config_cache_safe_key(): void
    {
        $this->assertSame(self::PRODUCTION_APP, config('app.frontend_url'));

        config(['app.frontend_url' => null]);
        $campaign = Campaign::factory()->create();

        $this->assertNull(OfficialPaymentShare::references($campaign)['share_url']);
    }

    public function test_resend_mailer_resolves_with_installed_sdk(): void
    {
        config([
            'mail.default' => 'resend',
            'services.resend.key' => 'placeholder-not-a-credential',
        ]);
        app('mail.manager')->forgetMailers();

        $this->assertInstanceOf(ResendTransport::class, Mail::mailer('resend')->getSymfonyTransport());
    }

    public function test_reply_to_header_is_added_only_when_configured(): void
    {
        config(['mail.default' => 'array', 'mail.reply_to' => ['address' => null, 'name' => null]]);
        app('mail.manager')->forgetMailers();

        $this->assertSame([], $this->sendProbe()->getOriginalMessage()->getReplyTo());

        config(['mail.reply_to' => ['address' => 'support@marcaturshub.com.ng', 'name' => 'MarcatursHub Support']]);
        app('mail.manager')->forgetMailers();

        $replyTo = $this->sendProbe()->getOriginalMessage()->getReplyTo();
        $this->assertCount(1, $replyTo);
        $this->assertSame('support@marcaturshub.com.ng', $replyTo[0]->getAddress());
    }

    private function sendProbe(): SentMessage
    {
        Mail::mailer('array')->raw('probe', fn ($message) => $message->to('probe@example.test')->subject('probe'));

        /** @var ArrayTransport $transport */
        $transport = Mail::mailer('array')->getSymfonyTransport();

        return $transport->messages()->last();
    }
}
