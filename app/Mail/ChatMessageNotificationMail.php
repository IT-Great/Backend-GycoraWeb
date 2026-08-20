<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChatMessageNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $sender;
    public $chatMessage;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($sender, $chatMessage)
    {
        $this->sender = $sender;
        $this->chatMessage = $chatMessage;
    }

    /**
     * Get the message envelope.
     *
     * @return \Illuminate\Mail\Mailables\Envelope
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            // Mengatur pengirim (From) secara spesifik menggunakan email perusahaan
            from: new Address('gycora.essence@gmail.com', 'Gycora Care'),
            subject: 'Pesan Baru dari ' . $this->sender->first_name . ' di Pusat Bantuan Gycora',
        );
    }

    /**
     * Get the message content definition.
     *
     * @return \Illuminate\Mail\Mailables\Content
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.chat_notification',
        );
    }
}
