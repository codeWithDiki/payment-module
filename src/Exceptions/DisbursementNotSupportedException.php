<?php

namespace CodeWithDiki\PaymentModule\Exceptions;

class DisbursementNotSupportedException extends \Exception
{
    public static function forVendor(\BackedEnum $vendor): self
    {
        return new self("Vendor [{$vendor->value}] does not support disbursement.");
    }
}
