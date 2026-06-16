<?php

namespace CodeWithDiki\PaymentModule\Exceptions;

class DisbursementApprovalDeniedException extends \Exception
{
    public static function selfApproval(): self
    {
        return new self('The maker of a disbursement cannot approve it (separation of duties).');
    }
}
