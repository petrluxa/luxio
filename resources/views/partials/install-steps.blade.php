{{--
    Onboarding: 1) přidat aplikaci na plochu, 2) povolit upozornění.
    Vstup: $pushConfig = ['vapidKey' => …, 'subscribeUrl' => …]
--}}
<div x-data="pushSetup(@js($pushConfig))">
    {{-- 1. Přidat na plochu --}}
    <section class="card">
        <div class="flex items-center gap-3">
            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-selected font-semibold text-banner-text">1</span>
            <b class="text-[16px] font-semibold">Přidat na plochu</b>
            <span class="tag ml-auto" x-show="standalone" x-cloak>Hotovo</span>
        </div>

        <p class="mt-3 text-muted" x-show="standalone" x-cloak>Aplikaci už máte na ploše telefonu.</p>

        <div x-show="!standalone">
            {{-- Otevřeno uvnitř Instagramu / Facebooku --}}
            <p class="banner mt-3 font-normal" x-show="inAppBrowser" x-cloak>
                Stránku máte otevřenou uvnitř jiné aplikace. Ťukněte na <b>⋯</b> a zvolte
                <b x-text="platform === 'ios' ? 'Otevřít v Safari' : 'Otevřít v Chromu'"></b> – teprve odtud jde přidat na plochu.
            </p>

            {{-- iPhone / iPad --}}
            <ol class="mt-3 grid gap-3" x-show="platform === 'ios'" x-cloak>
                <li class="flex items-center gap-3">
                    <svg class="h-10 w-10 flex-none text-primary" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="1" y="1" width="38" height="38" rx="10" stroke-opacity=".35"/><path d="M20 24V9m0 0l-5 5m5-5l5 5M13 18h-1.500a1.500 1.500 0 0 0-1.500 1.500v9A1.500 1.500 0 0 0 11.500 30h17a1.500 1.500 0 0 0 1.500-1.500v-9a1.500 1.500 0 0 0-1.500-1.500H27"/>
                    </svg>
                    <span>V Safari ťukněte dole na <b class="font-semibold">Sdílet</b>.</span>
                </li>
                <li class="flex items-center gap-3">
                    <svg class="h-10 w-10 flex-none text-primary" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <rect x="1" y="1" width="38" height="38" rx="10" stroke-opacity=".35"/><rect x="11" y="11" width="18" height="18" rx="4"/><path d="M20 15.500v9M15.500 20h9"/>
                    </svg>
                    <span>Sjeďte níž a vyberte <b class="font-semibold">Přidat na plochu</b>.</span>
                </li>
                <li class="flex items-center gap-3">
                    <svg class="h-10 w-10 flex-none text-primary" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="1" y="1" width="38" height="38" rx="10" stroke-opacity=".35"/><path d="M12 20.500l5.500 5.500L28.500 14.500"/>
                    </svg>
                    <span>Potvrďte <b class="font-semibold">Přidat</b> a aplikaci otevřete z plochy.</span>
                </li>
            </ol>

            {{-- Android --}}
            <div x-show="platform === 'android'" x-cloak>
                <button type="button" class="btn btn-primary btn-block mt-3" x-show="canPrompt" x-on:click="install()">Přidat na plochu</button>
                <ol class="mt-3 grid gap-3" x-show="!canPrompt">
                    <li class="flex items-center gap-3">
                        <svg class="h-10 w-10 flex-none text-primary" viewBox="0 0 40 40" fill="currentColor" aria-hidden="true">
                            <rect x="1" y="1" width="38" height="38" rx="10" fill="none" stroke="currentColor" stroke-width="2" stroke-opacity=".35"/><circle cx="20" cy="12.500" r="2"/><circle cx="20" cy="20" r="2"/><circle cx="20" cy="27.500" r="2"/>
                        </svg>
                        <span>V Chromu ťukněte vpravo nahoře na <b class="font-semibold">⋮</b>.</span>
                    </li>
                    <li class="flex items-center gap-3">
                        <svg class="h-10 w-10 flex-none text-primary" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <rect x="1" y="1" width="38" height="38" rx="10" stroke-opacity=".35"/><rect x="11" y="11" width="18" height="18" rx="4"/><path d="M20 15.500v9M15.500 20h9"/>
                        </svg>
                        <span>Vyberte <b class="font-semibold">Přidat na plochu</b> (nebo Nainstalovat aplikaci).</span>
                    </li>
                </ol>
            </div>

            {{-- Počítač --}}
            <div x-show="platform === 'desktop'" x-cloak>
                <button type="button" class="btn btn-outline btn-block mt-3" x-show="canPrompt" x-on:click="install()">Nainstalovat aplikaci</button>
                <p class="mt-3 text-muted" x-show="!canPrompt">Nejpohodlnější je to v telefonu: otevřete si tam svůj odkaz z e-mailu a přidejte si aplikaci na plochu.</p>
            </div>
        </div>
    </section>

    {{-- 2. Upozornění --}}
    <section class="card mt-2">
        <div class="flex items-center gap-3">
            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-selected font-semibold text-banner-text">2</span>
            <b class="text-[16px] font-semibold">Povolit upozornění</b>
            <span class="tag ml-auto" x-show="subscribed" x-cloak>Zapnuto</span>
        </div>

        <p class="mt-3 text-muted">{{ $pushText ?? 'Připomeneme vám termín a dáme vědět, kdyby se něco změnilo.' }}</p>

        {{-- iPhone bez instalace: vysvětlit, proč to ještě nejde --}}
        <p class="banner mt-3 font-normal" x-show="needsInstallFirst" x-cloak>
            Na iPhonu jdou upozornění zapnout až v aplikaci otevřené z plochy. Nejdřív proto udělejte krok 1.
        </p>

        <div x-show="!needsInstallFirst" x-cloak>
            <p class="mt-3 text-muted" x-show="!supported">
                Tento prohlížeč upozornění nepodporuje. {{ $fallbackText ?? 'Připomínky vám budou chodit e-mailem.' }}
            </p>

            <div x-show="supported">
                <button type="button" class="btn btn-primary btn-block mt-3" x-show="!subscribed && permission !== 'denied'"
                        x-on:click="enable()" :disabled="busy">Povolit upozornění</button>

                <p class="mt-3 text-muted" x-show="permission === 'denied'">
                    Upozornění máte pro tuto aplikaci zakázaná. Povolíte je v nastavení telefonu (Oznámení).
                </p>

                <button type="button" class="btn btn-quiet btn-sm btn-block mt-3" x-show="subscribed" x-on:click="disable()" :disabled="busy">
                    Vypnout na tomto zařízení
                </button>
            </div>
        </div>

        <p class="field-error" x-show="error" x-text="error" role="alert"></p>
    </section>
</div>
