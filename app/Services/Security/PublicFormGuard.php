<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Ochrana veřejného rezervačního formuláře proti robotům.
 *
 * Formulář po odeslání zakládá zákaznici a zabírá místo v kalendáři,
 * proto nestačí kontrola, která jde obejít vynecháním pole:
 *  - šifrované časové razítko vydané serverem (bez něj formulář neprojde),
 *  - minimální doba vyplnění a omezená platnost razítka,
 *  - limit odeslání na IP adresu za hodinu a za den,
 *  - skryté pole (honeypot) řeší controller.
 */
final class PublicFormGuard
{
    public const FIELD = '_cas';

    private const MIN_SECONDS = 4;

    private const MAX_SECONDS = 3 * 3600;

    private const PER_HOUR = 4;

    private const PER_DAY = 10;

    /** Hodnota do skrytého pole formuláře. */
    public function stamp(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * @throws ValidationException
     */
    public function check(Request $request): void
    {
        $age = $this->age($request->input(self::FIELD));

        if ($age === null || $age > self::MAX_SECONDS) {
            $this->fail('Formulář byl otevřený příliš dlouho. Zkontrolujte prosím výběr a odešlete ho znovu.');
        }

        if ($age < self::MIN_SECONDS) {
            $this->fail('To bylo moc rychlé. Zkuste to prosím za chvilku znovu.');
        }

        $hourly = 'verejna-rezervace:h:'.$request->ip();
        $daily = 'verejna-rezervace:d:'.$request->ip();

        if (RateLimiter::tooManyAttempts($hourly, self::PER_HOUR) || RateLimiter::tooManyAttempts($daily, self::PER_DAY)) {
            $this->fail('Z vašeho připojení přišlo příliš mnoho rezervací. Zkuste to prosím později, nebo zavolejte do salonu.');
        }
    }

    /** Započítá úspěšně vytvořenou rezervaci do limitů. */
    public function hit(Request $request): void
    {
        RateLimiter::hit('verejna-rezervace:h:'.$request->ip(), 3600);
        RateLimiter::hit('verejna-rezervace:d:'.$request->ip(), 86400);
    }

    /** Stáří razítka v sekundách, nebo null, když chybí či není naše. */
    private function age(mixed $stamp): ?int
    {
        if (! is_string($stamp) || $stamp === '') {
            return null;
        }

        try {
            $issued = (int) Crypt::decryptString($stamp);
        } catch (\Throwable) {
            return null;
        }

        return now()->getTimestamp() - $issued;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['form' => $message]);
    }
}
