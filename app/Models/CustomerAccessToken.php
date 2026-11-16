<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Osobní odkaz zákaznice `/k/{token}`. V databázi je jen SHA-256 hash,
 * čitelný token existuje pouze ve chvíli vydání (prohlížeč nové zákaznice / sdílení kadeřnicí).
 */
class CustomerAccessToken extends Model
{
    public const LENGTH = 40;

    protected $fillable = ['customer_id', 'token_hash', 'scope_staff_id', 'last_used_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Vydá nový token a vrátí jeho čitelnou podobu – jedinkrát, nikam se neukládá.
     */
    public static function issue(Customer $customer, ?int $scopeStaffId = null): string
    {
        $plain = Str::random(self::LENGTH);

        static::create([
            'customer_id' => $customer->id,
            'token_hash' => self::hash($plain),
            'scope_staff_id' => $scopeStaffId,
        ]);

        return $plain;
    }

    /** Najde platný (nezrušený) token podle čitelné podoby. */
    public static function findValid(string $plain): ?self
    {
        if (strlen($plain) !== self::LENGTH || ! ctype_alnum($plain)) {
            return null;
        }

        return static::where('token_hash', self::hash($plain))->whereNull('revoked_at')->first();
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scope_staff_id');
    }
}
