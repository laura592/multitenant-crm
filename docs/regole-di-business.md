# Le regole del CRM Alex

Le regole vere dell'applicazione: non come è fatto il codice, ma cosa deve
succedere e **perché** — con i casi che le hanno fatte nascere, quasi sempre
un errore visto dal vivo in ufficio o in produzione.

Serve a due cose: ritrovare la ragione di una scelta che a distanza di mesi
sembra strana, e non rifare un errore già fatto.

> Le date fra parentesi sono il giorno in cui il caso è emerso.

---

## 1. Chi paga

L'area più sottile dell'applicazione, e quella che ha prodotto più errori
veri sulle fatture.

### Il pagante è della macchina, non del cliente

L'anagrafica di Eureka ha nove campi e **nessuno dice chi paga**. Il dato
esiste solo sulla singola scheda (destinazione) e sulla singola macchina
(`id_intestatario_fattura_f15`). Solo il secondo è anagrafica.

Promuoverlo a regola del cliente aveva fatto dire al CRM che per "Bar Nostro"
pagava Illy, sulla base di **un** intervento del 12/02/2023: 51 clienti su 199
si reggevano così (03/09/2026).

È anche più giusto nel merito: il torrefattore paga per la macchina che ha
dato in comodato, non per tutto quello che si fa da quel cliente.

### Se nessuno lo dice, paga il cliente stesso

Vuoto vuol dire "non l'ha detto nessuno", e allora vale il pagante
dell'anagrafica. Ma quando Eureka indica un pagante **vale quello, anche se è
il cliente stesso**.

> SPINAMIKI (23/09/2026): l'impianto alla spina di Bar Miki arriva dal
> gestionale con codice pagante 233, cioè Bar Miki. Il CRM lo lasciava vuoto,
> quindi valeva il pagante dell'anagrafica — Dersut — e le manutenzioni
> dell'impianto **suo** finivano al torrefattore.

Una macchina può pagarsela il cliente anche se per lui, in generale, paga un
altro.

### Il pagante sta sul posizionamento

Dipende da **dove** è la macchina, e lo storico dice chi pagava quando
(22/09/2026). La macchina tiene la copia della posizione attuale, ed è quella
che leggono lavaggi e rapportini.

- Una macchina spostata **non** si porta dietro il pagante di prima.
- Cambiare il pagante sulla macchina lo cambia sulla posizione attuale.
- Aggiornare solo il codice Eureka non tocca il pagante.
- Un periodo passato si può aggiungere a mano, e si può correggere il pagante
  di una singola riga di storico.

### Per i rapportini già su Eureka il pagante è quello della scheda

Regola decisa il 22/09/2026: per un rapportino già nel gestionale il pagante è
**sempre** quello della scheda Eureka — destinazione, o intestatario se la
destinazione è vuota — copiato e mai deciso dal CRM.

- Se nome e codice della destinazione dicono due aziende diverse, **vale il
  nome**. Ma un nome che corrisponde a due clienti non scavalca il codice.
- Destinazione vuota: paga l'intestatario, non il pagante della macchina.
- Un pagante che sulla scheda c'è ma nel CRM non esiste **non si inventa**.
- La fattura non cambia il pagante: segnala la scheda **da correggere su
  Eureka**. Corretta lì, il ricontrollo allinea e la segnalazione sparisce.
  C'è anche un "va bene così" per chiudere il caso.
- L'autofattura di un fornitore non fa scattare il controllo.

### Il congelamento

Alla chiusura il rapportino congela il pagante, così un cambio successivo non
riscrive la storia. Ma:

- **Un pagante che non ha deciso nessuno non si congela** — altrimenti si
  fissa un valore ereditato per sbaglio.
- Il congelamento **non** sopravvive a un cambio del *cliente*: lì non si
  riscrive la storia, si corregge a chi appartiene il documento.
  > 04/09/2026: rapportino intestato per sbaglio a "Per SRL" invece che a
  > "Perenzin Latteria SRL"; corretto il cliente, era rimasto il pagante di
  > quello sbagliato.
- La macchina col pagante suo vince anche dopo la correzione, e una scelta
  esplicita fatta nello stesso salvataggio resta.
- Svuotare "Fatturare a" deve tornare al pagante della macchina, non
  ricaricare il cliente dalla relazione.

### Il preventivo va a chi lo ha chiesto, mai a chi paga

Il destinatario si prendeva da `Customer::invoiceRecipient()`, che segue il
pagante: su Mariver quello è Dersut, e la mail partiva al torrefattore invece
che al cliente (03/09/2026). Stessa regola già valida per i rapportini — con
una differenza importante: i **rapportini** vanno al cliente del cantiere, mai
al pagante, mentre l'ufficio può scegliere di mandare una copia anche al
pagante.

### "Chi paga per chi"

L'elenco di quello che si fattura a un torrefattore — 29 clienti e 33 macchine
per Martellozzo — si legge **dalle macchine, non dai clienti**. È guardando
questa lista che si scopre che due sedi vicine hanno i paganti incrociati
(richiesta dell'ufficio, 04/09/2026). I clienti normali non compaiono fra i
paganti.

---

## 2. Macchine e storico

### Eureka non chiude mai la consegna vecchia

La stessa matricola compare presso più clienti, ognuno con la sua bolla. Vale
**la più recente**, e il sync propone di spostare la macchina.

> Matricola 1863540 (22/09/2026): Agorà Park Hotel dal 2024, Hotel Principe
> Palace dal 20/04/2026.

Quando non si può dire, non si propone niente. Una proposta scartata non
ritorna. Con un elenco non letto (chiamata Eureka fallita) non si propone
nulla — mai dedurre da dati incompleti.

### Lo storico si ricostruisce dalle bolle

Il sync guarda solo l'ultima bolla, quindi i periodi di mezzo non diventano
mai storico.

> Macinadosatore 0819352 (23/09/2026): risultava sempre stato all'Hotel
> Principe Palace, mentre la bolla 205 del 28/04/2025 lo dava all'Hotel
> Venezia per un anno.

Il recupero: riempie i buchi, ma se adesso la macchina è da un altro cliente
lo spostamento resta al sync; due consegne lo stesso giorno da clienti diversi
si saltano; senza buchi non tocca niente.

**Lo storico si guarda dalla scheda della macchina: se non compare lì, non si
vede da nessuna parte** (23/09/2026).

### Spostamenti sbagliati

- "Annulla ultimo spostamento" rimette la macchina dov'era senza lasciare
  nello storico un passaggio mai avvenuto. Se veniva dal magazzino, torna in
  magazzino. Uno spostamento vecchio non si annulla.
- Dal dettaglio si elimina **uno spostamento qualsiasi**, non solo l'ultimo, e
  lo storico si ricuce: eliminando una riga in mezzo, la precedente copre il
  periodo.

### I rientri in magazzino

Il ritiro si fa con un DDT di ritiro, che **dall'API non si legge**: Eureka
continua a dare la macchina dal cliente di prima.

> Hotel Bellevue (23/09/2026): otto macchine ritirate il 21/09 e ancora lì.

La traccia utilizzabile è la riga DISIN/RITIRO nel rapportino. Un ritiro
precedente alla consegna attuale non si ripropone; se il tecnico ci è tornato
dopo, la macchina è ancora lì; un articolo qualsiasi non è un ritiro.

### Le fusioni di doppioni

Ogni doppione è un rapportino che non si abbinerà mai. L'import degli
installati aveva creato due volte la stessa orzina perché Eureka la scrive con
e senza spazi, e in anagrafica c'era "A 300 3400000310192" accanto a
"3400000310192".

**Il rischio non è mancare una fusione: è proporne una sbagliata, che fa
sparire una macchina vera.** Quindi:

- Punteggiatura e zeri iniziali non fanno due macchine; il modello scritto
  davanti al seriale si riconosce; ma un prefisso per caso non è un doppione.
- Non si fondono macchine di clienti diversi, né l'impianto acqua con quello
  alla spina, né due impianti alla spina dello stesso cliente (non si
  indovina).
- Le matricole segnaposto non si fondono mai.
- La stessa matricola scritta **identica** due volte resta due macchine; due
  scritture **diverse** su Eureka si propongono da controllare.
- Assorbire sposta i rapportini e riempie i vuoti senza raddoppiare la
  consegna; si tiene la matricola che usa Eureka; il sync non ripristina la
  macchina fusa.

### Matricole

Si confrontano senza la punteggiatura con cui Eureka le scrive: nell'import
del 02/09/2026 lo stesso apparecchio è entrato due volte perché un cliente lo
aveva come "BRL 003 020002113218" e un altro come "BRL003020002113218".

### Il codice manutenzione

Dipende dal **modello** della macchina (Faema 3 gruppi → F3, Cimbali 2 → C2,
Dalla Corte A/2 → DC2), dichiarato in `Material::maintenance_code`, **e dal
pagante** (F3 → F3GOPPION). Il codice sulla macchina vince su quello del
modello; il pagante della macchina vince su quello del cliente; senza la
variante del pagante si ricade sul codice base.

Si legge dal nome del modello con regole in ordine: **le marche con codice
proprio vanno riconosciute prima della regola generica sui gruppi**,
altrimenti "DALLA CORTE DC PRO 3 GRUPPI" diventa una F3.

Resta un campo scrivibile a mano, con i codici suggeriti da un elenco: il
catalogo di Eureka cambia, e un menu chiuso costringerebbe ad aspettare
l'import per registrare una macchina (04/09/2026).

---

## 3. Rapportini

### Il numero non cambia mai significato

L'ufficio stampa il riepilogo e quei numeri finiscono su carta: RT-2026-0579
era "Hotel Vidi Miramare" sulla stampa del 02/09/2026. Riassegnarlo a una
scheda importata avrebbe reso quella carta bugiarda.

Quindi si va **sempre avanti**, e i buchi lasciati dalle unioni restano —
anzi, dicono che lì c'è stata un'unione. Un archiviato tiene il suo numero e
nessuno lo riusa.

### Il rapportino a passi

Si spuntano le macchine, un passo per macchina, **una firma sola**, un
rapportino per macchina. Nasce da due visite vere: Hotel Olanda (21/09/2026,
due X20 con lo stesso lavoro) e La Strana Coppia (18/09/2026, spina e acqua,
due passi diversi).

- "Modifica" apre solo quel rapportino; "Modifica visita" apre tutti quelli
  della visita, partendo dal suo passo.
- Senza lavoro svolto non salva niente.
- Un rapportino già su Eureka non si divide e non si modifica.
- Dalla matricola si trova il cliente; una matricola in magazzino non lo
  indovina.
- **La matricola si corregge dove si vede** (24/09/2026): con una macchina
  sola il passo *è* il rapportino e il campo è modificabile. Con più macchine
  il passo *è* la macchina — cambiarla lì vorrebbe dire spostare il passo, e
  due passi finirebbero sulla stessa; si cambia dal passo "Macchine".

### Le copie da inviare cambiano col ruolo

Indicazione dell'ufficio, 04/09/2026:

| | Copie | Articoli | Destinatari |
|---|---|---|---|
| Tecnico | una sola | no | solo chi ha ricevuto l'intervento |
| Ufficio | tre a scelta | sì | anche il pagante |

Se resta solo il pagante non parte niente. L'email mandata dopo il passaggio in
gestionale non declassa lo stato. Il riepilogo dell'invio mostra quello che
parte davvero e **avvisa quando escono i prezzi**.

### I prezzi sul PDF

Chi stampa sceglie: la copia da lasciare al cliente in cantiere spesso non deve
dire quanto costa, quella per l'ufficio sì. Un dipendente non ottiene i prezzi
nemmeno chiedendoli esplicitamente via URL. Non riguarda il PDF allegato alla
mail, che non ha prezzi mai.

La scelta deve esserci **anche sulla pagina del singolo rapportino**, non solo
nell'elenco: è lì che si finisce quando si guarda un intervento, ed è lì che si
stampa (02/09/2026).

### Le righe senza prezzo

Le scorciatoie del rapportino creano la riga con articolo e quantità e basta,
e la copia coi prezzi mostrava "—" su voci che a listino un prezzo ce l'hanno
(RT-2026-0770: CHIORD 46,20 e ORE 42,00, entrambe vuote).

Una riga senza prezzo prende il listino; un prezzo già presente non si
sovrascrive; cambiando quantità o articolo l'importo segue; un prezzo corretto
a mano sopravvive al salvataggio.

### La firma

- Più rapportini dallo stesso cliente si fanno firmare **una volta sola**.
- Senza firma si salva lo stesso, e "Fai firmare" propone tutta la visita.
- La firma è un **dato personale**: fino al 23/09/2026 finiva su
  `storage/app/public`, che `/storage` serve a chiunque senza login — il nome
  era un UUID, ma "difficile da indovinare" non è un controllo d'accesso. Ora
  sta sul disco privato, dietro permesso.

### Elenco e ordinamento

- Diviso **per anno**: con lo storico importato sono migliaia di righe e quasi
  sempre si cerca quest'anno. Se quest'anno è vuoto, apre l'ultimo con dati.
- I due numeri si ordinano in modi diversi: quello del CRM come è stato
  emesso, quello del gestionale per valore. Sbagliarlo non dà errore, dà un
  elenco in ordine sbagliato — molto più difficile da notare.

### Azioni di massa

Chiudere i rapportini uno per uno su un elenco da 18 pagine è il lavoro di una
mattina. "Cambia stato" li porta tutti nello stesso stato, **senza forzare
quelli che il CRM non può più toccare**: già passati in Eureka, o nel cestino.
Lo stato in gestionale da solo non blocca; l'aggancio a una scheda Eureka sì.

---

## 4. Lavaggi, sanificazioni, piani

### Le vie sono il campo su cui si fattura

Su 989 lavaggi, 293 non avevano il numero di vie (24/09/2026). Il numero c'è
quasi sempre, ma scritto altrove: nella descrizione del tecnico ("6 Vie +
Chiusura") o nelle vie del piano. Si recupera in quest'ordine — prima quello
che ha scritto il tecnico, poi il piano — e **non si inventa**: senza fonte il
lavaggio resta da guardare a mano. Oltre la dozzina è un errore di battitura.

### Scegliere l'impianto deve portare le voci da fatturare

LAV2, e ULTVIA oltre le due vie. Le vie si riempivano da sole scegliendo il
piano, ma **scriverle da codice non risveglia l'`afterStateUpdated` del
repeater**: quello scatta solo se le vie le digita una persona. Chi sceglieva
l'impianto e si fermava lì restava senza righe da fatturare, e niente glielo
diceva (segnalato dal vivo il 03/09/2026).

Un impianto acqua prende la sanificazione, non il lavaggio vie; acqua e birra
insieme non si mescolano; spegnere la sanificazione toglie la sua voce.

### Le vie vanno selezionate, o i piani si scambiano

Senza le vie, un lavaggio non sa a quale piano appartiene e i piani di
manutenzione si incrociano.

### La pausa stagionale

Campeggi e chioschi chiudono a ottobre e riaprono a primavera: il piano
continuava a scadere tutto l'inverno, e a gennaio il promemoria elencava
locali chiusi (24/09/2026).

Un lavaggio con "Chiusura" mette il piano in pausa, uno con "Apertura" lo
rimette in moto. Un lavaggio normale non tocca la pausa. **In pausa non vuol
dire chiuso**: il piano resta attivo e con la sua storia, ma non scade e non
entra nei promemoria. Con una data, riprende da solo quel giorno.

### I doppioni

> "La Strana Coppia" (21/09/2026): la stessa visita di lavaggio compariva
> ripetuta per birra, vino, bibite, più righe orfane senza piano.

Lo storico mostra **una riga per visita e impianto**. Il rapportino toglie le
righe orfane generiche ma non le note vere. Un lavaggio segnato a mano lo
stesso giorno si aggancia al rapportino invece di duplicarlo.

Attenzione ai piani "non ancora classificati" (bevanda vuota, residuo di un
import): lo split per tipo di impianto ne creava uno duplicato per ognuno
invece di riconoscere che uno bastava — 8 doppioni reali il 12/08/2026.

### Frequenze

Il vino non ha una frequenza standard e resta "a chiamata". Un piano a
chiamata senza frequenza tiene comunque traccia dell'ultimo lavaggio. Spostare
un lavaggio su un altro piano ricalcola entrambi.

### Il promemoria

Elenca gli scaduti e quelli in scadenza, con cliente, impianto e ultimo
lavaggio. Restano fuori i piani lontani, chiusi, a chiamata, di manutenzione e
**in pausa**. Nessuna mail se non c'è niente da lavare o se non ci sono
destinatari configurati.

---

## 5. Preventivi, offerte, contratti

### Il form di creazione resta minimale

Le righe prodotto sono un dato da compilare **dopo**, col wizard "Configura
macchina", non un campo da riempire mentre si sta ancora creando il preventivo.

### La guida al conteggio

Gli articoli a catalogo sono 83, e la differenza fra "con VIP-1", "senza
interfaccia" e "predisposto per il lettore" non si legge dal nome in un
selettore generico.

**Il punto fermo: si finisce sempre su un prodotto reale del listino, col suo
codice d'ordine** — non su una somma di supplementi, che sarebbe giusta come
totale ma inordinabile da Franke. Il conteggio si offre solo sulle Franke, ma
può essere quotato anche da solo, senza macchina.

Gli alloggiamenti mancanti dal listino Franke 2026 sono stati aggiunti: senza,
quotare una gettoniera su un alloggiamento senza VIP-1 costringeva a usare la
riga con VIP-1, **355 euro più cara**.

### I contratti di assistenza

Full-Service 10% del listino di macchina, sistema latte e optional;
Easy-Service 5% di macchina e sistema latte, attivabile dal secondo anno. **Il
frigorifero fa parte del sistema latte** (indicazione dell'ufficio).

Il canone non entra nel totale del preventivo e lo sconto non lo abbassa. Su
una macchina non Franke non si salva. W3 non chiede il trattamento acqua.

Il modello del contratto si carica da **Documenti** (ex "Listini") e si usa
quello in vigore: niente copia di riserva nel codice, senza modello caricato il
contratto non si genera. Vale la decorrenza più recente; scaduti e futuri non
valgono; il contratto di un altro tenant non si usa.

### L'offerta caffè

Un documento a parte dal preventivo (21/09/2026), coi prezzi del listino
ritoccabili per il singolo cliente e **senza quantità**, perché quanti chili
prenderà non si sa.

Il prezzo ritoccato va sul foglio e non nel listino; il listino che cambia non
tocca le offerte già fatte. Il foglio divide caffè e liofilizzati, e non ha
quantità né totale. L'amministrazione stampa ma non cambia il listino; i
tecnici non fanno offerte.

### La risposta del cliente

Dal link nella mail il cliente conferma **firmando**, rifiuta, fa una domanda o
chiede di essere richiamato. Senza firma non si accetta. Un preventivo già
accettato non si riaccetta né si rifiuta. Nell'offerta globale la soluzione
scelta vince e le altre si chiudono. "Per ora no" richiede un motivo. Domande e
richiami non cambiano lo stato, e se ne possono fare anche dopo aver accettato.

### L'ordine cronologico

Segnalato due volte: prima "non sono in ordine cronologico", poi — dopo un
primo tentativo — "non sono in ordine numerico". Né `created_at` né `date` sono
affidabili sui dati importati dal legacy: `created_at` è quasi identico su
tutti (l'istante dell'import) e `date` ha molte righe con lo stesso giorno.
**`number` (PRV-AAAA-NNNN, zero-padded) è l'unico campo che dà un ordine
deterministico.**

### Le richieste informazioni

Seguono da sole lo stato dei preventivi collegati: prima andavano cambiate a
mano e restavano "Nuova" — e contate fra quelle da gestire — a preventivo già
inviato. Un'accettata vince, una in attesa batte una rifiutata. Le richieste
chiuse a mano si lasciano stare.

Una richiesta può generare **più** preventivi (varianti, rilanci) ed
eventualmente un'offerta che li raggruppa: il collegamento sta sul preventivo.
Con due richieste aperte non si sceglie per l'utente.

Nell'elenco si vedono **tutti** i contatti: 99 clienti hanno più di un'email e
19 più di un numero, e chi richiamava da lì finiva ad aprire l'anagrafica per
trovare l'altro recapito (03/09/2026).

---

## 6. Eureka

### Sola lettura

La domanda dell'ufficio prima di dare le API di produzione (21/09/2026): *"sei
sicuro che leggi i dati e non mi mandi nessun delete?"*. Con
`EUREKA_SOLA_LETTURA` le scritture non partono — l'invio a gestionale è
bloccato, le letture passano, gli altri servizi non si toccano.

### I 500 a intermittenza

L'API del fornitore restituisce 500 sulle stesse identiche query. La ricerca
per periodo dell'import rapportini è l'unica chiamata non ridondante, quindi un
solo 500 di passaggio faceva fallire l'intero import notturno senza importare
niente — successo il 30/08/2026 alle 04:00.

Ora si riprova, **a distanza crescente** perché gli errori arrivano a raffica.
Un errore 4xx invece non si riprova. Un errore sporadico in mezzo a chiamate
riuscite non si segnala.

### Un lato in errore non cancella l'altro

Regola trasversale a tutti gli import. Le partite aperte sono una
**fotografia** che si rifà da zero: si cancella e si riscrive, perché una
fattura incassata sparisce da Eureka e nessun ciclo sui dati nuovi la
incontrerebbe mai.

Il che rende la cancellazione la parte pericolosa. Da qui in poi la regola è
una sola: **si cancella solo ciò che Eureka ha davvero riconfermato**. Una riga
persa non è un buco visibile in una tabella — è un cliente che scompare
dall'elenco di chi va sollecitato, cioè una telefonata che non viene fatta.

Un'anagrafica col dettaglio fallito conserva le righe di ieri. Una risposta
davvero vuota invece svuota davvero.

### Le date

Eureka distingue `data` (data documento, spesso quando la scheda viene
archiviata in ufficio) da `sl_dataora_appuntamento` (quando il tecnico è stato
davvero dal cliente, a volte giorni prima). L'import mostrava la prima
spacciandola per data dell'intervento. Senza appuntamento, o con un
appuntamento implausibile, si ripiega sulla data documento.

Il dettaglio cash flow torna in **formato italiano** mentre tutti gli altri
endpoint parlano ISO: trascriverlo male sposta l'importo di mese.

### Il tipo intervento

Eureka non lo conosce: l'import lo deduce dal testo e ripiega su "riparazione".
Una scheda che nel CRM è stata segnata come sanificazione **non deve tornare
riparazione** al sync successivo.

Le sanificazioni impianto acqua si riconoscono dalla **riga SANIFICAZIONE**,
non dal testo: "sanificazione" nei rapportini si usa anche per il lavaggio
birra, che è un'altra cosa. Prima finivano tutte "riparazione" — 61 su 61 in
produzione (21/09/2026). Se la scheda dice installazione, resta installazione.

### Gli acconti

Le fatture di acconto e le righe che le detraggono si riconoscono solo dal
**testo** della riga documento, scritto a mano da persone diverse in anni
diversi. Nei dati reali convivono almeno sei forme:

```
A DETRARRE FATTURA DI ACCONTO NR. 178/25
A DETRARRE FATTURA DI ACCONTO NR . 178/25     (spazio prima del punto)
A DETRARRE FATTURA DI ACCTO NR 33/24          (abbreviato)
A DETRARRE FT ACCONTO NR. 16/25
A DETRARRE FATTURA DI ACCONTO N. 44
```

Due volte un pattern troppo rigido ha marcato come mai saldati acconti che
invece lo erano: la lista passò da 23 casi a 10 e l'importo da 143.000 a 59.000
euro. Una detrazione senza numero resta un caso da verificare; lo zero davanti
al numero non fa sembrare aperto un acconto saldato; una bolla non vale come
fattura di saldo; la detrazione di un anno non chiude l'acconto omonimo di un
altro.

### Gli articoli

`sl_articolo` (il bene su cui si è intervenuto) è un articolo Eureka come i
ricambi: materializzarlo come prodotto riempiva il catalogo preventivi di
macchine del parco installato che a listino non esistono — spesso già presenti
in Materiali con lo stesso codice. **Va in Materiali, non in Prodotti.**

Dell'importo di riga si tiene anche `importo` (prezzo netto × quantità, sconti
di riga già applicati): è l'unico dato che rende ricostruibile il valore
economico reale.

### I nomi dei clienti

"Cliente Eureka 2933" al posto del nome (23/09/2026) nasce quando l'import
trova una scheda intestata a un codice che nel CRM non c'è, e l'elenco non
porta la ragione sociale. Da lì non si sistema più da solo — il sync cerca
l'anagrafica per nome, e quel nome su Eureka non esiste. **Il nome vero sta nel
dettaglio della scheda.** Una scheda intestata a un altro codice non rinomina
niente.

### I doppioni con le schede importate

Il sync propone l'abbinamento fra un rapportino compilato qui e la scheda
importata che documenta lo stesso intervento; la conferma **tiene il nostro**,
travasandogli il collegamento a Eureka.

> 21/09/2026: di 58 rapportini recuperati, 35 erano doppioni del tecnico.

Non propone macchine diverse dello stesso cliente, né niente quando i candidati
sono due (davvero ambiguo → decide una persona). Fra due candidati propone
quello che stacca. La conferma adotta la macchina se qui manca, ma non
sovrascrive una già presente. Le righe sostituite restano recuperabili, e una
scheda senza righe non cancella i materiali. Una scheda mandata per mail non si
rinumera.

### Il sync non sovrascrive

Riempie i campi vuoti e **segnala** le differenze invece di sovrascrivere. Le
email e i telefoni nuovi si aggiungono senza togliere gli esistenti. Le note di
Eureka si rispecchiano, ma una chiamata che non torna niente non le cancella.
Una partita IVA o un codice fiscale che sembrano segnaposto non si scrivono.

Il collegamento si propone su corrispondenza esatta di partita IVA; per i
prodotti solo se la ricerca è univoca.

### Ultimi aggiornamenti

"Ultimi aggiornamenti da Eureka" e "Sincronizza ora" nascono da un fatto:
l'import dei rapportini era a zero schede **da settimane** e nessuno lo sapeva
(22/09/2026). Un giro riuscito lascia ora e totali, uno fallito dice perché, e
un lavoro che non gira da troppo si vede.

### Un rapportino sparito non ferma l'import

L'elenco dei già importati si carica una volta sola, e con `--with-detail` il
ciclo dura minuti: se nel frattempo qualcuno elimina dal pannello uno di quelli
in coda, a esplodere è l'inserimento delle righe materiale, che non trova più
il padre. Successo in produzione il 03/09/2026 su RT-2026-0676, con l'import
fermo a un terzo del lavoro.

---

## 7. Contabilità

- Il **fatturato** confronta periodi della stessa lunghezza.
- Il riquadro **RIBA** separa ciò che non passa mai dallo scaduto.
- I **saldi divergenti** mostrano anche chi non ha nessuna partita aperta, e
  hanno un riquadro anche per i **fornitori**.
- Il **cash flow** conta solo i mesi da qui in avanti.
- Gli incassi non imputati abbassano quello che si chiede.
- Il riquadro non dice cose che i dati smentiscono.

Fatturato e cash flow sono gli unici due numeri che **non ricostruiamo da
soli**: arrivano già calcolati da Eureka. Il che sposta il rischio
dall'aritmetica alla trascrizione.

### Perché un rapportino non ha fattura collegata

Cinque regole, dai 150 casi guardati a mano il 21/09/2026 (18 recenti, 22 senza
importo, 31 doppioni, 62 fatturati senza collegare la scheda, 17 da verificare):

1. **Recente** — il mese in corso e quello prima.
2. **Senza importo.**
3. **Doppione** di una scheda fatturata (non è doppione se cambia cliente o è
   lontano nel tempo).
4. **Probabile** se la fattura è fatta a mano; **da controllare** se è fatta
   dalle schede.
5. **Da verificare** — tutto il resto.

La fattura può essere intestata a chi paga. Una fattura troppo lontana non
conta. Con la fattura collegata il motivo sparisce.

### Lo scaduto

È la lista con cui si telefona, e al telefono si segna a penna: la stampa esce
con quello che si vede a schermo (stesso ordine, stessa ricerca) e **senza
paginazione** — stampare solo i primi 25 di una lista ordinata per urgenza
vorrebbe dire perdere proprio chi va richiamato.

---

## 8. Personale

### Le trasferte

Regola dell'ufficio (21/09/2026): **nel giorno di trasferta la prima ora oltre
il contratto la paga già l'indennità**, che il dipendente la faccia o no. Lo
straordinario parte dall'ora dopo. Quell'ora compresa non è né ordinaria né
straordinaria. Basta un turno in trasferta per tutta la giornata; due turni in
trasferta fanno un giorno.

Sotto il contratto, le ore mancanti restano mancanti.

### Ferie e permessi

- Le **ferie** saltano sabati, domeniche e festivi; la **malattia** conta
  giorni di calendario.
- Un permesso calcola le ore dall'intervallo orario e resta su un giorno solo;
  l'ora di fine prima di quella di inizio si rifiuta.
- Un dipendente non approva la propria richiesta, ma il titolare sì.
- L'amministrazione può creare una richiesta per un altro dipendente.
- Una richiesta approvata non si modifica né si cancella dal dipendente; un
  responsabile può revocarla.
- Chi decide fa scattare una notifica al dipendente.
- La mail di notifica porta a una **vista con Approva/Rifiuta**, non alla
  pagina di modifica.

### Presenze

La pausa pranzo è esclusa dalle ore lavorate. Lo straordinario settimanale si
calcola anche senza straordinario giornaliero. Un dipendente vede solo la
propria riga nel riepilogo mensile; amministrazione e admin vedono tutto il
tenant.

---

## 9. Ruoli, permessi, sicurezza

`App\Support\RolePermissions::for()` è **l'unica fonte di verità**, sia per il
seeder sia per i controlli.

| Ruolo | Cosa può |
|---|---|
| **Partner** | catalogo in sola lettura, clienti e preventivi propri. Niente scadenzario, presenze, metodi di pagamento, gestione tenant |
| **Dipendente** | opera tutti i giorni, non gestisce il catalogo condiviso né cancella clienti |
| **Amministrazione** | dati HR, rapportini, catalogo; legge i preventivi ma non li tocca; **non cancella mai** |
| **Admin** | gestisce tutto l'operativo, non la gestione tenant |

### L'aggiornamento non deve togliere permessi

`update.sh` lanciava il seeder dei ruoli dopo ogni `git pull`: finché il seeder
riallineava **tutti** i ruoli al codice, ogni aggiornamento cancellava i
permessi concessi a mano dalla pagina Ruoli (successo più volte su
"amministrazione"). Oggi lo script non lancia nessun seeder: il riallineamento
al codice è un comando esplicito.

Anche per questo i permessi nostri sui rapportini **devono comparire nella
schermata dei ruoli**: non c'erano, e chi modificava un ruolo dal pannello li
perdeva senza accorgersene — è così che "vedere i prezzi" è sparito dal ruolo
admin (04/09/2026) e poi da amministrazione (22/09/2026: Cristina non vedeva
più le fatture collegate ai rapportini).

### Isolamento fra tenant

Le rotte **fuori dal pannello** (PDF rapportino, PDF preventivo, scheda
anagrafica, stampe parcheggiate) non hanno lo scope automatico di Filament:
`Filament::getTenant()` torna `null`. Senza un controllo esplicito nella
policy, qualunque utente autenticato col permesso generico poteva scaricare il
documento di un altro tenant.

Il catalogo condiviso (`tenant_id` NULL) invece deve essere visibile a
**qualsiasi** tenant: Filament applica un proprio scoping con uguaglianza
stretta che lo nasconderebbe, e va disattivato esplicitamente sulle risorse di
catalogo.

### Le note del preventivo

Sono un RichEditor, quindi escono con `{!! !!}`: il loro HTML è il punto.
Filament però **non le sanifica lato server** — quello che arriva è l'HTML
mandato dal browser via Livewire, e un client manomesso manda quello che vuole.
La pagina pubblica del preventivo la apre **il cliente**, che non c'entra
niente con chi ha scritto la nota: uno `<script>` lì dentro girava nel suo
browser.

### Le stampe

Si aprono in una scheda, non si scaricano (04/09/2026). Un'azione Filament che
ritorna una response fa sempre partire un download, quindi il PDF viene
parcheggiato e aperto via URL — che serve il PDF **inline** e solo a chi l'ha
generato, con una scadenza.

### 2FA

Il giro era rotto dentro una dipendenza, per questo
`enableTwoFactorAuthentication(force:)` è rimasto a `false`: con `force: true`
nessuno riusciva ad attivarla, quindi nessuno riusciva più a entrare. Dal
23/09/2026 funziona, ma era **un guasto dentro una dipendenza**, cioè il tipo
di cosa che un `composer update` può rimettere come prima senza dire niente.

---

## 10. Trappole tecniche

Le cose che non danno errore quando le scrivi, e lo danno in produzione.

### Filament / Livewire

- **I widget usati solo in `Page::getHeaderWidgets()` non sono registrati con
  Livewire.** Filament registra solo quelli in `Panel::widgets()` o
  `Resource::getWidgets()`. Quando uno di questi si carica lazy, Livewire non
  trova il componente e solleva un errore che arriva al browser come **419** —
  che `app.js` traduce in "sessione scaduta". È successo tre volte in due
  giorni, ogni volta con una diagnosi diversa e sbagliata, perché il sintomo
  non ha niente a che vedere con la causa. Aprire `/scaduto` rimbalzava su
  `/sessione-scaduta` proprio per questo.

- **Shield istanzia ogni Page e ne chiama `getTitle()`** senza montarla e senza
  tenant attivo, per costruire la matrice dei permessi. Una Page che nel titolo
  legge una proprietà valorizzata solo da `mount()` manda in 500 **l'intera
  pagina Ruoli**, non solo sé stessa. Successo il 02/09/2026 con
  DettaglioScaduto, che riceve il codice cliente dall'URL.

- **`->columns(6)` vale solo da `lg` in su (1024px)**; sotto, la griglia è a
  colonna unica. `->columnSpan(3)` invece vale a **ogni** larghezza — anche
  dentro quella colonna unica. Risultato: una riga larga tre colonne in una
  griglia che ne ha una, la pagina straborda di lato e i campi si schiacciano.
  È il motivo di "le offerte caffè a mobile si vedono male" (23/09/2026).
  **Lo span deve portare lo stesso breakpoint della sua griglia**:
  `->columnSpan(['default' => 1, 'lg' => 3])`.

- **Le liste vanno guardate da un telefono.** Sette-undici colonne su 360px
  scorrono di lato, e quello che serve per decidere sta fuori campo. Le colonne
  secondarie vanno `->visibleFrom('md')`: restano nel DOM, quindi la ricerca
  continua a trovarle.

- **Filament risolve gli argomenti delle closure per NOME.** Una closure che
  dichiara `fn (?int $s)` invece di `$state` fallisce con "was unresolvable" —
  ma solo quando c'è davvero un valore da mostrare.

- **Scrivere uno stato da codice non risveglia `afterStateUpdated`**: quello
  scatta solo su input umano.

- **Sul form di creazione, `getByRole('button', {name: /Crea/})` prende il
  bottone sbagliato** — "Crea nuovo cliente" dentro la select Cliente. Il
  submit vero è "Salva".

### Laravel

- **Un flag booleano nello scheduler va passato come `'--with-detail'`**, non
  `'--with-detail' => true`: Laravel lo rende `--with-detail='1'`, che Symfony
  rifiuta prima ancora di eseguire. L'import notturno è rimasto rotto **una
  settimana** per questo.

- **`--env=testing` senza `.env.testing` ricade su `.env`.** Un
  `migrate:fresh` così cancella il database vero. Per i test si passa solo
  `-e DB_DATABASE=...`.

- **Le traduzioni dei pacchetti si sovrascrivono per FILE INTERO, non per
  chiave.** Una copia incompleta non dà errore: fa sparire le etichette
  rimaste fuori, e ci si ritrova i pulsanti con scritto
  `filament-panels::resources/pages/create-record.form.actions.create.label`.
  (La traduzione italiana dice "Nuovo :label", che su nove risorse dal nome
  femminile dava "Nuovo offerta caffè": la sovrascrittura usa "Crea :label".)

- **`updateOrCreate()` sui rinnovi perde lo storico.** Assicurazione, bollo e
  revisione sovrascrivevano la riga a ogni rinnovo. Rinnovare vuol dire
  chiudere la riga corrente e crearne una nuova.

- **I campi NOT NULL svuotati mandano NULL** e l'utente vede solo "Qualcosa è
  andato storto". Uno sconto vuoto vuol dire "nessuno sconto", non "errore".

- **Senza `lang/`, `APP_LOCALE=it` mostra la chiave nuda** `validation.required`
  sotto al campo. Su un form lungo l'errore resta anche fuori schermo: serve
  un avviso in testa.

### Ambiente

- **Su cPanel `proc_open` è disabilitato** (21/09/2026): la compressione PDF si
  salta, non manda in errore il salvataggio.
- **HSTS non va emesso fuori da HTTPS in produzione.**
- **MySQL da riga di comando vuole `--default-character-set=utf8mb4`**, altrimenti
  i dati accentati tornano illeggibili.

### Ricerca

"alex s.r.l." non trovava "Alex SRL" e restituiva mezzo elenco (22/09/2026): la
ricerca sui telefoni, privata delle cifre, cercava `%%` — che corrisponde a
tutto. La ricerca ignora la punteggiatura, ma non deve trovare tutti.

---

## 11. Stampe

| Stampa | Regola |
|---|---|
| **Riepilogo rapportini** | cliente, chi paga, macchina e articoli su una riga, in orizzontale, **senza importi**. Le date invertite si raddrizzano |
| **Riepilogo macchine** | segue l'elenco che si ha davanti: senza filtri il parco completo, cercando un cliente le sue macchine. Un'azione sola invece di due |
| **Scheda anagrafica** | precompilata, il cliente la controlla e la rimanda firmata. È un **modulo PDF compilabile**. Sezione A il soggetto, sezione B il pagante se diverso; le anagrafiche pagate diventano sedi operative; un cliente senza sedi collegate è sede di sé stesso. Consensi, firma e condizioni di pagamento restano in bianco |
| **Appuntamenti** | serve in mano prima di uscire: numero richiesta, contatti e zona, raggruppati per giornata |

---

## Dove guardare

| | |
|---|---|
| Architettura | `docs/architecture.md` |
| Migrazioni compattate | `docs/migrazioni-compattate.md` |
| Integrazione Eureka | `docs/gestionale-eureka/` |
| Rilascio | `docs/checklist-rilascio.md`, `docs/deploy-vps.md` |
| Cron e sync sul VPS | `docs/vps-cron-e-sync.md` |
| Roadmap e ticket | `docs/roadmap.md`, `docs/roadmap-tickets.md` |
