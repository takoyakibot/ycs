<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactFormMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public array $contactData
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $categories = [
            'general' => '一般的なお問い合わせ',
            'bug' => '不具合報告',
            'feature' => '機能リクエスト',
            'other' => 'その他',
        ];

        $categoryName = $categories[$this->contactData['category']] ?? $this->contactData['category'];

        return new Envelope(
            replyTo: $this->contactData['email'],
            subject: "[歌枠履歴er:D] {$categoryName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.contact',
            with: [
                'name' => $this->contactData['name'] ?? '匿名',
                'email' => $this->contactData['email'],
                'category' => $this->contactData['category'],
                'message' => $this->contactData['message'],
                'ipAddress' => $this->contactData['ip_address'] ?? null,
                'userAgent' => $this->contactData['user_agent'] ?? null,
                'submittedAt' => $this->contactData['submitted_at'] ?? null,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
