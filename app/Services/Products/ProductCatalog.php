<?php

namespace App\Services\Products;

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Salon;

/**
 * Výchozí katalog přípravků (Kérastase, L'Oréal Professionnel) ze souboru
 * `database/data/pripravky.json`. Nahrává se při založení salonu a dá se kdykoliv
 * doplnit znovu – co už salon má (i skryté), se nepřidá podruhé.
 */
final class ProductCatalog
{
    public function path(): string
    {
        return database_path('data/pripravky.json');
    }

    /**
     * @return list<array{brand: string, line: ?string, name: string, kind: ?string, category: string, size: ?string}>
     */
    public function items(): array
    {
        if (! is_file($this->path())) {
            return [];
        }

        $rows = json_decode((string) file_get_contents($this->path()), true);

        if (! is_array($rows)) {
            return [];
        }

        $items = [];

        foreach ($rows as $row) {
            $brand = $this->text($row['znacka'] ?? null, 60);
            $name = $this->text($row['nazev'] ?? null, 160);
            $category = ProductCategory::tryFrom((string) ($row['kategorie'] ?? ''));

            if ($brand === null || $name === null || $category === null) {
                continue;
            }

            $items[] = [
                'brand' => $brand,
                'line' => $this->text($row['rada'] ?? null, 80),
                'name' => $name,
                'kind' => $this->text($row['druh'] ?? null, 60),
                'category' => $category->value,
                'size' => $this->text($row['baleni'] ?? null, 60),
            ];
        }

        return $items;
    }

    /** Doplní do salonu přípravky z katalogu, které ještě nemá. Vrací počet přidaných. */
    public function import(Salon $salon): int
    {
        $key = fn (string $brand, string $name) => mb_strtolower($brand.'|'.$name);

        $known = $salon->products()->get(['brand', 'name'])
            ->mapWithKeys(fn (Product $p) => [$key($p->brand, $p->name) => true])
            ->all();

        $rows = [];
        $now = now();

        foreach ($this->items() as $item) {
            $id = $key($item['brand'], $item['name']);

            if (isset($known[$id])) {
                continue;
            }

            $known[$id] = true;
            $rows[] = $item + ['salon_id' => $salon->id, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            Product::insert($chunk);
        }

        return count($rows);
    }

    /** Čistý jednořádkový text omezené délky, prázdný → null. */
    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
