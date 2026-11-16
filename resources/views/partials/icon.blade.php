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
        // Boxíky v Adminu
        'price' => '<path d="M3.500 12.500V5A1.500 1.500 0 0 1 5 3.500h7.500l8 8-9 9z"/><circle cx="8" cy="8" r="1.300"/>',
        'clock' => '<circle cx="12" cy="12" r="8.500"/><path d="M12 7.500V12l3 2"/>',
        'bottle' => '<path d="M10 3h4v3l2 2.500V19a2 2 0 0 1-2 2h-4a2 2 0 0 1-2-2V8.500L10 6z"/><path d="M8 13h8"/>',
        'team' => '<circle cx="9" cy="8.500" r="3"/><path d="M3 19.500c.5-3.200 2.900-5 6-5s5.500 1.800 6 5"/><circle cx="17" cy="9.500" r="2.300"/><path d="M16.500 14.600c2.400.200 4 1.700 4.500 4.400"/>',
        'salon' => '<path d="M4 11l8-7 8 7v8a1.500 1.500 0 0 1-1.500 1.500h-13A1.500 1.500 0 0 1 4 19z"/><path d="M9.500 20.500v-6h5v6"/>',
        'rules' => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.700 0l3-3a4 4 0 0 0-5.700-5.700l-1 1"/><path d="M14 10a4 4 0 0 0-5.700 0l-3 3a4 4 0 0 0 5.700 5.700l1-1"/>',
        'moon' => '<path d="M20 14.500A8 8 0 0 1 9.500 4a8 8 0 1 0 10.500 10.500z"/>',
        'bell' =>'<path d="M6 16.500V11a6 6 0 1 1 12 0v5.500l1.500 2h-15zM10 20.500a2 2 0 0 0 4 0"/>',
    ];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="{{ $size ?? 24 }}" height="{{ $size ?? 24 }}" fill="none" stroke="currentColor" stroke-width="1.700" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="{{ $class ?? '' }}">{!! $paths[$name] ?? '' !!}</svg>
