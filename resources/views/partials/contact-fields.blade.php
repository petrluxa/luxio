{{-- Kontakt nové zákaznice (veřejný odkaz salonu). Vše povinné – SPEC 3.1. --}}
<section>
    <h2 class="section-title">Vaše údaje</h2>

    <label class="field">
        <span>Jméno a příjmení</span>
        <input class="input" type="text" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="100" required>
        @error('name') <div class="field-error">{{ $message }}</div> @enderror
    </label>

    <label class="field">
        <span>Telefon</span>
        <input class="input" type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel" inputmode="tel" placeholder="603 123 456" required>
        @error('phone') <div class="field-error">{{ $message }}</div> @enderror
    </label>

    <label class="field">
        <span>E-mail</span>
        <input class="input" type="email" name="email" value="{{ old('email') }}" autocomplete="email" inputmode="email" maxlength="190" required>
        @error('email') <div class="field-error">{{ $message }}</div> @enderror
    </label>

    {{-- Past na roboty: lidé toto pole nevidí. --}}
    <div class="hidden" aria-hidden="true">
        <label>Web <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    <label class="check">
        <input type="checkbox" name="consent" value="1" @checked(old('consent')) required>
        <span>Souhlasím se zpracováním jména, telefonu a e-mailu pro účely rezervace a připomenutí termínu.</span>
    </label>
    @error('consent') <div class="field-error">{{ $message }}</div> @enderror
</section>
