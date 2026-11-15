<x-mail::message>
# Dobrý den,

@if ($reason === 'welcome')
děkujeme za rezervaci v salonu **{{ $salon->name }}**. Tady je váš osobní odkaz – uvidíte na něm své termíny a příště se objednáte na pár ťuknutí, bez hesla.
@elseif ($reason === 'existing')
v salonu **{{ $salon->name }}** jsme přijali rezervaci na váš e-mail nebo telefon. Čeká na potvrzení kadeřnicí. Své termíny najdete přes tento odkaz.
@else
posíláme nový odkaz do vašich rezervací v salonu **{{ $salon->name }}**.
@endif

<x-mail::button :url="$url">
Otevřít moje rezervace
</x-mail::button>

@if ($validLabel)
Odkaz platí {{ $validLabel }}. Po otevření si aplikaci uložte na plochu telefonu, ať ji máte příště po ruce.
@else
Odkaz si uložte – nejlépe si aplikaci přidejte na plochu telefonu. Nikomu ho neposílejte, otevírá váš účet.
@endif

@if ($reason !== 'welcome')
Pokud jste o nic nežádali, e-mail prosím ignorujte.
@endif

{{ $salon->name }}@if ($salon->phone)<br>{{ \App\Support\Cz::phone($salon->phone) }}@endif

</x-mail::message>
