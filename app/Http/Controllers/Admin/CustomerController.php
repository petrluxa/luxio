<?php

namespace App\Http\Controllers\Admin;

use App\Models\Customer;
use App\Services\Customers\AccessLinks;
use App\Support\Cz;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Zákaznice: hledání a karta (kontakty, historie návštěv, receptura barvy,
 * poznámky, blokace, osobní odkaz).
 */
class CustomerController extends AdminController
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));

        $customers = $this->search($q)
            ->withCount('bookings')
            ->orderBy('name')
            ->paginate(40)
            ->withQueryString();

        return view('admin.customers.index', ['tab' => 'customers', 'customers' => $customers, 'q' => $q]);
    }

    /** Našeptávač zákaznic pro ruční rezervaci. */
    public function lookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json(
            $this->search($q)->orderBy('name')->limit(8)->get(['id', 'name', 'phone', 'is_blocked'])
                ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name, 'phone' => Cz::phone($c->phone), 'blocked' => $c->is_blocked])
        );
    }

    public function create(): View
    {
        return view('admin.customers.create', ['tab' => 'customers', 'staffList' => $this->staffList()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        // Zákaznice, kterou si přidala kadeřnice, je její – jinak by ji po uložení sama neviděla.
        if (! $this->user()->isOwner()) {
            $data['preferred_staff_id'] = $this->user()->id;
        }

        $customer = Customer::create($data + [
            'salon_id' => $this->salon()->id,
            'is_verified' => true, // přidala ji kadeřnice
        ]);

        return redirect()->route('admin.customers.show', $customer)->with('status', 'Zákaznice je přidaná.');
    }

    public function show(Customer $customer): View
    {
        $customer = $this->ownCustomer($customer);

        return view('admin.customers.show', [
            'tab' => 'customers',
            'customer' => $customer,
            'staffList' => $this->staffList(),
            // Kadeřnice vidí jen návštěvy u sebe, správce celou historii v salonu.
            'bookings' => $customer->bookings()
                ->when(! $this->user()->isOwner(), fn ($query) => $query->where('staff_id', $this->user()->id))
                ->with('items', 'staff', 'products')->orderByDesc('starts_at')->limit(60)->get(),
            'activeTokens' => $customer->accessTokens()->whereNull('revoked_at')->count(),
            // Čerstvě vydaný odkaz se ukáže jen jednou – nikde není uložený.
            'link' => session('link'),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validated($request);

        // Komu zákaznice patří, mění jen správce (kadeřnice to pole ve formuláři nemá).
        if (! $this->user()->isOwner()) {
            unset($data['preferred_staff_id']);
        }

        $this->ownCustomer($customer)->update($data);

        return back()->with('status', 'Uloženo.');
    }

    /** Blokace: zákaznice si nemůže rezervovat online a její odkazy přestanou platit. */
    public function block(Request $request, Customer $customer): RedirectResponse
    {
        $customer = $this->ownCustomer($customer);
        $blocked = ! $customer->is_blocked;

        $customer->update(['is_blocked' => $blocked]);

        if ($blocked) {
            $customer->accessTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        return back()->with('status', $blocked ? 'Zákaznice je zablokovaná a její odkazy už neplatí.' : 'Blokace je zrušená.');
    }

    /** „Poslat odkaz“: vydá nový osobní odkaz ke sdílení přes WhatsApp / Messenger. */
    public function issueLink(Request $request, Customer $customer, AccessLinks $links): RedirectResponse
    {
        $customer = $this->ownCustomer($customer);

        if ($customer->is_blocked) {
            return back()->with('error', 'Zablokované zákaznici nelze vydat odkaz.');
        }

        if ($request->boolean('replace')) {
            $customer->accessTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        $scope = $request->boolean('only_me') ? $this->user()->id : null;

        return back()->with('link', $links->issue($customer, $scope));
    }

    public function revokeLinks(Customer $customer): RedirectResponse
    {
        $this->ownCustomer($customer)->accessTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return back()->with('status', 'Všechny odkazy zákaznice jsou zrušené.');
    }

    private function search(string $q): Builder
    {
        $phone = preg_replace('/\D/', '', $q);

        return Customer::query()
            ->visibleTo($this->user())
            ->when($q !== '', fn (Builder $query) => $query->where(function (Builder $w) use ($q, $phone) {
                $w->where('name', 'like', "%{$q}%");

                if (strlen($phone) >= 3) {
                    $w->orWhere('phone', 'like', "%{$phone}%");
                }
            }));
    }

    private function validated(Request $request): array
    {
        $request->merge([
            'phone' => $request->filled('phone') ? Cz::normalizePhone($request->input('phone')) ?? 'x' : null,
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['nullable', 'string', 'regex:/^\+\d{9,15}$/'],
            'preferred_staff_id' => ['nullable', 'integer', Rule::in($this->staffList()->modelKeys())],
            'note' => ['nullable', 'string', 'max:5000'],
            'color_recipe' => ['nullable', 'string', 'max:5000'],
        ], ['phone.regex' => 'Zadejte prosím platné telefonní číslo.']);
    }
}
