<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Http\Controllers\TransactionController;

class ProcessFlashSaleCheckout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $userId;
    public $requestData;
    public $ipAddress;
    public $ticketId;

    public function __construct($userId, $requestData, $ipAddress, $ticketId)
    {
        $this->userId = $userId;
        $this->requestData = $requestData;
        $this->ipAddress = $ipAddress;
        $this->ticketId = $ticketId;
    }

    public function handle()
    {
        // Lempar pekerjaan berat ke TransactionController, tapi dieksekusi di Background
        app(TransactionController::class)->executeCheckoutLogic(
            $this->userId,
            $this->requestData,
            $this->ipAddress,
            $this->ticketId
        );
    }
}
