@use('App\Support\Cz')
{{-- Společná pole karty zákaznice. Vstup: $customer (může být null), $staffList --}}
<label class="field">
    <span>Jméno a příjmení</span>
    <input class="input" type="text" name="name" value="{{ old('name', $customer?->name) }}" maxlength="100" autocomplete="off" required>
    @error('name') <div class="field-error">{{ $message }}</div> @enderror
</label>

<label class="field">
    <span>Telefon</span>
    <input class="input" type="tel" name="phone" value="{{ old('phone', Cz::phone($customer?->phone)) }}" inputmode="tel" autocomplete="off" placeholder="603 123 456">
    @error('phone') <div class="field-error">{{ $message }}</div> @enderror
</label>

@if ($staffList->count() > 1)
    <label class="field">
        <span>Chodí ke kadeřnici</span>
        <select class="input" name="preferred_staff_id">
            <option value="">Nezáleží</option>
            @foreach ($staffList as $member)
                <option value="{{ $member->id }}" @selected((int) old('preferred_staff_id', $customer?->preferred_staff_id) === $member->id)>{{ $member->name }}</option>
            @endforeach
        </select>
    </label>
@endif

<label class="field">
    <span>Receptura barvy</span>
    <textarea class="input" name="color_recipe" maxlength="5000" rows="3" placeholder="Např. Koleston 7/1 + 6 % oxidant, 40 min">{{ old('color_recipe', $customer?->color_recipe) }}</textarea>
</label>

<label class="field">
    <span>Poznámky</span>
    <textarea class="input" name="note" maxlength="5000" rows="3">{{ old('note', $customer?->note) }}</textarea>
</label>
