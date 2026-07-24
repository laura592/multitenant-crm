{{-- CSS condivisa da tutti i documenti PDF che usano <x-pdf-letterhead>, cosi'
un ritocco allo stile dell'intestazione non richiede piu' di toccare 3 file
separati in modo identico. Va inclusa dentro il tag <style> del template
chiamante (@include, non un tag <style> proprio: eviterebbe <style> annidati). --}}
.pdf-letterhead { width: 100%; border-bottom: 2px solid #020F30; padding-bottom: 10px; margin-bottom: 16px; }
.pdf-letterhead td { border: none; padding: 0; vertical-align: top; }
.pdf-letterhead .logo-stack { width: 180px; }
.pdf-letterhead .logo-stack .alex-logo { display: block; max-height: 60px; max-width: 180px; }
.pdf-letterhead .logo-stack .franke-partner-logo { display: block; margin-top: 8px; max-height: 48px; max-width: 130px; }
.pdf-letterhead .company-name { font-size: 14px; font-weight: bold; color: #020F30; }
.pdf-letterhead .company-details { color: #6b7280; font-size: 8.5px; line-height: 1.5; }
.pdf-letterhead .to-right { text-align: right; }
