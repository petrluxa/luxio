<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Cz;
use App\Support\SalonSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Nastavení (jen majitelka): údaje salonu, pravidla rezervací a kadeřnice.
 * Každá přihlášená si navíc může změnit vlastní heslo na obrazovce „Více“.
 */
class SettingsController extends AdminController
{
    public function more(): View
    {
        return view('admin.more', ['tab' => 'more', 'salon' => $this->salon()]);
    }

    public function edit(): View
    {
        $this->ownerOnly();

        return view('admin.settings', [
            'tab' => 'more',
            'salon' => $this->salon(),
            'rules' => $this->salon()->rules(),
            'users' => $this->salon()->users()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->ownerOnly();
        $salon = $this->salon();

        $request->merge(['phone' => $request->filled('phone') ? Cz::normalizePhone($request->input('phone')) ?? 'x' : null]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('salons', 'slug')->ignore($salon->id)],
            'phone' => ['nullable', 'string', 'regex:/^\+\d{9,15}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'slot_step' => ['required', 'integer', Rule::in([5, 10, 15, 20, 30, 60])],
            'min_notice_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'max_days_ahead' => ['required', 'integer', 'min:1', 'max:365'],
            'cancel_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'reminder_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'second_reminder_hours' => ['nullable', 'integer', 'min:1', 'max:48', 'lt:reminder_hours'],
        ], [
            'slug.regex' => 'Adresa odkazu smí obsahovat jen malá písmena bez diakritiky, číslice a pomlčky.',
            'phone.regex' => 'Zadejte prosím platné telefonní číslo.',
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
            'settings' => ['approve_new_customers' => $request->boolean('approve_new_customers')] + $rules->toArray(),
        ]);

        return back()->with('status', 'Nastavení je uložené.');
    }

    public function createStaff(): View
    {
        $this->ownerOnly();

        return view('admin.staff', ['tab' => 'more', 'member' => new User(['is_bookable' => true, 'color' => '#74624F', 'role' => UserRole::Staff])]);
    }

    public function storeStaff(Request $request): RedirectResponse
    {
        $this->ownerOnly();

        $data = $this->staffData($request, null);
        $member = User::create($data + ['salon_id' => $this->salon()->id]);

        // Nová kadeřnice dostane všechny aktivní služby; své si pak upraví.
        $member->services()->attach($this->salon()->services()->where('is_active', true)->pluck('id'));

        return redirect()->route('admin.settings')->with('status', 'Kadeřnice je přidaná. Nastavte jí pracovní dobu.');
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

        // Salon nesmí zůstat bez majitelky.
        if ($member->isOwner() && $data['role'] !== UserRole::Owner->value
            && $this->salon()->users()->where('role', UserRole::Owner)->count() <= 1) {
            return back()->withInput()->with('error', 'V salonu musí zůstat aspoň jedna majitelka.');
        }

        $member->update($data);

        return redirect()->route('admin.settings')->with('status', 'Uloženo.');
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

    private function staffData(Request $request, ?User $member): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')->ignore($member?->id)],
            'password' => [$member ? 'nullable' : 'required', Password::min(8)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $data['email'] = mb_strtolower($data['email']);
        $data['is_bookable'] = $request->boolean('is_bookable');

        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }
}
