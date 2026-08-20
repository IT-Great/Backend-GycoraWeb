<?php

namespace App\Mail;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShippingUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public $transaction;
    public $statusPesan;
    public $statusJudul;

    public function __construct(Transaction $transaction, $statusPesan, $statusJudul)
    {
        $this->transaction = $transaction;
        $this->statusPesan = $statusPesan;
        $this->statusJudul = $statusJudul;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Update Pengiriman Pesanan [{$this->transaction->order_id}]: {$this->statusJudul}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.shipping_update',
        );
    }
}
