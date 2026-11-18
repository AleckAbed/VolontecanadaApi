<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    use BelongsToCabinet;

    protected $fillable = ['invoice_id', 'description', 'quantity', 'unit_price_cents', 'taxable', 'amount_cents', 'sort_order'];

    protected $casts = [
        'quantity' => 'float',
        'unit_price_cents' => 'integer',
        'amount_cents' => 'integer',
        'taxable' => 'boolean',
    ];
}
