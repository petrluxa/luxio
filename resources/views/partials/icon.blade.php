@php
    // Jednoduché obrysové ikony (24px) – jen cesty, barva se dědí z textu.
    $paths = [
        'today' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'pending' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'customers' => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c.6-3.6 3.4-5.5 7-5.5s6.400 1.900 7 5.500"/>',
        'more' => '<circle cx="5.5" cy="12" r="1.300"/><circle cx="12" cy="12" r="1.300"/><circle cx="18.500" cy="12" r="1.300"/>',
        'phone' => '<path d="M6.500 4h3l1.500 4-2 1.500a11 11 0 0 0 5.500 5.500l1.500-2 4 1.500v3a2 2 0 0 1-2 2A15 15 0 0 1 4.500 6a2 2 0 0 1 2-2z"/>',
        'chevron' => '<path d="M9 5.500l6.500 6.500L9 18.500"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'share' => '<path d="M12 15V4m0 0L8 8m4-4l4 4M5 13v5a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-5"/>',
        'bell' => '<path d="M6 16.500V11a6 6 0 1 1 12 0v5.500l1.500 2h-15zM10 20.500a2 2 0 0 0 4 0"/>',
    ];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="{{ $size ?? 24 }}" height="{{ $size ?? 24 }}" fill="none" stroke="currentColor" stroke-width="1.700" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="{{ $class ?? '' }}">{!! $paths[$name] ?? '' !!}</svg>
