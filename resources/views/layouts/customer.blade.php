@extends('layouts.base')

@section('body')
    <main class="mx-auto w-full max-w-md px-[18px] pb-6 pt-[max(20px,env(safe-area-inset-top))]">
        @include('partials.flash')
        @yield('content')
    </main>
@endsection
