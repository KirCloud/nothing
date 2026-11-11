<?php

namespace Tests\Feature;

use Tests\TestCase;

class MailTemplateTest extends TestCase
{
    private const BASE = ['name' => 'Test <Panel>', 'url' => 'https://panel.example.com'];

    private function render(string $template, array $data = []): string
    {
        $html = view('mail.classic.' . $template, self::BASE + $data)->render();
        // Set MAIL_PREVIEW_DIR to keep the rendered HTML for a visual check.
        if ($dir = getenv('MAIL_PREVIEW_DIR')) file_put_contents($dir . '/' . $template . '.html', $html);
        return $html;
    }

    public function testEveryClassicTemplateSharesTheLayoutAndEscapesTheSiteName(): void
    {
        $link = 'https://api.example.com/api/v1/passport/oauth/app?ticket=' . str_repeat('a', 64) . '&state=' . str_repeat('b', 64);
        $templates = [
            'verify' => ['code' => '482913'], 'mailLogin' => ['link' => $link],
            'notify' => ['content' => "first line\r\nsecond line"], 'remindExpire' => [], 'remindTraffic' => [],
        ];
        foreach ($templates as $template => $data) {
            $html = $this->render($template, $data);
            $this->assertStringContainsString('class="mail-card"', $html, $template);
            $this->assertStringContainsString('Test &lt;Panel&gt;', $html, $template);
            $this->assertStringNotContainsString('<Panel>', $html, $template);
            $this->assertStringContainsString('https://panel.example.com/#/profile', $html, $template);
        }
    }

    public function testTemplatesCarryTheirOwnPayload(): void
    {
        $this->assertStringContainsString('482913', $this->render('verify', ['code' => '482913']));
        $link = 'https://api.example.com/api/v1/passport/oauth/app?ticket=abc&state=def';
        $login = $this->render('mailLogin', ['link' => $link]);
        $this->assertSame(2, substr_count($login, 'href="' . e($link) . '"'));
        $this->assertStringContainsString("first line<br />\r\nsecond line", $this->render('notify', ['content' => "first line\r\nsecond line"]));
    }
}
