<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Services\Products\ProductCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Přípravky salonu: přehled podle značky a druhu (barvy, šampony, péče, styling, ampule),
 * hledání pro vyúčtování návštěvy, vlastní položky a doplnění z výchozího katalogu.
 */
class ProductController extends AdminController
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $brand = trim((string) $request->query('znacka'));
        $category = ProductCategory::tryFrom((string) $request->query('druh'));
        $hidden = $request->boolean('skryte');

        $products = $this->search($q)
            ->where('is_active', ! $hidden)
            ->when($brand !== '', fn (Builder $query) => $query->where('brand', $brand))
            ->when($category, fn (Builder $query) => $query->where('category', $category))
            ->orderBy('brand')->orderBy('line')->orderBy('name')
            ->paginate(60)
            ->withQueryString();

        return view('admin.products.index', [
            'tab' => 'more',
            'products' => $products,
            'q' => $q,
            'brand' => $brand,
            'category' => $category,
            'hidden' => $hidden,
            'brands' => $this->salon()->products()->distinct()->orderBy('brand')->pluck('brand'),
            'hiddenCount' => $this->salon()->products()->where('is_active', false)->count(),
            'total' => $this->salon()->products()->count(),
        ]);
    }

    /** Našeptávač přípravků pro vyúčtování návštěvy. */
    public function lookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json(
            $this->search($q)->where('is_active', true)
                ->orderBy('brand')->orderBy('line')->orderBy('name')
                ->limit(12)->get()
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->fullName(), 'meta' => $p->meta()])
        );
    }

    public function create(): View
    {
        return view('admin.products.form', [
            'tab' => 'more',
            'product' => new Product(['is_active' => true, 'category' => ProductCategory::Care]),
            'brands' => $this->salon()->products()->distinct()->orderBy('brand')->pluck('brand'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Product::create($this->validated($request) + ['salon_id' => $this->salon()->id, 'is_active' => true]);

        return redirect()->route('admin.products.index')->with('status', 'Přípravek je přidaný.');
    }

    public function edit(Product $product): View
    {
        return view('admin.products.form', [
            'tab' => 'more',
            'product' => $this->ownProduct($product),
            'brands' => $this->salon()->products()->distinct()->orderBy('brand')->pluck('brand'),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->ownProduct($product)->update($this->validated($request) + ['is_active' => ! $request->boolean('hidden')]);

        return redirect()->route('admin.products.index')->with('status', 'Uloženo.');
    }

    /** Doplní výchozí katalog Kérastase a L'Oréal Professionnel (co salon už má, se nepřidá znovu). */
    public function import(ProductCatalog $catalog): RedirectResponse
    {
        $this->ownerOnly();

        $added = $catalog->import($this->salon());

        return back()->with('status', $added > 0
            ? 'Z katalogu přibylo přípravků: '.$added.'.'
            : 'Všechny přípravky z katalogu už v přehledu máte.');
    }

    /** Každé slovo dotazu musí být v názvu, řadě, značce nebo druhu („majirel“, „kerastase bain“). */
    private function search(string $q): Builder
    {
        $query = Product::query()->where('salon_id', $this->salon()->id);

        foreach (array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';

            $query->where(fn (Builder $w) => $w
                ->where('name', 'like', $like)
                ->orWhere('line', 'like', $like)
                ->orWhere('brand', 'like', $like)
                ->orWhere('kind', 'like', $like));
        }

        return $query;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'brand' => ['required', 'string', 'max:60'],
            'line' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['nullable', 'string', 'max:60'],
            'category' => ['required', Rule::enum(ProductCategory::class)],
            'size' => ['nullable', 'string', 'max:60'],
        ], [], ['brand' => 'značka', 'name' => 'název', 'category' => 'druh']);
    }
}
