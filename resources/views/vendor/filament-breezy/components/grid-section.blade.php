@props(['title', 'description'])

{{--
    Override del layout "etichetta a sinistra, card a destra" di filament-breezy:
    con una descrizione di una riga e una card di form molto piu alta, il
    risultato era uno spazio vuoto verticale enorme sotto l'etichetta. Il
    resto dell'app usa Section a colonna singola (titolo sopra, campi sotto,
    es. UserResource) - qui si allinea la pagina profilo alla stessa
    convenzione invece di reintrodurre il pattern a due colonne di Jetstream.
--}}
<x-filament::section
    :heading="$title"
    :description="$description"
    {{ $attributes }}
>
    {{ $slot }}
</x-filament::section>
