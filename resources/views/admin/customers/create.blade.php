@extends('layouts.admin')

@section('title', 'Nová zákaznice')

@section('content')
    <a class="back-link" href="{{ route('admin.customers.index') }}">Zákaznice</a>
    <h1 class="screen-title">Nová zákaznice</h1>

    <form method="post" action="{{ route('admin.customers.store') }}" novalidate>
        @csrf
        @include('admin.customers.fields', ['customer' => null])
        <button class="btn btn-primary btn-block mt-2" type="submit">Přidat</button>
    </form>
@endsection
