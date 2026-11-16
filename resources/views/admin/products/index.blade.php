@use('App\Enums\ProductCategory')
@extends('layouts.admin')

@section('title', 'Přípravky')

@php
    $link = fn (array $change) => route('admin.products.index', array_filter($change + ['q' => $q, 'znacka' => $brand, 'druh' => $category?->value, 'skryte' => $hidden ? 1 : null]));
@endphp

@section('content')
    <a class="back-link" href="{{ route('admin.more') }}">Admin</a>
    <h1 class="screen-title">Přípravky</h1>

    <form method="get" action="{{ route('admin.products.index') }}" class="mb-3" role="search">
        @if ($brand)<input type="hidden" name="znacka" value="{{ $brand }}">@endif
        @if ($category)<input type="hidden" name="druh" value="{{ $category->value }}">@endif
        @if ($hidden)<input type="hidden" name="skryte" value="1">@endif
        <label class="field mb-0">
            <span class="sr-only">Hledat přípravek</span>
            <input class="input" type="search" name="q" value="{{ $q }}" placeholder="Hledat – název, řada, značka" autocomplete="off">
        </label>
    </form>

    @if ($brands->count() > 1)
        <div class="chips mb-2" role="group" aria-label="Značka">
            <a class="chip @if ($brand === '') is-on @endif" href="{{ $link(['znacka' => null]) }}">Všechny značky</a>
            @foreach ($brands as $name)
                <a class="chip @if ($brand === $name) is-on @endif" href="{{ $link(['znacka' => $name]) }}">{{ $name }}</a>
            @endforeach
        </div>
    @endif

    <div class="chips mb-3" role="group" aria-label="Druh">
        <a class="chip @if (! $category) is-on @endif" href="{{ $link(['druh' => null]) }}">Vše</a>
        @foreach (ProductCategory::cases() as $case)
            <a class="chip @if ($category === $case) is-on @endif" href="{{ $link(['druh' => $case->value]) }}">{{ $case->label() }}</a>
        @endforeach
    </div>

    <div class="mb-2 flex items-baseline justify-between gap-3">
        <span class="label">{{ $hidden ? 'Skryté přípravky' : 'Přípravků' }}: {{ $products->total() }}</span>
        @if ($hidden)
            <a class="label underline" href="{{ $link(['skryte' => null]) }}">Zpět na používané</a>
        @elseif ($hiddenCount > 0)
            <a class="label underline" href="{{ $link(['skryte' => 1]) }}">Skryté ({{ $hiddenCount }})</a>
        @endif
    </div>

    @forelse ($products as $product)
        <a class="row" href="{{ route('admin.products.edit', $product) }}">
            <span class="row-main">
                <b>{{ $product->name }}</b>
                <span>{{ collect([$product->brand, $product->meta()])->filter()->join(' · ') }}</span>
            </span>
            <span class="tag tag-muted">{{ $product->category->label() }}</span>
        </a>
    @empty
        <div class="empty">
            @if ($total === 0)
                Zatím tu není žádný přípravek.
            @else
                Tomuto výběru neodpovídá žádný přípravek.
            @endif
        </div>
    @endforelse

    <div class="mt-4">{{ $products->links() }}</div>

    <a class="btn btn-primary btn-block mt-4" href="{{ route('admin.products.create') }}">Přidat přípravek</a>

    @if (auth()->user()->isOwner())
        <form method="post" action="{{ route('admin.products.import') }}" class="mt-2">
            @csrf
            <button class="btn btn-outline btn-block" type="submit">Doplnit katalog Kérastase a L'Oréal Professionnel</button>
        </form>
    @endif

    <p class="label mt-3">
        Co nepoužíváte, v detailu přípravku skryjte – přestane se nabízet při vyúčtování, v historii návštěv zůstane.
        U barev se odstín a množství zapisuje až u návštěvy.
    </p>
@endsection
