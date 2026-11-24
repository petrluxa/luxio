@extends('layouts.base')

@section('body')
    {{-- $wide: stránka s rozvrhem celého salonu sahá přes šířku okna; ostatní jsou úzký sloupec. --}}
    <main @class(['mx-auto w-full px-[18px] pb-6 pt-[max(20px,env(safe-area-inset-top))]', 'max-w-[1480px]' => $wide ?? false, 'max-w-md' => ! ($wide ?? false)])>
        @include('partials.flash')
        @yield('content')
    </main>
@endsection
