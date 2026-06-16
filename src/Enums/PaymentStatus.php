<?php

namespace CodeWithDiki\PaymentModule\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';

    /** A terminal status will not transition further; used to ignore replayed webhooks. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::PAID, self::FAILED], true);
    }
}
