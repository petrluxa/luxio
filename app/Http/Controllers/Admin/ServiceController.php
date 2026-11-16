<?php

namespace App\Http\Controllers\Admin;

use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ceník. Každá kadeřnice má svůj: zaškrtne, které služby dělá, a nastaví si vlastní cenu
 * a délky (práce – pauza – práce). Správce vidí a upraví ceník kterékoliv kadeřnice.
 *
 * Služba sama (název, popis, výchozí cena a délky, úklid, pořadí) je společná pro salon.
 * Novou službu může přidat i kadeřnice – dostane ji do svého ceníku; výchozí hodnoty služby,
 * kterou dělá jen ona, si upraví sama, ostatní společné služby upravuje správce.
 */
class ServiceController extends AdminController
{
    public function index(Request $request): View
    {
        $staff = $this->editableStaff($request);

        return view('admin.services.index', [
            'tab' => 'more',
            'width' => 'wide',
            'staff' => $staff,
            'staffList' => $this->user()->isOwner() ? $this->staffList() : collect([$staff]),
            'services' => $this->salon()->services()->withCount('staff')->get(),
            'mine' => $staff->services()->get()->keyBy('id'),
            'isOwner' => $this->user()->isOwner(),
            'editable' => fn (Service $service) => $this->canEditBase($service),
        ]);
    }

    /** Uloží celý ceník jedné kadeřnice najednou: co dělá, za kolik a jak dlouho. */
    public function prices(Request $request): RedirectResponse
    {
        $staff = $this->editableStaff($request);

        $data = $request->validate([
            'rows' => ['nullable', 'array'],
            'rows.*.price' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'rows.*.work_1' => ['nullable', 'integer', 'min:5', 'max:600'],
            'rows.*.pause' => ['nullable', 'integer', 'min:0', 'max:600'],
            'rows.*.work_2' => ['nullable', 'integer', 'min:0', 'max:600'],
        ], [], ['rows.*.price' => 'cena', 'rows.*.work_1' => 'délka práce', 'rows.*.pause' => 'pauza', 'rows.*.work_2' => 'délka druhé práce']);

        $sync = [];

        foreach ($this->salon()->services()->get() as $service) {
            $row = $request->input("rows.{$service->id}");

            if (! is_array($row) || empty($row['offers'])) {
                continue;
            }

            // Co se shoduje s výchozí hodnotou služby, se neukládá – změna výchozí hodnoty se pak projeví i tady.
            $own = function (string $key, int $base) use ($data, $service): ?int {
                $value = $data['rows'][$service->id][$key] ?? null;

                return $value === null || (int) $value === $base ? null : (int) $value;
            };

            $sync[$service->id] = [
                'price_from' => $own('price', $service->price_from),
                'work_1' => $own('work_1', $service->work_1),
                'pause' => $own('pause', $service->pause),
                'work_2' => $own('work_2', $service->work_2),
            ];
        }

        $staff->services()->sync($sync);

        return back()->with('status', 'Ceník – '.$staff->name.' – je uložený.');
    }

    public function create(Request $request): View
    {
        return view('admin.services.form', [
            'tab' => 'more',
            'service' => new Service(['is_active' => true, 'work_1' => 30]),
            'staffList' => $this->user()->isOwner() ? $this->staffList() : collect(),
            // Kdo novou službu dostane do ceníku: kadeřnice, u které správce právě je.
            'checked' => [$this->editableStaff($request)->id],
            'for' => $this->editableStaff($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $me = $this->user();

        $service = Service::create($this->validated($request) + [
            'salon_id' => $this->salon()->id,
            'sort' => (int) $this->salon()->services()->max('sort') + 1,
        ]);

        // Kadeřnice přidává službu sobě; správce vybírá, které kadeřnice ji dělají.
        $staffIds = $me->isOwner()
            ? $this->staffList()->modelKeys()
            : [$me->id];

        if ($me->isOwner()) {
            $picked = $request->validate(['staff' => ['nullable', 'array'], 'staff.*' => ['integer', Rule::in($staffIds)]])['staff'] ?? [];
            $staffIds = array_map('intval', $picked);
        }

        $service->staff()->attach($staffIds);

        return redirect()->route('admin.services.index', $this->staffQuery($request))->with('status', 'Služba je přidaná.');
    }

    public function edit(Request $request, Service $service): View|RedirectResponse
    {
        $service = $this->ownService($service);

        if (! $this->canEditBase($service)) {
            return redirect()->route('admin.services.index')
                ->with('error', 'Společnou službu upravuje správce salonu. Svoji cenu a délku si nastavíte přímo v ceníku.');
        }

        return view('admin.services.form', [
            'tab' => 'more',
            'service' => $service,
            'staffList' => collect(),
            'checked' => [],
            'for' => $this->editableStaff($request),
        ]);
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $service = $this->ownService($service);

        abort_unless($this->canEditBase($service), 403);

        $service->update($this->validated($request));

        return redirect()->route('admin.services.index', $this->staffQuery($request))->with('status', 'Služba je uložená.');
    }

    /** Posun v ceníku o jedno místo nahoru / dolů. */
    public function reorder(Request $request, Service $service): RedirectResponse
    {
        $this->ownerOnly();
        $service = $this->ownService($service);

        $ordered = $this->salon()->services()->get()->values();
        $index = $ordered->search(fn (Service $s) => $s->is($service));
        $target = $index + ($request->input('direction') === 'up' ? -1 : 1);

        if ($index !== false && $ordered->has($target)) {
            $ids = $ordered->modelKeys();
            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

            foreach ($ids as $position => $id) {
                Service::whereKey($id)->update(['sort' => $position + 1]);
            }
        }

        return back();
    }

    /**
     * Výchozí hodnoty služby smí měnit správce, nebo kadeřnice u služby, kterou dělá jen ona
     * (typicky si ji sama přidala).
     */
    private function canEditBase(Service $service): bool
    {
        $me = $this->user();

        if ($me->isOwner()) {
            return true;
        }

        $providers = $service->staff()->pluck('users.id');

        return $providers->count() === 1 && $providers->first() === $me->id;
    }

    /** Správce se po uložení vrací do ceníku kadeřnice, u které byl. */
    private function staffQuery(Request $request): array
    {
        $staff = $this->editableStaff($request);

        return $staff->is($this->user()) ? [] : ['kadernice' => $staff->id];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_from' => ['required', 'integer', 'min:0', 'max:100000'],
            'price_to' => ['nullable', 'integer', 'gt:price_from', 'max:100000'],
            'work_1' => ['required', 'integer', 'min:5', 'max:600'],
            'pause' => ['nullable', 'integer', 'min:0', 'max:600'],
            'work_2' => ['nullable', 'integer', 'min:0', 'max:600'],
            'buffer_after' => ['nullable', 'integer', 'min:0', 'max:120'],
        ], ['price_to.gt' => 'Cena „do“ musí být vyšší než cena „od“.']);

        return [
            'pause' => (int) ($data['pause'] ?? 0),
            'work_2' => (int) ($data['work_2'] ?? 0),
            'buffer_after' => (int) ($data['buffer_after'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ] + $data;
    }
}
