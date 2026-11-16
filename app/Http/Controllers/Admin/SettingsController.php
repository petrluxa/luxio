<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Setup\SalonInstaller;
use App\Support\Cz;
use App\Support\SalonSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Nastavení (jen správce salonu): údaje salonu, pravidla rezervací a kadeřnice.
 * Každý přihlášený si navíc může změnit vlastní heslo na obrazovce „Více“.
 */
class SettingsController extends AdminController
{
    /** Admin: rozcestník s boxíky – všechno nastavení salonu na jednom místě. */
    public function more(): View
    {
        $salon = $this->salon();

        return view('admin.more', [
            'tab' => 'more',
            'width' => 'wide',
            'salon' => $salon,
            'counts' => [
                'staff' => $salon->staff()->count(),
                'services' => $salon->services()->where('is_active', true)->count(),
                'products' => $salon->products()->where('is_active', true)->count(),
                'customers' => $salon->customers()->count(),
            ],
        ]);
    }

    /** Účet přihlášeného: heslo, upozornění na tomto zařízení, odhlášení. */
    public function account(): View
    {
        return view('admin.account', ['tab' => 'account']);
    }

    public function edit(): View
    {
        $this->ownerOnly();

        return view('admin.settings', [
            'tab' => 'more',
            'salon' => $this->salon(),
            'rules' => $this->salon()->rules(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->ownerOnly();
        $salon = $this->salon();

        $request->merge([
            'phone' => $request->filled('phone') ? Cz::normalizePhone($request->input('phone')) ?? 'x' : null,
            'ico' => $request->filled('ico') ? preg_replace('/\s+/', '', (string) $request->input('ico')) : null,
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('salons', 'slug')->ignore($salon->id)],
            'phone' => ['nullable', 'string', 'regex:/^\+\d{9,15}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:160'],
            'ico' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'slot_step' => ['required', 'integer', Rule::in([5, 10, 15, 20, 30, 60])],
            'min_notice_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'max_days_ahead' => ['required', 'integer', 'min:1', 'max:365'],
            'cancel_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'reminder_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'second_reminder_hours' => ['nullable', 'integer', 'min:1', 'max:48', 'lt:reminder_hours'],
        ], [
            'slug.regex' => 'Adresa odkazu smí obsahovat jen malá písmena bez diakritiky, číslice a pomlčky.',
            'phone.regex' => 'Zadejte prosím platné telefonní číslo.',
            'ico.regex' => 'IČO má osm číslic.',
            'second_reminder_hours.lt' => 'Druhá připomínka musí být blíž k termínu než ta první.',
        ]);

        $rules = new SalonSettings([
            'slot_step' => $data['slot_step'],
            'min_notice_hours' => $data['min_notice_hours'],
            'max_days_ahead' => $data['max_days_ahead'],
            'cancel_hours' => $data['cancel_hours'],
            'approve_new_customers' => $request->boolean('approve_new_customers'),
            'reminder_hours' => $data['reminder_hours'],
            'second_reminder_hours' => $data['second_reminder_hours'] ?? null,
        ]);

        // approve_new_customers = false se musí uložit výslovně (výchozí je true).
        $salon->update([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'phone' => $data['phone'],
            'address' => $data['address'] ?? null,
            'company' => $data['company'] ?? null,
            'ico' => $data['ico'] ?? null,
            'settings' => ['approve_new_customers' => $request->boolean('approve_new_customers')] + $rules->toArray(),
        ]);

        return back()->with('status', 'Nastavení je uložené.');
    }

    /** Kadeřnice a správci salonu: kdo je v rozvrhu a kdo se umí přihlásit. */
    public function staffIndex(): View
    {
        $this->ownerOnly();

        return view('admin.staff-index', [
            'tab' => 'more',
            'users' => $this->salon()->users()->orderByDesc('is_bookable')->orderBy('name')->get(),
        ]);
    }

    public function createStaff(): View
    {
        $this->ownerOnly();

        $used = $this->salon()->users()->pluck('color')->map(fn (string $c) => strtoupper($c))->all();
        $color = collect(SalonInstaller::COLORS)->first(fn (string $c) => ! in_array($c, $used, true)) ?? SalonInstaller::COLORS[0];

        return view('admin.staff', ['tab' => 'more', 'member' => new User(['is_bookable' => true, 'color' => $color, 'role' => UserRole::Staff])]);
    }

    public function storeStaff(Request $request): RedirectResponse
    {
        $this->ownerOnly();

        $data = $this->staffData($request, null);
        $member = User::create($data + ['salon_id' => $this->salon()->id]);

        // Nová kadeřnice dostane všechny aktivní služby; své si pak upraví.
        $member->services()->attach($this->salon()->services()->where('is_active', true)->pluck('id'));

        return redirect()->route('admin.hours', ['kadernice' => $member->id])
            ->with('status', 'Kadeřnice je přidaná. Nastavte jí pracovní dobu.');
    }

    public function editStaff(User $member): View
    {
        $this->ownerOnly();

        return view('admin.staff', ['tab' => 'more', 'member' => $this->ownStaff($member)]);
    }

    public function updateStaff(Request $request, User $member): RedirectResponse
    {
        $this->ownerOnly();
        $member = $this->ownStaff($member);
        $data = $this->staffData($request, $member);

        // Salon nesmí zůstat bez správce, který se umí přihlásit.
        $losesAccess = $member->isOwner() && $member->canLogIn()
            && ($data['role'] !== UserRole::Owner->value || $data['login'] === null);

        if ($losesAccess && ! $this->salon()->users()->where('role', UserRole::Owner)
            ->whereNotNull('login')->whereNotNull('password')->whereKeyNot($member->id)->exists()) {
            return back()->withInput()->with('error', 'V salonu musí zůstat aspoň jeden správce s přihlášením.');
        }

        $member->update($data);

        return redirect()->route('admin.staff.index')->with('status', 'Uloženo.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $this->user()->update(['password' => $data['password']]);

        return back()->with('status', 'Heslo je změněné.');
    }

    /**
     * Přihlašovací jméno a heslo jsou nepovinné: kadeřnice bez nich je jen v rozvrhu.
     * Kdo jméno dostane, musí mít i heslo; smazáním jména o přihlášení přijde.
     */
    private function staffData(Request $request, ?User $member): array
    {
        $request->merge(['login' => $request->filled('login') ? mb_strtolower(trim((string) $request->input('login'))) : null]);

        $needsPassword = $request->filled('login') && ! $member?->password;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'login' => ['nullable', 'string', 'regex:'.SalonInstaller::LOGIN_PATTERN, Rule::unique('users', 'login')->ignore($member?->id)],
            'password' => [$needsPassword ? 'required' : 'nullable', Password::min(8)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'login.regex' => 'Přihlašovací jméno smí mít 3–60 znaků: malá písmena bez háčků, číslice, tečku, pomlčku nebo podtržítko.',
            'login.unique' => 'Toto přihlašovací jméno už někdo používá.',
            'password.required' => 'K přihlašovacímu jménu zadejte i heslo.',
        ]);

        $data['is_bookable'] = $request->boolean('is_bookable');

        if ($data['login'] === null) {
            $data['password'] = null;
        } elseif (empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }
}
