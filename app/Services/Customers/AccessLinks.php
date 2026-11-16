<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerAccessToken;

/**
 * Osobní odkazy zákaznic. Čitelný token existuje jen ve chvíli vydání: buď ho dostane
 * rovnou prohlížeč nové zákaznice, nebo ho kadeřnice pošle sama (WhatsApp, Messenger).
 * Aplikace žádné e-maily ani SMS neposílá.
 */
final class AccessLinks
{
    /** Vydá nový token a vrátí celý osobní odkaz. */
    public function issue(Customer $customer, ?int $scopeStaffId = null): string
    {
        return $this->url(CustomerAccessToken::issue($customer, $scopeStaffId));
    }

    public function url(string $plainToken): string
    {
        return route('customer.home', ['token' => $plainToken]);
    }
}
