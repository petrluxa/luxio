/**
 * Registrace service workeru. Leží v kořeni aplikace – a ta může běžet i v podsložce
 * webu (např. /luxio), proto se adresa skládá z `data-root` na značce <html>.
 */
export const registerWorker = () =>
    navigator.serviceWorker.register((document.documentElement.dataset.root || '') + '/sw.js');
