@use('App\Support\Cz')
@use('App\Enums\BookingStatus')
@use('App\Enums\SegmentType')
{{-- Řádek rezervace v seznamu. Vstup: $booking (s customer, items), volitelně $withDate, $withStaff --}}
@php($pause = $booking->relationLoaded('segments') ? $booking->segments->firstWhere('type', SegmentType::Pause) : null)
<a class="row @if ($booking->status->isCancelled() || ($dim ?? false)) opacity-60 @endif" href="{{ route('admin.bookings.show', $booking) }}">
    <span class="w-[52px] flex-none text-center leading-tight">
        <b class="block text-[16px] font-semibold">{{ Cz::time($booking->starts_at) }}</b>
        <span class="label">{{ Cz::time($booking->ends_at) }}</span>
    </span>
    <span class="row-main">
        <b>{{ $booking->customer->name }}</b>
        <span>
            @if ($withDate ?? false){{ Cz::date($booking->starts_at) }} · @endif
            {{ $booking->servicesLabel() }}
            @if ($withStaff ?? false) · {{ $booking->staff->name }}@endif
        </span>
        @if ($pause)
            <span>působí barva {{ Cz::time($pause->starts_at) }}–{{ Cz::time($pause->ends_at) }}</span>
        @endif
    </span>
    @if ($booking->status === BookingStatus::Pending)
        <span class="tag">Čeká</span>
    @elseif ($booking->status === BookingStatus::NoShow)
        <span class="tag tag-danger">Nepřišla</span>
    @elseif ($booking->status->isCancelled())
        <span class="tag tag-muted">Zrušeno</span>
    @endif
</a>
