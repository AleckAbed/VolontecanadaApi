<?php

namespace App\Mail;

use App\Models\Cabinet;
use App\Models\Invoice;

/** Reçu de paiement envoyé au client. */
class InvoicePaidMail extends InvoiceMail
{
    public function __construct(Invoice $invoice, ?Cabinet $cabinet)
    {
        parent::__construct($invoice, $cabinet, true);
    }
}
