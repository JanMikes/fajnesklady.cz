<?php

declare(strict_types=1);

namespace App\Service\Order;

use App\Entity\Contract;
use App\Entity\Order;

/**
 * Spine of spec 043. Every customer-facing surface (signing page, signing
 * email, completion page, rental-activated email) reads one of the three
 * situations below to pick its wording.
 *
 * Detection ordering matches {@see customer_billing_status.html.twig} (spec
 * 030) exactly: free wins over prepaid, prepaid wins over GoPay. The two
 * factory entry points exist because some surfaces run before contract
 * creation (sign page / signing-link email) and some after (rental
 * activated email).
 *
 * GOPAY_FIRST_CHARGE means "the customer pays the first charge themselves" —
 * by card OR bank transfer (fromOrder() returns it for both); surfaces branch
 * on the payment method where the wording differs.
 */
enum CustomerBillingSituation: string
{
    case GOPAY_FIRST_CHARGE = 'gopay_first_charge';
    case EXTERNALLY_PREPAID = 'externally_prepaid';
    case FREE = 'free';

    public static function fromOrder(Order $order): self
    {
        if (0 === $order->individualMonthlyAmount) {
            return self::FREE;
        }
        if (null !== $order->paidThroughDate) {
            return self::EXTERNALLY_PREPAID;
        }

        return self::GOPAY_FIRST_CHARGE;
    }

    public static function fromContract(Contract $contract): self
    {
        if ($contract->isFree()) {
            return self::FREE;
        }
        // Only an admin-recorded prepayment on the ORDER is "externally
        // prepaid". The contract's own paidThroughDate is set by every
        // customer payment too (bank transfer, one-shot, tranche) — keying on
        // it told customers who had just paid by bank transfer that their
        // rental was "předplacen externě".
        if (null !== $contract->order->paidThroughDate) {
            return self::EXTERNALLY_PREPAID;
        }

        return self::GOPAY_FIRST_CHARGE;
    }
}
