@use('App\Support\Cz')
@extends('layouts.base')

@section('title', 'Doklad č. '.$booking->receipt_number)

@section('body')
    <main class="mx-auto w-full max-w-md px-[18px] pb-10 pt-[max(24px,env(safe-area-inset-top))]">
        <article class="card receipt">
            <p class="label">Doklad o zaplacení</p>
            <h1 class="mt-1 text-[22px] font-semibold leading-tight">{{ $salon->name }}</h1>

            <p class="mt-2 text-[14px] text-muted">
                @if ($salon->company){{ $salon->company }}<br>@endif
                @if ($salon->address){{ $salon->address }}<br>@endif
                @if ($salon->ico)IČO {{ $salon->ico }}<br>@endif
                @if ($salon->phone)tel. {{ Cz::phone($salon->phone) }}@endif
            </p>

            <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 border-t border-line pt-4">
                <dt class="text-muted">Číslo dokladu</dt><dd class="text-right font-semibold">{{ $booking->receipt_number }}</dd>
                <dt class="text-muted">Datum</dt><dd class="text-right">{{ Cz::dateFull($booking->starts_at) }}</dd>
                <dt class="text-muted">Zákaznice</dt><dd class="text-right">{{ $booking->customer->name }}</dd>
                <dt class="text-muted">Kadeřnice</dt><dd class="text-right">{{ $booking->staff->name }}</dd>
            </dl>

            <ul class="mt-4 border-t border-line pt-4">
                @foreach ($booking->items as $item)
                    <li class="@if (! $loop->first) mt-1 @endif">{{ $item->name }}</li>
                @endforeach
            </ul>

            <div class="mt-4 flex items-baseline justify-between gap-3 border-t border-line pt-4">
                <span class="text-[16px] font-semibold">Zaplaceno {{ $booking->payment_method->label() }}</span>
                <b class="whitespace-nowrap text-[26px] font-semibold">{{ Cz::money($booking->paid_amount) }}</b>
            </div>
            <p class="label mt-1">Uhrazeno {{ Cz::dateFull($booking->paid_at) }}.</p>

            <p class="mt-5 text-center">Děkujeme za návštěvu.</p>
        </article>

        <button type="button" class="btn btn-outline btn-block mt-4 print:hidden" onclick="window.print()">Vytisknout nebo uložit jako PDF</button>
    </main>
@endsection
