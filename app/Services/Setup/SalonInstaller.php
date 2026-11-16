<?php

namespace App\Services\Setup;

use App\Enums\UserRole;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Products\ProductCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Založení salonu a účtu správce – společné pro příkaz `luxio:install`
 * i pro instalaci přes prohlížeč na hostingu bez příkazové řádky.
 * Heslo si správce volí sám, žádné výchozí neexistuje.
 *
 * Aby šla aplikace hned vyzkoušet, salon dostane vzorový ceník, pracovní dobu po–pá 9–17
 * a katalog přípravků; všechno se pak upraví v administraci.
 */
final class SalonInstaller
{
    public const LOGIN_PATTERN = '/^[a-z0-9._@-]{3,60}$/';

    /** Barvy kadeřnic v rozvrhu (správce si je může změnit). */
    public const COLORS = ['#74624F', '#A8875E', '#5E7A6B', '#8C5A5A', '#6A6F8C', '#8A7A3F'];

    /** Vzorový ceník: název, popis, cena od, cena do, práce, pauza, práce, úklid (minuty). */
    private const SERVICES = [
        ['Dámský střih', 'Mytí, střih a foukaná do tvaru.', 650, null, 60, 0, 0, 5],
        ['Pánský střih', 'Střih strojkem nebo nůžkami.', 350, null, 30, 0, 0, 5],
        ['Dětský střih', null, 250, null, 30, 0, 0, 5],
        ['Foukaná', 'Mytí a foukaná.', 450, null, 45, 0, 0, 5],
        ['Barvení a střih', 'Barva na odrosty nebo celé délky, střih a styling.', 1400, 2200, 40, 35, 60, 10],
        ['Melír a střih', 'Melír do fólií, tónování, střih.', 1800, 2800, 60, 30, 60, 10],
        ['Společenský účes', null, 800, 1200, 60, 0, 0, 5],
    ];

    public function __construct(private readonly ProductCatalog $catalog) {}

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'owner' => ['required', 'string', 'max:60'],
            'login' => ['required', 'string', 'regex:'.self::LOGIN_PATTERN, 'unique:users,login'],
            'password' => ['required', Password::min(8)],
            'staff' => ['nullable', 'string', 'max:600'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.regex' => 'Přihlašovací jméno smí mít 3–60 znaků: malá písmena bez háčků, číslice, tečku, pomlčku nebo podtržítko.',
            'login.unique' => 'Účet s tímto přihlašovacím jménem už existuje.',
        ];
    }

    /**
     * @param  array{name: string, owner: string, login: string, password: string, staff?: ?string}  $data  ověřená data
     */
    public function install(array $data): Salon
    {
        return DB::transaction(function () use ($data) {
            $salon = Salon::create([
                'name' => $data['name'],
                'slug' => $this->freeSlug($data['name']),
                'timezone' => config('app.timezone'),
            ]);

            User::create([
                'salon_id' => $salon->id,
                'name' => $data['owner'],
                'login' => mb_strtolower($data['login']),
                'password' => $data['password'],
                'role' => UserRole::Owner,
                'color' => self::COLORS[0],
                'is_bookable' => true,
            ]);

            $this->complete($salon, $data['staff'] ?? null);

            return $salon;
        });
    }

    /**
     * Je salon založený, ale ještě se nepoužívá? Bez jediné rezervace a buď bez ceníku, nebo jen se
     * zakladatelem (bez dalších kadeřnic). Takový jde dovybavit – typicky salon založený starší verzí
     * instalace, která ceník ani kadeřnice nepřidávala.
     */
    public function isIncomplete(Salon $salon): bool
    {
        return ! $salon->bookings()->exists()
            && (! $salon->services()->exists() || $salon->users()->count() <= 1);
    }

    /**
     * Dovybaví salon, aby šel hned používat: kadeřnice v rozvrhu, vzorový ceník, pracovní doba
     * po–pá 9–17 a katalog přípravků. Nic nemaže a nepřepisuje – přidá jen to, co chybí.
     *
     * Se samostatnými kadeřnicemi je zakladatel správcem (recepcí): zákaznice se k němu
     * neobjednávají. Bez nich je to salon jedné kadeřnice, která je zároveň správcem.
     *
     * @return array{staff: int, services: int, products: int} kolik čeho přibylo
     */
    public function complete(Salon $salon, ?string $staff = null): array
    {
        return DB::transaction(function () use ($salon, $staff) {
            $names = $this->staffNames($staff);
            $users = $salon->users()->orderBy('id')->get();
            $known = $users->map(fn (User $user) => mb_strtolower($user->name))->all();
            $added = 0;

            foreach ($names as $name) {
                if (in_array(mb_strtolower($name), $known, true)) {
                    continue;
                }

                User::create([
                    'salon_id' => $salon->id,
                    'name' => $name,
                    'role' => UserRole::Staff,
                    'color' => self::COLORS[$added % count(self::COLORS)],
                    'is_bookable' => true,
                ]);

                $added++;
            }

            if ($added > 0) {
                $listed = array_map('mb_strtolower', $names);

                $users->filter(fn (User $user) => $user->isOwner() && $user->is_bookable
                    && ! in_array(mb_strtolower($user->name), $listed, true)
                    && ! $user->bookings()->exists())
                    ->each(fn (User $owner) => $owner->update(['is_bookable' => false]));
            }

            $services = 0;

            if (! $salon->services()->exists()) {
                foreach (self::SERVICES as $i => [$name, $description, $from, $to, $work1, $pause, $work2, $buffer]) {
                    Service::create([
                        'salon_id' => $salon->id,
                        'name' => $name,
                        'description' => $description,
                        'price_from' => $from,
                        'price_to' => $to,
                        'work_1' => $work1,
                        'pause' => $pause,
                        'work_2' => $work2,
                        'buffer_after' => $buffer,
                        'sort' => $i + 1,
                    ]);

                    $services++;
                }
            }

            $serviceIds = $salon->services()->where('is_active', true)->pluck('id')->all();

            foreach ($salon->staff()->get() as $member) {
                if (! $member->services()->exists()) {
                    $member->services()->attach($serviceIds);
                }

                if (! $member->workingHours()->exists()) {
                    $member->workingHours()->createMany(
                        array_map(fn (int $weekday) => ['weekday' => $weekday, 'starts_at' => '09:00', 'ends_at' => '17:00'], [1, 2, 3, 4, 5]),
                    );
                }
            }

            return ['staff' => $added, 'services' => $services, 'products' => $this->catalog->import($salon)];
        });
    }

    /**
     * Jména kadeřnic z formuláře: každé na vlastním řádku nebo oddělená čárkou.
     *
     * @return list<string>
     */
    private function staffNames(?string $input): array
    {
        // Poškozený text (jiné kódování než UTF-8) by se dál tiše zahodil – raději žádná jména.
        if (! mb_check_encoding((string) $input, 'UTF-8')) {
            return [];
        }

        $names = preg_split('/[\r\n,;]+/u', (string) $input) ?: [];

        return collect($names)
            ->map(fn (string $name) => mb_substr(trim(preg_replace('/\s+/u', ' ', $name)), 0, 60))
            ->filter()
            ->unique(fn (string $name) => mb_strtolower($name))
            ->take(12)
            ->values()
            ->all();
    }

    /** Adresa veřejného odkazu z názvu salonu („Kadeřnictví Eva“ → kadernictvi-eva). */
    private function freeSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'salon', 50, '');
        $slug = $base;

        for ($i = 2; Salon::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
