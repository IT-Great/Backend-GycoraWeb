<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

// Pastikan ada implements ShouldQueue agar diproses di background (tidak membebani Admin)
class BackInStockMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $product;
    public $user;

    public function __construct(Product $product, User $user)
    {
        $this->product = $product;
        $this->user = $user;
    }

    public function build()
    {
        return $this->subject('✨ Kabar Gembira! ' . $this->product->name . ' Tersedia Kembali')
                    ->markdown('emails.back_in_stock');
    }
}
