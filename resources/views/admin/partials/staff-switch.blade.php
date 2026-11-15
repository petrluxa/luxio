{{-- Přepínač kadeřnic – ukáže se, jen když jich je v salonu víc. Vstup: $staffList, $staff, $route, $params --}}
@if ($staffList->count() > 1)
    <div class="chips mb-3" role="group" aria-label="Kadeřnice">
        @foreach ($staffList as $member)
            <a class="chip @if ($member->is($staff)) is-on @endif" href="{{ route($route, ['kadernice' => $member->id] + ($params ?? [])) }}"
               @if ($member->is($staff)) aria-current="true" @endif>{{ $member->name }}</a>
        @endforeach
    </div>
@endif
