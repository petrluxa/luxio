<?php

namespace App\Console\Commands;

use App\Services\Setup\SalonInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Založení salonu a účtu majitelky na ostrém serveru. Heslo se zadává při instalaci
 * (skrytě), žádné výchozí heslo neexistuje. Ukázková data (`db:seed`) jsou jen pro vývoj.
 *
 * Na hostingu bez příkazové řádky totéž udělá adresa /instalace/{klíč} (config/luxio.php).
 */
class InstallSalon extends Command
{
    protected $signature = 'luxio:install';

    protected $description = 'Založí salon a účet majitelky (ostrý provoz)';

    public function handle(SalonInstaller $installer): int
    {
        $name = text('Název salonu', placeholder: 'Kadeřnictví Petra', required: true);
        $slug = text('Adresa veřejného odkazu (/s/…)', default: Str::slug($name), required: true);
        $owner = text('Jméno majitelky (uvidí ho zákaznice)', placeholder: 'Petra', required: true);
        $email = text('Přihlašovací e-mail', required: true);
        $secret = password('Heslo (aspoň 10 znaků)', required: true);

        $validator = Validator::make(
            ['name' => $name, 'slug' => $slug, 'owner' => $owner, 'email' => $email, 'password' => $secret],
            $installer->rules(),
            $installer->messages(),
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $installer->install($validator->validated());

        $this->info('Salon je založený.');
        $this->line('  Administrace:  '.url('/admin'));
        $this->line('  Veřejný odkaz: '.url('/s/'.$slug));
        $this->line('Dál v administraci nastavte služby a pracovní dobu.');

        return self::SUCCESS;
    }
}
