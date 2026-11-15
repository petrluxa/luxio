<?php

namespace App\Services\Customers;

use App\Mail\AccessLinkMail;
use App\Models\Customer;
use App\Models\CustomerAccessToken;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Osobní odkazy zákaznic. Čitelný token existuje jen ve chvíli vydání,
 * proto se odkaz buď rovnou pošle e-mailem / předá ke sdílení, nebo se pošle
 * dočasný podepsaný odkaz, přes který si zákaznice nový token vyzvedne.
 */
final class AccessLinks
{
    public const RECOVER_MINUTES = 30;

    public const EXISTING_MINUTES = 60 * 24;

    /** Vydá nový token a vrátí celý osobní odkaz. */
    public function issue(Customer $customer, ?int $scopeStaffId = null): string
    {
        return $this->url(CustomerAccessToken::issue($customer, $scopeStaffId));
    }

    public function url(string $plainToken): string
    {
        return route('customer.home', ['token' => $plainToken]);
    }

    /** E-mail po první rezervaci: osobní odkaz, aby ho zákaznice neztratila. */
    public function sendWelcome(Customer $customer, string $plainToken): void
    {
        if ($customer->email) {
            Mail::to($customer->email)->queue(new AccessLinkMail($customer, $this->url($plainToken), 'welcome'));
        }
    }

    /**
     * Dočasný odkaz na uložený e-mail (ztracený odkaz, nebo rezervace přes veřejný
     * formulář s už známým e-mailem/telefonem). Nový token vznikne až po otevření.
     */
    public function sendClaim(Customer $customer, string $reason = 'recover'): void
    {
        if (! $customer->email) {
            return;
        }

        $minutes = $reason === 'recover' ? self::RECOVER_MINUTES : self::EXISTING_MINUTES;
        $url = URL::temporarySignedRoute('recover.claim', now()->addMinutes($minutes), ['customer' => $customer->id]);

        Mail::to($customer->email)->queue(new AccessLinkMail($customer, $url, $reason, $minutes));
    }
}
