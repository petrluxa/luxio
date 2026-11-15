<?php

namespace App\Http\Controllers\Admin;

use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Služby: ceník, délky fází (práce – pauza – práce), úklid a pořadí.
 * Ceník upravuje majitelka; každá kadeřnice si u služby nastaví, jestli ji dělá,
 * a případně vlastní cenu a délky.
 */
class ServiceController extends AdminController
{
    public function index(): View
    {
        $mine = $this->user()->services()->pluck('services.id')->all();

        return view('admin.services.index', [
            'tab' => 'more',
            'services' => $this->salon()->services()->get(),
            'mine' => $mine,
            'canEdit' => $this->user()->isOwner(),
        ]);
    }

    public function create(): View
    {
        $this->ownerOnly();

        return view('admin.services.form', ['tab' => 'more', 'service' => new Service(['is_active' => true, 'work_1' => 30]), 'pivot' => null, 'offers' => true]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ownerOnly();

        $service = Service::create($this->validated($request) + [
            'salon_id' => $this->salon()->id,
            'sort' => (int) $this->salon()->services()->max('sort') + 1,
        ]);

        // Novou službu rovnou dostanou všechny kadeřnice salonu.
        $service->staff()->attach($this->staffList()->modelKeys());

        return redirect()->route('admin.services.index')->with('status', 'Služba je přidaná.');
    }

    public function edit(Service $service): View
    {
        $service = $this->ownService($service);
        $pivot = $this->user()->services()->whereKey($service->id)->first()?->pivot;

        return view('admin.services.form', [
            'tab' => 'more',
            'service' => $service,
            'pivot' => $pivot,
            'offers' => $pivot !== null,
        ]);
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $service = $this->ownService($service);

        if ($this->user()->isOwner()) {
            $service->update($this->validated($request));
        }

        $this->syncMine($request, $service);

        return redirect()->route('admin.services.index')->with('status', 'Služba je uložená.');
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

    /** „Tuto službu dělám“ + vlastní cena a délky přihlášené kadeřnice. */
    private function syncMine(Request $request, Service $service): void
    {
        $me = $this->user();

        if (! $request->boolean('offers')) {
            $me->services()->detach($service->id);

            return;
        }

        $own = $request->validate([
            'own_price' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'own_work_1' => ['nullable', 'integer', 'min:5', 'max:600'],
            'own_pause' => ['nullable', 'integer', 'min:0', 'max:600'],
            'own_work_2' => ['nullable', 'integer', 'min:0', 'max:600'],
        ]);

        $me->services()->syncWithoutDetaching([$service->id => [
            'price_from' => $own['own_price'] ?? null,
            'work_1' => $own['own_work_1'] ?? null,
            'pause' => $own['own_pause'] ?? null,
            'work_2' => $own['own_work_2'] ?? null,
        ]]);
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
