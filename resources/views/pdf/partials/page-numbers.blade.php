{{-- Numero di pagina "Pagina X di Y" centrato in basso, su tutti i PDF generati
con dompdf. Richiede 'enable_php' => true in config/dompdf.php. Deve restare
$pdf->page_text() (non $pdf->text()) coi placeholder letterali {PAGE_NUM}/
{PAGE_COUNT}: e' l'unico modo per farlo ripetere su ogni pagina, perche'
questo script gira una volta sola nel punto in cui appare nel flusso HTML
(qui, in fondo al body) e page_text() rimanda il disegno vero e proprio a
dopo, quando l'impaginazione e' completa, sostituendo i placeholder pagina
per pagina. Il rovescio della medaglia: $fontMetrics->getTextWidth() su
quella stringa coi placeholder letterali misurerebbe una stringa molto piu'
lunga del numero vero, sballando il centraggio - percio' la larghezza per
centrare va stimata sul caso peggiore "N di N" usando $PAGE_COUNT (che invece
e' un intero vero, gia' risolto quando lo script gira). --}}
<script type="text/php">
if (isset($pdf)) {
    $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
    $size = 8;
    $width = $fontMetrics->getTextWidth("Pagina {$PAGE_COUNT} di {$PAGE_COUNT}", $font, $size);
    $x = ($pdf->get_width() - $width) / 2;
    $y = $pdf->get_height() - 24;
    $pdf->page_text($x, $y, "Pagina {PAGE_NUM} di {PAGE_COUNT}", $font, $size, [0.4, 0.4, 0.4]);
}
</script>
