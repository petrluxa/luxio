<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Cookie s osobním tokenem zákaznice (šifrovaná, HttpOnly, platnost 1 rok).
 */
final class CustomerCookie
{
    public const NAME = 'luxio_k';

    private const MINUTES = 60 * 24 * 365;

    public static function make(string $plainToken): SymfonyCookie
    {
        return Cookie::make(self::NAME, $plainToken, self::MINUTES, httpOnly: true, sameSite: 'lax');
    }

    public static function forget(): SymfonyCookie
    {
        return Cookie::forget(self::NAME);
    }

    /** Platný token z cookie, pokud v prohlížeči nějaký je. */
    public static function token(Request $request): ?string
    {
        $plain = $request->cookie(self::NAME);

        return is_string($plain) && CustomerAccessToken::findValid($plain) ? $plain : null;
    }

    public static function customer(Request $request): ?Customer
    {
        $plain = $request->cookie(self::NAME);

        return is_string($plain) ? CustomerAccessToken::findValid($plain)?->customer : null;
    }
}
