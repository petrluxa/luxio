<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Založení salonu a účtu majitelky na ostrém serveru. Heslo se zadává při instalaci
 * (skrytě), žádné výchozí heslo neexistuje. Ukázková data (`db:seed`) jsou jen pro vývoj.
 */
class InstallSalon extends Command
{
    protected $signature = 'luxio:install';

    protected $description = 'Založí salon a účet majitelky (ostrý provoz)';

    public function handle(): int
    {
        $name = text('Název salonu', placeholder: 'Kadeřnictví Petra', required: true);
        $slug = text('Adresa veřejného odkazu (/s/…)', default: Str::slug($name), required: true);
        $owner = text('Jméno majitelky (uvidí ho zákaznice)', placeholder: 'Petra', required: true);
        $email = text('Přihlašovací e-mail', required: true);
        $secret = password('Heslo (aspoň 10 znaků)', required: true);

        $validator = Validator::make(
            ['name' => $name, 'slug' => $slug, 'owner' => $owner, 'email' => $email, 'password' => $secret],
            [
                'name' => ['required', 'string', 'max:100'],
                'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'unique:salons,slug'],
                'owner' => ['required', 'string', 'max:60'],
                'email' => ['required', 'email', 'max:190', 'unique:users,email'],
                'password' => ['required', Password::min(10)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $slug, $owner, $email, $secret) {
            $salon = Salon::create(['name' => $name, 'slug' => $slug, 'timezone' => config('app.timezone')]);

            User::create([
                'salon_id' => $salon->id,
                'name' => $owner,
                'email' => mb_strtolower($email),
                'password' => $secret,
                'role' => UserRole::Owner,
                'is_bookable' => true,
            ]);
        });

        $this->info('Salon je založený.');
        $this->line('  Administrace:  '.url('/admin'));
        $this->line('  Veřejný odkaz: '.url('/s/'.$slug));
        $this->line('Dál v administraci nastavte služby a pracovní dobu.');

        return self::SUCCESS;
    }
}
