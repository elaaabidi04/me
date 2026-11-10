<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 2026-11-07: alert to ADMIN_EMAIL when InjectionGuard blocks an attempt.
 * Queued (ShouldQueue) so the visitor's request never waits on SMTP.
 */
class InjectionAttemptMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $account,
        public string $where,
        public string $rule,
        public ?string $text,
        public string $at,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[me] blocked a prompt-injection attempt');
    }

    public function content(): Content
    {
        $e = fn ($v) => e($v);
        $quote = $this->text !== null
            ? '<blockquote style="margin:12px 0;padding:10px 14px;border-left:3px solid #A07230;background:#F5F2EC;white-space:pre-wrap;font-family:ui-monospace,Menlo,monospace;font-size:13px">'.$e($this->text).'</blockquote>'
            : '<p style="color:#6B6F72">Text not included — this was a real account, and its journal stays private.</p>';

        return new Content(htmlString: '<div style="font-family:system-ui,sans-serif;font-size:14px;color:#26282B;line-height:1.5">'
            .'<p><strong>Blocked a prompt-injection attempt.</strong> It never reached the AI; the user got a fixed reply.</p>'
            .'<table style="border-collapse:collapse;font-size:13px">'
            .'<tr><td style="padding:2px 12px 2px 0;color:#6B6F72">who</td><td>'.$e($this->account).'</td></tr>'
            .'<tr><td style="padding:2px 12px 2px 0;color:#6B6F72">where</td><td>'.$e($this->where).'</td></tr>'
            .'<tr><td style="padding:2px 12px 2px 0;color:#6B6F72">rule</td><td>'.$e($this->rule).'</td></tr>'
            .'<tr><td style="padding:2px 12px 2px 0;color:#6B6F72">when</td><td>'.$e($this->at).'</td></tr>'
            .'</table>'.$quote
            .'<p style="color:#6B6F72;font-size:12px">Further attempts from the same account within 10 minutes are blocked and logged but not emailed. All of them show on /admin.</p>'
            .'</div>');
    }
}
