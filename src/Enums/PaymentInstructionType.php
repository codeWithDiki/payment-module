<?php

namespace CodeWithDiki\PaymentModule\Enums;

/**
 * How the customer is expected to settle a payment, regardless of gateway.
 *
 * Stripe's hosted checkout is reported as EWallet: the customer flow is identical
 * (send them to redirect_url), so consumers need no fourth branch.
 */
enum PaymentInstructionType: string
{
    case Qr = 'qr';
    case EWallet = 'ewallet';
    case VirtualAccount = 'virtual_account';
}
