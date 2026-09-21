{{-- Le fatture Eureka di una scheda lavoro: vedi App\Support\Gestionale\FattureRapportino. --}}
<div class="space-y-4 text-sm">
    @if($esito === 'errore')
        <p class="text-danger-600 dark:text-danger-400">
            Eureka non risponde in questo momento. Riprova fra qualche minuto.
        </p>
    @elseif($esito === 'vuoto')
        <p>Su Eureka <strong>nessuna fattura è collegata</strong> a questa scheda.</p>
        @if(! empty($motivo))
            <p>Il motivo più probabile: <strong>{{ $motivo }}</strong>.</p>
        @endif
        <p class="text-gray-500 dark:text-gray-400">
            Se è recente, la fattura non è ancora stata emessa: riapri questa finestra più avanti.
            Se è vecchia, può essere stata fatturata a mano senza partire dalla scheda, oppure essere il doppione di un'altra scheda.
        </p>
    @else
        <ul class="divide-y divide-gray-200 dark:divide-white/10 rounded-lg border border-gray-200 dark:border-white/10">
            @foreach($fatture as $f)
                <li class="flex items-center justify-between gap-4 px-4 py-3">
                    <div>
                        <div class="font-semibold">{{ $f['etichetta'] }}</div>
                        <div class="text-gray-500 dark:text-gray-400">
                            {{ $f['fe'] ? 'Fattura elettronica emessa' : 'Fattura elettronica non ancora emessa' }}
                        </div>
                    </div>
                    <a href="{{ $f['url'] }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 font-semibold text-primary-600 hover:underline dark:text-primary-400">
                        <x-heroicon-o-document-arrow-down class="h-5 w-5" />
                        Apri PDF
                    </a>
                </li>
            @endforeach
        </ul>

        @if(count($fatture) > 1)
            <p class="text-gray-500 dark:text-gray-400">La scheda è finita su più fatture: sono in ordine dalla più recente.</p>
        @endif

        <p class="text-gray-500 dark:text-gray-400">
            La fattura è intestata a chi paga, e spesso raccoglie anche gli interventi di altri locali nello stesso periodo.
        </p>
    @endif
</div>
