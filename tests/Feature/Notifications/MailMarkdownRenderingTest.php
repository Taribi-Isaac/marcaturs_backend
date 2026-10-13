<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\TestNotification;
use Composer\InstalledVersions;
use Tests\TestCase;

/**
 * Mail notifications render through Illuminate\Mail\Markdown (CommonMark core + Table extension),
 * and several of them interpolate user-supplied text into their lines.
 */
class MailMarkdownRenderingTest extends TestCase
{
    public function test_commonmark_includes_the_september_2026_security_fixes(): void
    {
        // GHSA-3q6v-r5mr-hxv8 (Table extension quadratic DoS) and GHSA-97jj-33gv-5xf9
        // (DisallowedRawHtml bypass) are fixed in 2.10.2.
        $installed = (string) InstalledVersions::getVersion('league/commonmark');

        $this->assertTrue(
            version_compare($installed, '2.10.2', '>='),
            "league/commonmark {$installed} is affected by GHSA-3q6v-r5mr-hxv8 / GHSA-97jj-33gv-5xf9",
        );
    }

    public function test_user_supplied_html_in_a_mail_line_is_escaped(): void
    {
        $html = $this->renderMail("<script>alert(1)</script>\n<script\n<img src=x onerror=alert(1)>");

        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringNotContainsStringIgnoringCase('<img src=x', $html);
        $this->assertStringContainsString('&lt;script', $html);
    }

    public function test_user_supplied_javascript_link_is_not_rendered_as_a_link(): void
    {
        $html = $this->renderMail('[Claim payout](javascript:alert(1))');

        $this->assertStringNotContainsStringIgnoringCase('href="javascript:', $html);
        $this->assertStringContainsString('Claim payout', $html);
    }

    public function test_large_pipe_free_paragraph_still_renders(): void
    {
        $html = $this->renderMail(str_repeat("12345678\n", 2000).'end-of-user-text');

        $this->assertStringContainsString('end-of-user-text', $html);
        $this->assertStringContainsString('This is a test notification.', $html);
    }

    private function renderMail(string $userText): string
    {
        $mail = (new TestNotification($userText))->toMail(User::factory()->make());
        $this->assertNotNull($mail);

        return (string) $mail->render();
    }
}
