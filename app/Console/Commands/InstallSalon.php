<?php

namespace App\Console\Commands;

use App\Services\Setup\SalonInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Založení salonu a účtu správce na ostrém serveru. Heslo se zadává při instalaci
 * (skrytě), žádné výchozí heslo neexistuje. Ukázková data (`db:seed`) jsou jen pro vývoj.
 *
 * Na hostingu bez příkazové řádky totéž udělá adresa /instalace/{klíč} (config/luxio.php).
 */
class InstallSalon extends Command
{
    protected $signature = 'luxio:install';

    protected $description = 'Založí salon a účet správce (ostrý provoz)';

    public function handle(SalonInstaller $installer): int
    {
        $name = text('Název salonu', placeholder: 'Kadeřnictví Luxio', required: true);
        $owner = text('Vaše jméno', placeholder: 'Petr', required: true);
        $login = text('Přihlašovací jméno správce (malá písmena bez háčků)', required: true);
        $secret = password('Heslo (aspoň 8 znaků)', required: true);
        $staff = text('Kadeřnice – jména oddělená čárkou (prázdné = kadeřnicí je správce)');

        $validator = Validator::make(
            ['name' => $name, 'owner' => $owner, 'login' => Str::lower(trim($login)), 'password' => $secret, 'staff' => $staff],
            $installer->rules(),
            $installer->messages(),
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $salon = $installer->install($validator->validated());

        $this->info('Salon je založený.');
        $this->line('  Administrace:  '.url('/admin'));
        $this->line('  Veřejný odkaz: '.url('/s/'.$salon->slug));
        $this->line('Salon má vzorový ceník, pracovní dobu po–pá 9–17 a katalog přípravků. Vše upravíte v administraci.');

        return self::SUCCESS;
    }
}
