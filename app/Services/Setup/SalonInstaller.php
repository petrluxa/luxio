<?php

namespace App\Services\Setup;

use App\Enums\UserRole;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Založení salonu a účtu majitelky – společné pro příkaz `luxio:install`
 * i pro instalaci přes prohlížeč na hostingu bez příkazové řádky.
 * Heslo si majitelka volí sama, žádné výchozí neexistuje.
 */
final class SalonInstaller
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'unique:salons,slug'],
            'owner' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(10)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Adresa smí obsahovat jen malá písmena bez háčků, číslice a pomlčky.',
            'slug.unique' => 'Salon s touto adresou už existuje.',
            'email.unique' => 'Účet s tímto přihlašovacím e-mailem už existuje.',
        ];
    }

    /**
     * @param  array{name: string, slug: string, owner: string, email: string, password: string}  $data  ověřená data
     */
    public function install(array $data): Salon
    {
        return DB::transaction(function () use ($data) {
            $salon = Salon::create(['name' => $data['name'], 'slug' => $data['slug'], 'timezone' => config('app.timezone')]);

            User::create([
                'salon_id' => $salon->id,
                'name' => $data['owner'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
                'role' => UserRole::Owner,
                'is_bookable' => true,
            ]);

            return $salon;
        });
    }
}
