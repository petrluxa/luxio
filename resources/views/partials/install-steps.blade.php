{{--
    Onboarding: 1) přidat aplikaci na plochu, 2) povolit upozornění.
    Vstup: $pushConfig = ['vapidKey' => …, 'subscribeUrl' => …, 'testUrl' => …]
--}}
<div x-data="pushSetup(@js($pushConfig))">
    {{-- 1. Přidat na plochu --}}
    <section class="card">
        <div class="flex items-center gap-3">
            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-selected font-semibold text-ink">1</span>
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
                    <svg class="h-10 w-10 flex-none text-link" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="1" y="1" width="38" height="38" rx="10" stroke-opacity=".35"/><path d="M20 24V9m0 0l-5 5m5-5l5 5M13 18h-1.500a1.500 1.500 0 0 0-1.500 1.500v9A1.500 1.500 0 0 0 11.500 30h17a1.500 1.500 0 0 0 1.500-1.500v-9a1.500 1.500 0 0 0-1.500-1.500H27"/>
                    </svg>
                    <span>V Safari ťukněte dole na <b class="font-semibold">Sdílet</b>.</span>
                </li>
                <li class="flex items-center gap-3">
                    <svg class="h-10 w-10 flex-none text-link" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <rect x="1" y="1" width="38" height="38" rx="10" stroke-opacity=".35"/><rect x="11" y="11" width="18" height="18" rx="4"/><path d="M20 15.500v9M15.500 20h9"/>
                    </svg>
                    <span>Sjeďte níž a vyberte <b class="font-semibold">Přidat na plochu</b>.</span>
                </li>
                <li class="flex items-center gap-3">
                    <svg class="h-10 w-10 flex-none text-link" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
                        <svg class="h-10 w-10 flex-none text-link" viewBox="0 0 40 40" fill="currentColor" aria-hidden="true">
                            <rect x="1" y="1" width="38" height="38" rx="10" fill="none" stroke="currentColor" stroke-width="2" stroke-opacity=".35"/><circle cx="20" cy="12.500" r="2"/><circle cx="20" cy="20" r="2"/><circle cx="20" cy="27.500" r="2"/>
                        </svg>
                        <span>V Chromu ťukněte vpravo nahoře na <b class="font-semibold">⋮</b>.</span>
                    </li>
                    <li class="flex items-center gap-3">
                        <svg class="h-10 w-10 flex-none text-link" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <rect x="1" y="1" width="38" height="38" rx="10" stroke-opacity=".35"/><rect x="11" y="11" width="18" height="18" rx="4"/><path d="M20 15.500v9M15.500 20h9"/>
                        </svg>
                        <span>Vyberte <b class="font-semibold">Přidat na plochu</b> (nebo Nainstalovat aplikaci).</span>
                    </li>
                </ol>
            </div>

            {{-- Počítač --}}
            <div x-show="platform === 'desktop'" x-cloak>
                <button type="button" class="btn btn-outline btn-block mt-3" x-show="canPrompt" x-on:click="install()">Nainstalovat aplikaci</button>
                <p class="mt-3 text-muted" x-show="!canPrompt">Nejpohodlnější je to v telefonu: otevřete si tam svůj odkaz a přidejte si aplikaci na plochu.</p>
            </div>
        </div>
    </section>

    {{-- 2. Upozornění --}}
    <section class="card mt-2">
        <div class="flex items-center gap-3">
            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-selected font-semibold text-ink">2</span>
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
                Tento prohlížeč upozornění nepodporuje. {{ $fallbackText ?? 'Své termíny uvidíte vždy tady v aplikaci.' }}
            </p>

            <div x-show="supported">
                <button type="button" class="btn btn-primary btn-block mt-3" x-show="!subscribed && permission !== 'denied'"
                        x-on:click="enable()" :disabled="busy">Povolit upozornění</button>

                {{-- Telefon má upozornění pro aplikaci vypnutá (dřív odmítnuto): sama se zeptat nesmí,
                     proto přesný postup a tlačítko, které to po povolení dokončí. --}}
                <div x-show="permission === 'denied'" x-cloak>
                    <p class="mt-3">
                        Telefon má pro tuto aplikaci upozornění vypnutá, proto se na ně nemůže zeptat sama. Zapnete je takto:
                    </p>

                    <ol class="mt-2 grid list-decimal gap-1 pl-5" x-show="platform === 'ios'">
                        <li>V iPhonu otevřete <b class="font-semibold">Nastavení → Oznámení</b>.</li>
                        <li>V seznamu najděte tuto aplikaci (jmenuje se stejně jako ikona na ploše) a zapněte <b class="font-semibold">Povolit oznámení</b>.</li>
                        <li>Vraťte se sem a ťukněte na tlačítko pod tímto textem.</li>
                    </ol>

                    <ol class="mt-2 grid list-decimal gap-1 pl-5" x-show="platform === 'android'">
                        <li>Podržte prst na ikoně aplikace a zvolte <b class="font-semibold">Informace o aplikaci → Oznámení</b>.</li>
                        <li>Oznámení zapněte.</li>
                        <li>Vraťte se sem a ťukněte na tlačítko pod tímto textem.</li>
                    </ol>

                    <p class="mt-2" x-show="platform === 'desktop'">
                        V prohlížeči klikněte vlevo vedle adresy na ikonu nastavení stránky, u Oznámení zvolte Povolit a klikněte na tlačítko pod tímto textem.
                    </p>

                    <button type="button" class="btn btn-primary btn-block mt-3" x-on:click="enable()" :disabled="busy">Už mám povoleno – zapnout upozornění</button>

                    <p class="banner mt-3 font-normal" x-show="stillDenied" x-cloak>
                        Telefon upozornění pořád hlásí jako vypnutá. Zavřete aplikaci úplně (přejeďte ji v přehledu
                        otevřených aplikací nahoru) a otevřete ji znovu z plochy. Když ani to nepomůže, smažte ikonu
                        z plochy, přidejte ji znovu podle kroku 1 a pak ťukněte na Povolit upozornění.
                    </p>
                </div>

                {{-- Zapnuto: zkouška, že upozornění na toto zařízení opravdu dorazí. --}}
                <div x-show="subscribed" x-cloak>
                    <button type="button" class="btn btn-outline btn-block mt-3" x-on:click="sendTest()" :disabled="testing || busy"
                            x-text="testing ? 'Posílám…' : 'Poslat zkušební upozornění'"></button>

                    <div class="banner mt-3 font-normal" x-show="testResult === 'sent'" x-cloak role="status">
                        <b class="font-semibold">Odesláno.</b> Do pár vteřin má na tomto zařízení vyskočit „Zkušební upozornění“.
                        <span x-show="platform !== 'desktop'">
                            Když do půl minuty nepřijde, podívejte se v telefonu, jestli nemáte zapnutý režim Nerušit
                            (Soustředění) a jestli má tato aplikace v nastavení telefonu oznámení povolená.
                        </span>
                        <span x-show="platform === 'desktop'">
                            Když do půl minuty nepřijde, podívejte se, jestli nemáte v počítači zapnutý režim Nerušit
                            a jestli má prohlížeč v nastavení počítače oznámení povolená.
                        </span>
                    </div>

                    <p class="banner banner-danger mt-3 font-normal" x-show="testResult === 'failed' && !error" x-cloak role="alert">
                        Zkušební upozornění se nepodařilo odeslat. Zkuste to prosím za chvíli znovu; když to nepůjde ani
                        potom, vypněte upozornění tlačítkem níže a zapněte je znovu.
                    </p>
                </div>

                <button type="button" class="btn btn-quiet btn-sm btn-block mt-3" x-show="subscribed" x-on:click="disable()" :disabled="busy">
                    Vypnout na tomto zařízení
                </button>
            </div>
        </div>

        <p class="field-error" x-show="error" x-text="error" x-cloak role="alert"></p>

        {{-- Doslovná chyba od telefonu nebo serveru – kdo si neví rady, pošle ji dál. --}}
        <p class="mt-2 break-words text-[13px] text-muted" x-show="detail" x-cloak>Podrobnost: <span x-text="detail"></span></p>
    </section>
</div>
