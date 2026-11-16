@use('App\Enums\ProductCategory')
@extends('layouts.admin')

@php($isNew = ! $product->exists)

@section('title', $isNew ? 'Nový přípravek' : $product->name)

@section('content')
    <a class="back-link" href="{{ route('admin.products.index') }}">Přípravky</a>
    <h1 class="screen-title">{{ $isNew ? 'Nový přípravek' : $product->name }}</h1>

    <form method="post" action="{{ $isNew ? route('admin.products.store') : route('admin.products.update', $product) }}" novalidate autocomplete="off">
        @csrf

        <label class="field">
            <span>Značka</span>
            <input class="input" type="text" name="brand" value="{{ old('brand', $product->brand) }}" maxlength="60" list="znacky" required>
            <datalist id="znacky">
                @foreach ($brands as $name)<option value="{{ $name }}">@endforeach
            </datalist>
            @error('brand') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <label class="field">
            <span>Název</span>
            <input class="input" type="text" name="name" value="{{ old('name', $product->name) }}" maxlength="160" required>
            @error('name') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <label class="field">
            <span>Řada (nepovinné)</span>
            <input class="input" type="text" name="line" value="{{ old('line', $product->line) }}" maxlength="80" placeholder="Např. Nutritive, Serie Expert, Tecni.Art">
        </label>

        <div class="grid grid-cols-2 gap-3">
            <label class="field">
                <span>Druh</span>
                <select class="input" name="category">
                    @foreach (ProductCategory::cases() as $case)
                        <option value="{{ $case->value }}" @selected(old('category', $product->category?->value) === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </select>
                @error('category') <div class="field-error">{{ $message }}</div> @enderror
            </label>
            <label class="field">
                <span>Balení (nepovinné)</span>
                <input class="input" type="text" name="size" value="{{ old('size', $product->size) }}" maxlength="60" placeholder="250 ml">
            </label>
        </div>

        <label class="field">
            <span>Upřesnění (nepovinné)</span>
            <input class="input" type="text" name="kind" value="{{ old('kind', $product->kind) }}" maxlength="60" placeholder="šampon, maska, oxidant…">
        </label>

        @unless ($isNew)
            <label class="check">
                <input type="checkbox" name="hidden" value="1" @checked(old('hidden', ! $product->is_active))>
                <span>Skrýt – nepoužíváme (při vyúčtování se nenabízí)</span>
            </label>
        @endunless

        <button class="btn btn-primary btn-block mt-3" type="submit">{{ $isNew ? 'Přidat přípravek' : 'Uložit' }}</button>
    </form>
@endsection
