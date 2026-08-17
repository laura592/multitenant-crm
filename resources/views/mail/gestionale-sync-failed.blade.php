<x-mail::message>
<x-mail.hero
	tone="red"
	kicker="Sync Eureka"
	title="{{ $tenant->name }}"
	subtitle="Controllo automatico non riuscito"
/>

Il controllo automatico di questa notte non è riuscito a raggiungere Eureka.

<x-mail.severity-panel color="red">
Ogni chiamata a Eureka durante questa esecuzione è fallita — probabile interruzione del servizio lato Eureka (host non raggiungibile, credenziali rifiutate o simile), non un problema di dati. Nessuna modifica è stata fatta nel CRM.
</x-mail.severity-panel>

Nessun riepilogo da controllare questa volta: non è stato possibile confrontare nulla con Eureka, quindi non arriverà l'email consueta con le proposte. Riprova più tardi da "Sync Eureka" nel pannello, oppure attendi il prossimo controllo automatico — se il problema persiste, verifica lo stato di Eureka direttamente o contatta chi lo gestisce.

<x-slot:footer>
<x-mail.footer-tenant :tenant="$tenant" />
</x-slot:footer>
</x-mail::message>
