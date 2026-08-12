<?php

namespace CodeWithDiki\PaymentModule\Exceptions;

class DisbursementApprovalDeniedException extends \Exception
{
    public static function selfApproval(): self
    {
        return new self('The maker of a disbursement cannot approve it (separation of duties).');
    }

    public static function notAwaitingApproval(\BackedEnum $status): self
    {
        return new self('Only a queued disbursement can be approved or rejected; this one is '.$status->value.'.');
    }
}
