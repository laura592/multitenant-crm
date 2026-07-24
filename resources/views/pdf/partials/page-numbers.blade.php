{{-- Numero di pagina "Pagina X di Y" in basso a destra, su tutti i PDF generati
con dompdf. Richiede 'enable_php' => true in config/dompdf.php: dompdf sostituisce
{PAGE_NUM}/{PAGE_COUNT} per ogni pagina solo se lo script gira come PHP embedded
dentro il documento (non e' possibile saperlo in anticipo lato Blade/PHP normale). --}}
<script type="text/php">
if (isset($pdf)) {
    $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
    $text = "Pagina {PAGE_NUM} di {PAGE_COUNT}";
    $size = 8;
    $width = $fontMetrics->getTextWidth($text, $font, $size);
    $x = $pdf->get_width() - $width - 28;
    $y = $pdf->get_height() - 24;
    $pdf->page_text($x, $y, $text, $font, $size, [0.4, 0.4, 0.4]);
}
</script>
