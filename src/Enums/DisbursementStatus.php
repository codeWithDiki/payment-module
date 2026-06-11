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
}
