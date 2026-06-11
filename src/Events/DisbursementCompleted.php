<?php

namespace CodeWithDiki\PaymentModule\Events;

use CodeWithDiki\PaymentModule\Models\Disbursement;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DisbursementCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Disbursement $disbursement
    ) {
        //
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('disbursement-completed'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'cwd.payment-module.disbursement-completed';
    }
}
