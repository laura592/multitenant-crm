/*
 * Lo scaffolding di Laravel metteva qui axios su window.axios, per le
 * chiamate AJAX di un'app Blade classica. Questo pannello e' Filament, cioe'
 * Livewire: le chiamate le fa lui con il suo fetch, e `window.axios` non lo
 * usava nessuno -- cercato in resources/views, app/ e nel nostro JS, zero
 * occorrenze (03/11/2026).
 *
 * Toglierlo leva 10 kB dal bundle e una dipendenza che ogni paio di mesi
 * compare in `npm audit` con un avviso da rincorrere. Il file resta perche'
 * e' il posto giusto dove mettere eventuale codice da eseguire prima di
 * tutto il resto.
 */
