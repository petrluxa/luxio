@use('App\Support\Cz')
@extends('layouts.admin')

@section('title', 'Nastavení')

@section('content')
    <a class="back-link" href="{{ route('admin.more') }}">Více</a>
    <h1 class="screen-title">Nastavení</h1>

    <form method="post" action="{{ route('admin.settings.update') }}" novalidate>
        @csrf

        <h2 class="section-title">Salon</h2>
        <label class="field">
            <span>Název</span>
            <input class="input" type="text" name="name" value="{{ old('name', $salon->name) }}" maxlength="100" required>
            @error('name') <div class="field-error">{{ $message }}</div> @enderror
        </label>
        <label class="field">
            <span>Telefon (zákaznice ho uvidí u zrušení po lhůtě)</span>
            <input class="input" type="tel" name="phone" value="{{ old('phone', Cz::phone($salon->phone)) }}" inputmode="tel">
            @error('phone') <div class="field-error">{{ $message }}</div> @enderror
        </label>
        <label class="field">
            <span>Adresa</span>
            <input class="input" type="text" name="address" value="{{ old('address', $salon->address) }}" maxlength="255">
        </label>
        <label class="field">
            <span>Adresa veřejného odkazu: {{ url('/s') }}/…</span>
            <input class="input" type="text" name="slug" value="{{ old('slug', $salon->slug) }}" maxlength="60" autocapitalize="none" autocomplete="off" required>
            @error('slug') <div class="field-error">{{ $message }}</div> @enderror
        </label>

        <h2 class="section-title">Pravidla rezervací</h2>
        <label class="field">
            <span>Krok nabídky časů</span>
            <select class="input" name="slot_step">
                @foreach ([5, 10, 15, 20, 30, 60] as $step)
                    <option value="{{ $step }}" @selected((int) old('slot_step', $rules->slotStep) === $step)>{{ $step }} min</option>
                @endforeach
            </select>
        </label>
        <div class="grid grid-cols-2 gap-3">
            <label class="field">
                <span>Nejdřív lze rezervovat (h předem)</span>
                <input class="input" type="number" name="min_notice_hours" value="{{ old('min_notice_hours', $rules->minNoticeHours) }}" inputmode="numeric" min="0" max="168" required>
                @error('min_notice_hours') <div class="field-error">{{ $message }}</div> @enderror
            </label>
            <label class="field">
                <span>Nejdál lze rezervovat (dní)</span>
                <input class="input" type="number" name="max_days_ahead" value="{{ old('max_days_ahead', $rules->maxDaysAhead) }}" inputmode="numeric" min="1" max="365" required>
                @error('max_days_ahead') <div class="field-error">{{ $message }}</div> @enderror
            </label>
            <label class="field">
                <span>Zrušení zákaznicí nejpozději (h předem)</span>
                <input class="input" type="number" name="cancel_hours" value="{{ old('cancel_hours', $rules->cancelHours) }}" inputmode="numeric" min="0" max="168" required>
                @error('cancel_hours') <div class="field-error">{{ $message }}</div> @enderror
            </label>
            <label class="field">
                <span>Připomínka (h předem)</span>
                <input class="input" type="number" name="reminder_hours" value="{{ old('reminder_hours', $rules->reminderHours) }}" inputmode="numeric" min="1" max="168" required>
                @error('reminder_hours') <div class="field-error">{{ $message }}</div> @enderror
            </label>
        </div>
        <label class="field">
            <span>Druhá připomínka (h předem, nepovinné – např. 2)</span>
            <input class="input" type="number" name="second_reminder_hours" value="{{ old('second_reminder_hours', $rules->secondReminderHours) }}" inputmode="numeric" min="1" max="48">
            @error('second_reminder_hours') <div class="field-error">{{ $message }}</div> @enderror
        </label>
        <label class="check">
            <input type="checkbox" name="approve_new_customers" value="1" @checked(old('approve_new_customers', $rules->approveNewCustomers))>
            <span>Nové zákaznice čekají na schválení (stálé mají termín potvrzený hned)</span>
        </label>

        <button class="btn btn-primary btn-block mt-3" type="submit">Uložit nastavení</button>
    </form>

    <h2 class="section-title mt-6">Kadeřnice</h2>
    @foreach ($users as $member)
        <a class="row" href="{{ route('admin.staff.edit', $member) }}">
            <span class="h-3 w-3 flex-none rounded-full" style="background: {{ $member->color }}" aria-hidden="true"></span>
            <span class="row-main">
                <b>{{ $member->name }}</b>
                <span>{{ $member->role->label() }} · {{ $member->email }}@if (! $member->is_bookable) · nepřijímá rezervace @endif</span>
            </span>
            @include('partials.icon', ['name' => 'chevron', 'size' => 20, 'class' => 'text-muted'])
        </a>
    @endforeach
    <a class="btn btn-outline btn-block mt-3" href="{{ route('admin.staff.create') }}">Přidat kadeřnici</a>
@endsection
