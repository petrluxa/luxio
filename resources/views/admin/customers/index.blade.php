@use('App\Support\Cz')
@extends('layouts.admin')

@section('title', 'Zákaznice')

@section('content')
    <h1 class="screen-title">Zákaznice</h1>

    <form method="get" action="{{ route('admin.customers.index') }}" class="mb-3 flex gap-2" role="search">
        <input class="input" type="search" name="q" value="{{ $q }}" placeholder="Jméno, telefon nebo e-mail" aria-label="Hledat zákaznici" autocomplete="off">
        <button class="btn btn-outline" type="submit">Hledat</button>
    </form>

    @forelse ($customers as $customer)
        <a class="row" href="{{ route('admin.customers.show', $customer) }}">
            <span class="row-main">
                <b>{{ $customer->name }}</b>
                <span>{{ Cz::phone($customer->phone) ?: 'bez telefonu' }} · {{ $customer->bookings_count }}× u vás</span>
            </span>
            @if ($customer->is_blocked)
                <span class="tag tag-danger">Blokovaná</span>
            @elseif (! $customer->is_verified)
                <span class="tag">Nová</span>
            @endif
            @include('partials.icon', ['name' => 'chevron', 'size' => 20, 'class' => 'text-muted'])
        </a>
    @empty
        <div class="empty">
            {{ $q !== '' ? 'Nikoho takového jsme nenašli.' : 'Zatím tu nemáte žádnou zákaznici.' }}
        </div>
    @endforelse

    @if ($customers->hasPages())
        <div class="mt-4 flex gap-2">
            @if ($customers->previousPageUrl())
                <a class="btn btn-quiet btn-sm flex-1" href="{{ $customers->previousPageUrl() }}">‹ Předchozí</a>
            @endif
            @if ($customers->nextPageUrl())
                <a class="btn btn-quiet btn-sm flex-1" href="{{ $customers->nextPageUrl() }}">Další ›</a>
            @endif
        </div>
    @endif

    <a class="btn btn-primary btn-block mt-4" href="{{ route('admin.customers.create') }}">Přidat zákaznici</a>
@endsection
