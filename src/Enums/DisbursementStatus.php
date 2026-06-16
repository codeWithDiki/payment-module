<?php

namespace CodeWithDiki\PaymentModule\Enums;

enum DisbursementStatus: string
{
    case PENDING = 'pending';
    case QUEUED = 'queued';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case PROCESSED = 'processed';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    /** A terminal status will not transition further; used to ignore replayed webhooks. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::REJECTED], true);
    }
}
