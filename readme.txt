=== Lingua Bridge ===
Contributors: lingua-bridge
Tags: multilingual, translations, gutenberg, etch, codex, mcp
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 1.14.0
License: GPL-2.0-or-later

Lingua Bridge collega traduzioni come normali contenuti WordPress e mostra lo stato delle versioni linguistiche nell'elenco degli articoli.

== Caratteristiche della versione 1.1.0 ==

* Una tabella indicizzata dedicata collega ogni articolo alla sua lingua e al relativo gruppo di traduzioni.
* Il contenuto resta nel post WordPress e conserva il markup dei blocchi, inclusi i blocchi personalizzati registrati da Etch.
* Aggiunge una colonna Traduzioni agli elenchi dei tipi di contenuto che supportano l'editor.
* Offre una dashboard paginata, una schermata per creare bozze e un riquadro Versioni linguistiche nell'editor.
* Aggiunge l'importatore selezionabile per Polylang, WPML e TranslatePress in Lingua Bridge > Importa da plugin.
* Fornisce il blocco dinamico Gutenberg `Selettore lingua — Lingua Bridge` e lo shortcode `[lingua_bridge_switcher]` come alternativa. Il selettore compare quando esistono almeno due versioni pubblicate e aggiunge `hreflang` alle pagine collegate.
* Duplica titolo, corpo a blocchi, estratto, miniatura, tassonomie e meta pubblici; usa le API ACF compatibili per copiare i campi ACF/SCF quando disponibili.
* Copia i campi JetEngine dei post via API standard dei metadati WordPress, anche quando JetEngine li serve tramite il proprio storage meta. Le chiavi da tradurre con Codex si autorizzano esplicitamente nelle impostazioni.
* Copia i campi nativi Etch assegnati per tipo di contenuto, compreso il riferimento al gruppo. I campi Etch di tipo testo e textarea vengono esposti alle Abilities per la traduzione.
* Espone tre WordPress Abilities su WordPress 6.9+: lettura del contenuto autorizzato, salvataggio di bozze mancanti/già in bozza e creazione di copie di revisione per traduzioni pubblicate.
* Aggiunge un connettore locale per Codex: un pulsante copia il comando `serve`, la scheda WordPress resta in ascolto e cambia stato visivamente quando si collega; le richieste passano dalla scheda autenticata e vengono autorizzate a ogni chiamata.
* Restituisce a Codex un prompt editoriale con direzione linguistica, contesto del sito, glossario e regole per preservare blocchi Gutenberg/Etch e campi. Se contesto e glossario sono vuoti, usa il nome e la descrizione del sito.
* Permette di configurare contesto, glossario e preferenze editoriali per ridurre calchi letterali e incoerenze terminologiche.
* Aggiunge una classe lingua al body; se una pagina è collegata alla homepage statica, nasconde titolo e breadcrumb standard nelle sue traduzioni.
* Offre URL opzionali con prefisso lingua (per esempio `/en/` e `/en/mission-reports/`), homepage tradotta con template front-page WordPress e query pubbliche filtrate per lingua. La funzione è disattivata finché l'amministratore non la attiva.
* Se i componenti Etch sono esposti come contenuti WordPress modificabili, consente di tradurli separatamente e seleziona il componente tradotto pubblicato in base alla lingua della pagina.
* Il salvataggio normale aggiorna solo traduzioni in bozza. Per correggere una traduzione pubblicata, una Ability separata crea una copia di revisione modificabile collegata al contenuto esistente; può anche creare una copia autonoma quando il contenuto pubblicato non ha ancora una relazione linguistica. Il contenuto pubblico non viene mai sovrascritto.
* Disattivando il plugin, i post e i relativi contenuti restano normali record WordPress. La gestione delle relazioni e i badge richiedono il plugin attivo.

== Installazione ==

1. Carica la cartella `lingua-bridge` in `wp-content/plugins/` oppure installa lo ZIP dall'amministrazione WordPress.
2. Attiva Lingua Bridge.
3. Apri Lingua Bridge > Impostazioni e configura codici lingua e lingua predefinita.
4. Dall'elenco Articoli usa la colonna Traduzioni o l'azione Aggiungi traduzione.
5. Il selettore di lingua si vede da solo: dove la pagina non ne ha un altro, il plugin mostra un selettore fluttuante in un angolo. In Impostazioni → Selettore si sceglie dove e come: fluttuante, nel menu del tema, nella testata di Etch («Metti il selettore nella testata», con la copia per tornare indietro), il blocco `Selettore lingua — Lingua Bridge` o lo shortcode `[lingua_bridge_switcher]`, che la stessa schermata scrive con le opzioni scelte. Lì si sceglie anche che cosa succede a un visitatore che legge un'altra lingua: niente, una barra che gliela propone, oppure il passaggio diretto alla prima pagina della visita.
6. Per migrare da Polylang, WPML o TranslatePress, apri Lingua Bridge > Importa da plugin e scegli la sorgente.
7. In Lingua Bridge > Impostazioni configura il contesto editoriale e il glossario. I componenti Etch globali (header/footer) vanno tradotti come componenti separati quando sono disponibili tra i contenuti modificabili; pubblica la variante per attivare lo scambio automatico.

== Migrazione da altri plugin ==

Polylang e WPML: gli importatori usano le API dei plugin per collegare i post e le pagine che esistono già. Mantengono contenuti e stati editoriali; non cancellano i dati del plugin sorgente. Le lingue devono corrispondere a quelle configurate in Lingua Bridge e la lingua predefinita deve coincidere.

TranslatePress: le traduzioni sono stringhe collegate al contenuto originale, non post autonomi. Il convertitore cerca corrispondenze esatte nei titoli, negli estratti e nei blocchi Gutenberg, quindi crea bozze WordPress indipendenti conservando la struttura dei blocchi. Le bozze richiedono revisione: campi personalizzati, slug e frasi composte da stringhe non corrispondenti non vengono convertiti. I dati TranslatePress restano intatti.

Gli importatori lavorano a piccoli lotti e riconoscono le relazioni già presenti. I codici lingua vengono associati per corrispondenza esatta o per codice primario quando non è ambiguo. L'importazione non disattiva il plugin sorgente e non ne elimina i dati. Verifica relazioni e URL prima di disattivarlo; non usare contemporaneamente i due router multilingua sugli stessi percorsi.

== Connector locale per Codex ==

Il connettore locale è documentato in `connector/README.md`. In Lingua Bridge > Impostazioni premi **Collega Codex**: il pulsante copia il comando `npx … serve` e resta in ascolto con animazione; diventa stabile quando la scheda WordPress si collega. La scheda autenticata fa da ponte: Node non riceve credenziali WordPress e il server ascolta solo su loopback. La release 1.1.0 del pacchetto npm deve essere pubblicata prima di distribuire il pulsante aggiornato; il tarball generato è incluso negli artefatti locali.

La sezione avanzata mostra lo stato delle Abilities core e dei provider MCP WordPress tradizionali. Se rileva il WordPress MCP Adapter ufficiale, mostra il suo endpoint e un comando di esempio; rileva anche Invizo. Questi percorsi restano disponibili come alternativa. Il connector locale richiede Node.js 18 o successivo ed è indipendente dall'Abilities API core, così resta utilizzabile anche su WordPress 6.4–6.8.

Nel connector locale WordPress verifica nonce, `manage_options` e `edit_post` per ogni richiesta; le bozze non vengono pubblicate. Le Abilities core `lingua-bridge/get-translation-source`, `lingua-bridge/save-translation-draft` e `lingua-bridge/save-translation-correction-draft` restano disponibili su WordPress 6.9+ per MCP standard. La copia di revisione mostra nell'editor un collegamento al contenuto pubblicato e, quando esiste, alla fonte. Per i contenuti pubblicati senza relazione usa `published_post_id`; per le traduzioni collegate usa `source_post_id` e `language`.

== Campi personalizzati ==

ACF e Secure Custom Fields sono trattati tramite le API ACF compatibili. I campi semplici di primo livello (testo, textarea, WYSIWYG, email e URL) vengono passati a Codex; gruppi, repeater e flexible content sono copiati come strutture e restano editabili nel relativo editor, ma non sono ancora scomposti per tradurre automaticamente ogni cella. I campi JetEngine applicati a post e CPT passano attraverso le API standard dei metadati WordPress; le chiavi testuali da inviare a Codex vanno aggiunte nella relativa lista. Per i campi nativi Etch assegnati a tipi di contenuto, Lingua Bridge copia i valori e il riferimento al gruppo Etch; text e textarea sono inviati a Codex.

Il plugin fornisce gli hook `lingua_bridge_copy_custom_fields`, `lingua_bridge_translatable_fields`, `lingua_bridge_save_translatable_fields` e `lingua_bridge_skip_meta_keys` per adattatori di provider. CCT e relazioni JetEngine, opzioni, gruppi Etch assegnati a singoli ID post e campi non esposti come meta di un post richiedono provider specifici. L'integrazione Etch attuale usa i valori e i riferimenti ai gruppi salvati come post meta dall'editor Etch; non chiama direttamente la sua API JavaScript.

== Limiti noti ==

* I prefissi lingua sono opzionali e richiedono permalink leggibili. Disattivandoli restano gli URL attuali. Se sono attivi, routing, selettore e relazioni richiedono Lingua Bridge attivo; i contenuti restano normali record WordPress e modificabili anche dopo la disattivazione. Con Static Bridge o CDN occorre rigenerare gli output e configurare i redirect server/CDN dei vecchi URL prima della migrazione.
* Le tassonomie copiate sono condivise tra le versioni; la traduzione dei termini va aggiunta separatamente.
* I campi relazionali copiati possono continuare a puntare all'oggetto nella lingua di partenza.
* Le impronte di aggiornamento considerano titolo, contenuto, estratto e campi testuali esposti al plugin.
* Dopo aver creato la prima relazione, la lingua predefinita non si può cambiare e non si possono rimuovere lingue già assegnate.
* I template, lo scambio dei componenti Etch e la regola per la homepage dipendono dalla struttura effettiva del sito. Se un tipo componente Etch non è esposto come contenuto WordPress modificabile, serve un provider dedicato. La verifica su un'installazione Etch reale non fa parte dei test automatici locali.

== Hook per provider di campi ==

Un adattatore può aggiungere campi traduci tramite `lingua_bridge_translatable_fields`, salvarli con `lingua_bridge_save_translatable_fields` e copiare i valori tramite `lingua_bridge_copy_custom_fields`. Le callback ricevono gli ID dei post e devono applicare controlli sui permessi e sulla struttura del valore.

== Changelog ==

= 1.14.0 =
* Fluent Forms parla la lingua della pagina: errori nel browser e del server, messaggio di conferma con il suo markup, redirect alla pagina tradotta ed email al visitatore, anche quando l'email parte in sottofondo. L'email al gestore del sito resta nella lingua dell'originale (filtro `lingua_bridge_fluentform_translate_notification`).
* FluentCRM (gratuito): chi si iscrive da una pagina in una lingua diventa un contatto di quella lingua, e il doppio consenso, le automazioni e le campagne gli arrivano tradotti. I testi delle email sono stringhe del sito.
* XLIFF: gli spazi ai bordi di un segmento tradotto restano (una traduzione può volerne uno che l'originale non aveva, «dell’<a>» → «of <a>»), e quelli dell'originale tornano se il traduttore li ha tolti; mai due spazi dove il contenuto ne ha già uno. Prima le parole si attaccavano ai link e ai grassetti.
* XLIFF e ogni traduzione ricomposta per segmenti: le virgolette nel testo restano virgolette, non `&quot;`, così gli shortcode con attributi (`[modulo id="5"]`) funzionano anche nella traduzione.
* Link interni: un link alla pagina degli articoli (impostata in Lettura) porta alla sua traduzione, anche alle pagine seguenti (`page/2/`); prima restava nella lingua dell'originale.

= 1.13.0 =
* WPGraphQL: ogni tipo traducibile ha il campo `lingua` (codice, originale, gruppo, versioni con id e indirizzo), e le connessioni dei contenuti prendono `where: { lingua: "en" }` o `where: { lingua: "originals" }`, come la REST.
* Le richieste GraphQL vedono tutte le lingue, come la REST: non sono più limitate alla lingua dell'originale.

= 1.12.0 =
* Una valuta per lingua con la multi-valuta di WooPayments (Impostazioni → Avanzate, «Valuta di ogni lingua»): alla prima pagina di una lingua i prezzi passano alla sua valuta, con la funzione di WooPayments; poi vale la scelta del visitatore nel selettore delle valute. Valute, tassi e conversione restano di WooPayments. Filtro `lingua_bridge_language_currency`.

= 1.11.0 =
* x-default a scelta (Impostazioni → URL e SEO): l'originale, una lingua o nessuno, uguale in pagine e sitemap; filtro `lingua_bridge_x_default`.
* Le cache delle pagine seguono le traduzioni: salvato un contenuto, le sue altre versioni si puliscono con WP Rocket e LiteSpeed Cache (e con l'azione `lingua_bridge_purge_urls` per gli altri); una stringa pubblicata pulisce tutto.
* Stringhe del sito in PO: «Scarica PO» e «Aggiungi da PO» nella schermata delle stringhe; dal file arrivano bozze da approvare.
* La memoria propone anche le frasi simili (almeno all'85%), con la percentuale e le parole diverse evidenziate, nell'editor sulla pagina e nell'XLIFF: sempre da rivedere.
* Traduzioni assegnate: il riquadro «Assegnata a», le email a chi la riceve (ogni volta, un riassunto al giorno o nessuna) e «Le mie» nella dashboard.
* Un altro file della libreria per una lingua (immagini con testo, PDF): nelle pagine di quella lingua blocchi, immagini e `srcset` usano quello.
* Ricerca nella copia statica: un indice per lingua che Static Bridge copia, e la ricerca che lo legge dove la copia non ha `?s=`.
* Importazione da Polylang e WPML: dopo i contenuti anche le relazioni dei termini e i menu di ogni lingua, che ora si possono scegliere per lingua e posto del tema (Impostazioni → Selettore, «Menu delle lingue»).

= 1.10.0 =
* «Traduci in sottofondo» (Impostazioni → Traduzione IA, spento di serie): per le lingue e i tipi scelti, un originale pubblicato o cambiato entra nella coda del servizio automatico; la sua bozza nasce o si aggiorna, mai una traduzione pubblicata. Data di taglio, tetto giornaliero e il conto delle bozze da rivedere nella dashboard.
* «Stima parole e costo» fra le azioni di gruppo: parole e caratteri da mandare, senza le frasi che la memoria conosce; il costo quando scrivi il prezzo del tuo servizio per milione di caratteri.
* Google Cloud Translation come terzo servizio, con la tua chiave. DeepL riceve il glossario del sito come glossario vero, creato una volta e poi riusato.
* Registro per lingua (formale o informale): va a DeepL, nel prompt del servizio compatibile con OpenAI e in quello dell'agente.
* I termini si traducono anche con le macchine, ma solo come proposte: «Proponi le traduzioni» (memoria e servizio) e «Scarica XLIFF» sotto l'elenco dei termini, la proposta nei campi del termine, «Accetta le proposte» per crearli.
* Lingue da destra a sinistra (arabo, ebraico, persiano…): `dir="rtl"` e la classe `rtl` del body anche senza il pacchetto di lingua di WordPress.
* Un modulo inviato da una pagina in una lingua (AJAX o REST) riceve la risposta in quella lingua; chi si registra da una pagina in una lingua la trova nel suo profilo, e le email di WordPress gliela usano.
* Fluent Forms: etichette, segnaposto, pulsante e messaggio di conferma sono stringhe del sito; ogni invio ricorda la lingua in cui è partito.
* Complianz: i testi del banner passano dalle stringhe di Lingua Bridge (l'API dei testi di WPML, quando WPML non c'è).

= 1.9.0 =
* Impostazioni → Avanzate, «I campi nelle traduzioni»: per ogni campo personalizzato si sceglie traduci, copia (anche quando l'originale cambia), copia una volta o ignora.
* «Uguale all'originale» (spento di serie): immagine in evidenza, data, pagina genitore, template, ordine, commenti e termini passano dall'originale alle traduzioni quando lo salvi, anche a quelle pubblicate; nessun testo cambia.
* ACF e Secure Custom Fields: i testi dentro ripetitori, flexible content e gruppi si traducono uno per uno e tornano nelle stesse righe; le pagine opzioni hanno una versione per lingua (con l'originale finché non la salvi).
* Le parti dei template dei temi a blocchi (testata, piè di pagina) salvate nell'editor del sito si traducono: sulle pagine di una lingua compare la sua.
* Basi degli indirizzi tradotte (Impostazioni → URL e SEO): `/fr/categorie/…` invece di `/fr/category/…`, con il 301 dalla base dell'originale.

= 1.8.2 =
* Meta SEO tradotti: i titoli e le descrizioni (anche social) di Yoast, Rank Math, SEOPress e Slim SEO passano alla traduzione, li vedono agente, XLIFF e servizio automatico, e nell'editor sulla pagina c'è il riquadro «Meta della pagina». Le variabili dei plugin (`%%title%%`, `%sep%`) restano quelle dell'originale; l'indirizzo canonico non si copia più nella traduzione.
* Sitemap dei plugin SEO: Rank Math, Yoast e Slim SEO hanno le versioni di ogni contenuto (`xhtml:link`) e `x-default`, come la sitemap di WordPress.
* WooCommerce: un ordine ricorda la lingua in cui è stato fatto, e le sue email partono in quella lingua anche quando il negozio le manda dopo, dall'amministrazione (rimborso, spedizione, nota); senza lingua salvata, quella del profilo del cliente.
* WooCommerce: gli attributi globali (`pa_colore`) si traducono come categorie e tag, e le varianti dei prodotti tradotti usano i termini tradotti.

= 1.8.1 =
* Static Bridge 2.2.15 e successive: i reindirizzamenti di Lingua Bridge (il vecchio slug di una traduzione, la categoria dell'originale sotto la cartella di una lingua, la categoria tradotta alla radice, la home di una lingua non ancora tradotta) diventano regole del server, quindi valgono anche in modalità Strict, dove prima davano 404.
* Con un dominio per lingua la copia statica è quella del sito principale: gli indirizzi dei domini delle lingue non entrano nell'export e Static Bridge non serve la copia su quei domini, dove risponde WordPress.
* Le sitemap restano di WordPress anche con Static Bridge in Strict, con le versioni nelle altre lingue: una prova lo controlla.

= 1.8.0 =
* Il selettore di lingua si vede da solo: dove la pagina non ha un altro selettore, compare un selettore fluttuante in un angolo (in basso a destra di serie), con i colori del tema, almeno 44 px da toccare e niente JavaScript, anche nelle copie statiche. Sparisce dove c'è già il selettore nel menu, il blocco, lo shortcode o il componente Etch. Si spegne in un clic.
* Impostazioni → Selettore: dove si è visto il selettore, il fluttuante con l'anteprima, il menu del tema, lo shortcode scritto con le opzioni scelte e, su Etch, «Metti il selettore nella testata», che mette il blocco nel componente della testata e nelle sue versioni tradotte, dopo una copia che si ripristina con un clic. La configurazione guidata propone le tre strade.
* La lingua del visitatore: niente, una barra che propone la sua lingua (di serie) oppure il passaggio diretto, alla prima pagina della visita, dall'originale alla versione nella lingua del browser. Avviene nel browser leggendo gli hreflang: funziona con le cache e nelle copie statiche, mai per i motori di ricerca, e la scelta fatta col selettore vince sempre, senza cookie.
* hreflang anche sulla home blog, sugli archivi dei tipi, sugli autori, sulle date e sui termini non tradotti, solo per le lingue che hanno contenuti in quell'elenco, con x-default; gli stessi nella sitemap `lingua` (xhtml:link). Il selettore di un elenco non porta più a un elenco vuoto.
* Corretto: sulla home tradotta (`/en/`) l'hreflang e il selettore dell'originale portavano allo slug della pagina iniziale (`/casa/`) invece che alla radice.
* Prove nuove: accessibilità di ogni forma del selettore (axe e tastiera) e una prova completa con Static Bridge, dall'export alla copia servita.

= 1.7.1 =
* Domini delle lingue (Impostazioni → URL, con i prefissi accesi): una lingua può avere un dominio suo, `example.fr` o `fr.example.com`, al posto del prefisso. Lì i percorsi sono quelli del sito principale e la radice è la home tradotta; permalink, elenchi, selettori e hreflang usano il dominio; `/fr/…` porta al dominio con un 301. Amministrazione e accesso restano sul dominio principale.

= 1.7.0 =
* Il ruolo «Traduttore»: un amministratore gli dà le lingue nel profilo dell'utente. Il traduttore legge gli originali pubblicati, crea e modifica le bozze delle sue lingue (dalla dashboard, dall'editor, dall'XLIFF o con un agente) e le manda in revisione; non pubblica, non modifica gli originali né le traduzioni pubblicate o di altre lingue. La dashboard e l'elenco del lavoro gli mostrano solo le sue lingue.

= 1.6.9 =
* Glossario in un file (Impostazioni → Traduzione IA): «Scarica CSV» e «Aggiungi da CSV o TBX». Il CSV si apre e si salva con un foglio di calcolo (virgola o punto e virgola); dal TBX si prendono i termini della lingua dell'originale verso le altre lingue del sito.
* XLIFF più comodo: «Tutte le lingue» scarica uno ZIP con un file per lingua; oltre 100 contenuti, più file da 100 (fino a 300 per lingua); il glossario del sito va nel file come nota per il traduttore; al ritorno il riepilogo conta anche i testi lasciati uguali all'originale.
* Connettore: `listWork` (e `work --offset`) salta i primi risultati.

= 1.6.8 =
* Sitemap: per ogni contenuto con altre versioni pubblicate, un `xhtml:link rel="alternate"` per lingua e `x-default`, come nell'`<head>` della pagina. Utile soprattutto per le copie statiche.
* La lingua del browser (Impostazioni → URL, spenta di serie): una piccola barra in fondo alla pagina propone la versione nella lingua del browser del visitatore, se c'è. Nessun reindirizzamento; chiusa una volta non torna; funziona anche nelle copie statiche.

= 1.6.7 =
* Il selettore di lingua ha le sue opzioni, nel blocco (barra laterale) e nello shortcode: elenco o tendina (`style="dropdown"`, senza script), nome, codice o tutti e due (`label="code"`, `label="both"`), `hide_current="yes"` per nascondere la lingua corrente, `show_missing="yes"` per mostrare anche le lingue senza traduzione, che portano alla loro home.
* Sulle pagine di elenco (archivi, categorie, ricerca, blog) il selettore e le lingue nel menu portano allo stesso elenco nelle altre lingue, non più alle home: da `/fr/category/…` si torna all'italiano.

= 1.6.6 =
* Sicurezza: una traduzione non aggiunge shortcode che il sito esegue né espressioni di Etch e Histeon (`{this.title}`) nuove o diverse da quelle dell'originale, da qualunque via arrivi (agente, XLIFF, servizio automatico, memoria). Gli attributi degli shortcode restano traducibili.
* Le incoerenze note chiuse insieme: cancellare una traduzione non rimette «in pari» le sorelle da aggiornare; un «\» nei campi tradotti resta; salvare le lingue a righe tiene i locale di WordPress; il passo del contesto della configurazione non svuota il glossario; una bozza nuova che non si salva non resta con il testo dell'originale; un avviso sconosciuto nell'indirizzo non dice nulla; le voci di menu con un apostrofo prendono il titolo della traduzione; nessun XLIFF vuoto o verso la lingua dell'originale.
* Impostazioni: i testi della traduzione IA parlano del «tuo agente», non solo di Codex; il glossario ha il pulsante «Aggiungi riga».
* Creare molte bozze: le tassonomie vuote non si riscrivono; le azioni di gruppo con più di 10 contenuti vanno già in coda.

= 1.6.5 =
* I file media in ogni lingua: nella schermata di un file, testo alternativo e didascalia per ogni lingua di traduzione. Le immagini stampate dalla libreria nelle pagine di quella lingua li usano (il file resta uno solo), anche nelle pagine Etch per il testo alternativo; un testo alternativo scritto nella pagina resta quello della traduzione della pagina.

= 1.6.4 =
* La memoria di traduzione si vede e si corregge: la schermata «Memoria» cerca fra le frasi di ogni lingua, corregge una traduzione, dimentica una frase, scarica e aggiunge frasi in TMX.
* Ogni frase della memoria sa chi l'ha scritta (una persona, l'agente IA, il servizio automatico), e la frase di una persona non viene più sostituita da quella di una macchina.

= 1.6.3 =
* Comandi WP-CLI: `wp lingua status`, `create`, `xliff export|import`, `link`, `strings`, `strings-scan`, `memory rebuild` e `queue`. Usano le stesse funzioni e gli stessi controlli dell'amministrazione, girano come l'utente indicato con `--user` e scrivono solo bozze.

= 1.6.2 =
* La lingua nella REST API: ogni tipo traducibile ha il campo `lingua` (la lingua, il gruppo e le versioni che chi legge può vedere) e i suoi elenchi accettano `?lingua=fr` o `?lingua=originals`. Per temi headless, app e agenti.

= 1.6.1 =
* Lo stesso slug in più lingue: la traduzione di «Newsletter» può essere /fr/newsletter/ invece di /fr/newsletter-2/. WordPress numera uno slug solo quando lo usa un altro contenuto della stessa lingua; l'indirizzo trova sempre la pagina della lingua chiesta, e /newsletter/ resta l'originale.
* Una pagina tradotta che cambia slug tiene il vecchio indirizzo, che porta a quello nuovo (301), come WordPress fa già per gli articoli.

= 1.6.0 =
* Categorie, tag e termini delle altre tassonomie pubbliche si traducono: nella schermata di un termine, per ogni lingua, nome e slug della versione tradotta. Il termine tradotto è un termine WordPress normale; le traduzioni dei contenuti in quella lingua lo ricevono al posto dell'originale (subito, e quelle create dopo); il suo archivio sta nella cartella della lingua (/fr/category/actualites/), con hreflang e selettore verso le altre versioni. L'archivio del termine originale sotto /fr/ porta alla sua traduzione.
* Menu e link interni delle pagine tradotte portano al termine tradotto; la colonna «Lingue» negli elenchi dei termini dice quali versioni ci sono.
* I termini li traduce una persona: non hanno bozze, quindi agenti, XLIFF e servizio automatico non li creano.

= 1.5.14 =
* Configurazione guidata: la lingua originale proposta è quella dei contenuti (stimata dalle parole degli ultimi 20), non quella di WordPress; una conferma ricorda che dopo la prima traduzione non si cambia più, e l'originale non è mai spuntato fra le traduzioni.
* Configurazione guidata: le 67 lingue si cercano per nome, nome nella tua lingua o codice, con le più usate in cima e le scelte come etichette.
* Le lingue: l'originale è sempre la prima (anche nei selettori), le altre si riordinano con le frecce nelle impostazioni.
* Cruscotto a matrice: una colonna per lingua, l'originale per prima; una bozza ancora quasi tutta nella lingua dell'originale dice «Da tradurre»; le azioni di gruppo valgono anche per «Tutte le lingue». Il riepilogo conta tutti i contenuti, non gli ultimi 300.
* Elenchi di WordPress (Articoli, Pagine…): il filtro «Lingua» (tutte, solo gli originali, una lingua) e una colonna compatta con un pallino per lo stato di ogni lingua.
* Prestazioni: il cruscotto e gli elenchi leggono relazioni e traduzioni di tutta la pagina in poche query (da circa 320 a circa 70 su una pagina di 20 righe).

= 1.5.13 =
* Agente IA «a segmenti»: con `segments` e `save-segments` (Abilities `get-translation-segments` e `save-translation-segments`) l'agente riceve solo i testi da tradurre, numerati, e WordPress ricostruisce il markup dall'originale. Su una pagina Etch è da 5 a 9 volte meno testo, e il markup non si può rompere. Connettore 3.1.0; il modo con il markup intero resta.
* Traduzione automatica di molti contenuti in coda: l'azione di gruppo finisce subito, WP-Cron e il cruscotto aperto lavorano tre contenuti alla volta, con l'avanzamento, gli errori per contenuto e «Riprova». Anche le bozze di più di 10 contenuti vanno in coda.
* Servizio compatibile OpenAI: attesa massima regolabile (fino a 600 secondi, per un modello locale lento) e parametri aggiuntivi in JSON (per esempio per spegnere il ragionamento), con un elenco chiuso di chiavi. Una chiamata che fallisce non consuma più il budget orario.
* Dopo un salvataggio dell'agente, dell'XLIFF o del servizio automatico, ciò che è cambiato oltre ai testi (blocchi, attributi, link verso altri siti) si vede nel pannello «Lingue», nella metabox e nell'editor sulla pagina, da controllare prima di pubblicare.
* Il messaggio delle azioni di gruppo conta anche i contenuti non riusciti.

= 1.5.12 =
* I link interni delle pagine tradotte portano alla loro lingua: menu, pulsanti e link dei contenuti, anche nella testata e nel piè di pagina di Etch, vanno alla versione pubblicata nella lingua della pagina; categorie, autori e archivi prendono la cartella della lingua. Si spegne in Impostazioni → URL e SEO («Link interni»).
* Quando la homepage è il blog, /en/, /fr/… sono l'elenco degli articoli di quella lingua invece di un 404. Con una homepage statica non ancora tradotta, /xx/ porta alla homepage dell'originale.
* La homepage e gli elenchi dell'originale dicono la lingua dell'originale (`lang`), anche quando WordPress ha un'altra lingua; le impostazioni lo segnalano e suggeriscono di allinearla.
* Stringhe: «Trova i testi da tradurre» confronta le pagine principali di una lingua con l'originale e aggiunge da sé i testi del tema, della testata e del piè di pagina rimasti nella lingua dell'originale. Con il servizio automatico configurato, un clic ne scrive le bozze da approvare.
* Selettore di lingua: la configurazione guidata sceglie il blocco Navigazione solo se il tema lo usa davvero (Etch disegna il menu con un componente) e controlla la homepage: se il selettore non c'è, dice come metterlo. Il promemoria del cruscotto segna il passo come fatto solo quando il selettore compare su una pagina.
* Editor: se WordPress ha aggiunto un numero allo slug di una traduzione (newsletter-2), il pannello «Lingue» lo spiega.
* Static Bridge: gli indirizzi passati per le copie statiche sono codificati (un autore con uno spazio nel nome) e una homepage statica non tradotta non viene più esportata come home della lingua.

= 1.5.11 =
* Sicurezza: un link `javascript:` nascosto con un tab, un a capo o un riferimento senza «;» (`jav&#9;ascript:`, `&#106avascript:`) non passa più il controllo del codice attivo, neanche dentro un elemento Etch.
* Sicurezza: un originale reso privato o protetto da password dopo la pubblicazione delle traduzioni rende subito private o protette anche le traduzioni pubblicate, che spariscono dal sito e dalla sitemap. Le copie di correzione conservano la password della pagina che correggono.
* Sicurezza: copie di correzione solo per chi può creare contenuti di quel tipo; il collegamento da Histeon Publish vuole i permessi su entrambi i contenuti; un file XLIFF in UTF-16 è rifiutato prima di leggerlo; l'editor sulla pagina non si può incorniciare in un altro sito; le versioni che non puoi modificare non mostrano stato né link.
* Dopo una disattivazione e una riattivazione le pagine tradotte non danno più 404 fino al primo salvataggio delle impostazioni.
* Etch: i componenti tradotti (testata, piè di pagina, sezioni riusate) compaiono nelle pagine della loro lingua anche con Etch 1.6 e successivi.
* Etch: un modulo, un SVG o un iframe non rendono più intraducibile ciò che contengono; se una traduzione cambia davvero codice attivo, il messaggio dice quale elemento.
* Etch: i nomi dei livelli dell'editor non sono più testi da tradurre (XLIFF, editor sulla pagina, traduzione automatica); `aria-label`, `title`, `placeholder` e i testi dei pulsanti sì.
* Editor sulla pagina: «Pubblica» funziona anche sulle traduzioni dei componenti, e un testo di un componente tradotto si salva nel componente, non come testo di tutto il sito.
* XLIFF: i testi di una bozza ancora uguali all'originale escono da tradurre, quelli riempiti dalla memoria da rivedere.
* «Traduci con l'IA»: il comando per Windows PowerShell ha di nuovo `$env:LINGUA_BRIDGE_PAIR`.
* Prestazioni: le impostazioni si caricano con le altre opzioni di WordPress, senza una query in più per pagina.

= 1.5.10 =
* Static Bridge: quando una pagina cambia, l'aggiornamento incrementale riscrive anche le copie statiche delle sue traduzioni pubblicate (filtro `static_bridge_translated_urls`, con Static Bridge 2.2.11). Solo contenuti pubblici, solo indirizzi del sito, al massimo 32 (docs/STATIC-BRIDGE.md).
* Static Bridge 2.2: gli elenchi di ogni lingua (/en/category/…, /en/eventi/) entrano nell'export completo con il filtro `static_bridge_export_urls`. Static Bridge 2.2 non legge più le sitemap, quindi finora dipendevano dai link interni.

= 1.5.9 =
* Rafforzamenti dall'audit di sicurezza del 3 novembre 2026 (note 21, 22, 25, 26, 33).
* I campi pubblici e i campi Etch copiati in una traduzione conservano le barre rovesciate (`\`).
* Con `DISALLOW_FILE_MODS` il router sempre attivo non scrive più in wp-content/mu-plugins: le impostazioni mostrano il codice da copiare a mano.
* Un componente Etch è sostituito dalla sua traduzione solo se anche questa è un componente (wp_block) pubblicato.
* Una sola regola per i link tra le lingue (hreflang, selettori, menu): pubblicato, non un file media, e una variazione solo se il suo prodotto è pubblicato.
* WooCommerce: i dati interni di Histeon (meta `_xb_`) non vengono copiati nelle traduzioni dei prodotti.

= 1.5.8 =
* Aggiornamenti firmati: il plugin si aggiorna dalle release pubbliche su GitHub, come ogni plugin dall'amministrazione, e installa solo pacchetti firmati con la chiave di rilascio (docs/UPDATES.md). Acceso di serie per il plugin autonomo; `LINGUA_BRIDGE_UPDATES` a false in wp-config.php lo spegne. Una sola richiesta anonima a GitHub al massimo ogni 12 ore.

= 1.5.7 =
* Il builder visuale Histos si chiama ora Histeon: messaggi, avvisi e documentazione usano il nuovo nome. Il funzionamento non cambia.

= 1.5.6 =
* Il plugin non contiene più il contesto e il glossario del sito per cui era nato: dalla 1.2 sono impostazioni di quel sito, che le ha già. Un sito con «Collegi» nel nome non riceve più per errore quel glossario.

= 1.5.5 =
* Sicurezza: una traduzione non può più aggiungere o cambiare un elemento attivo scritto come blocco. Histeon salva script, stili e iframe come blocchi xb/element, Etch tiene lo script di un elemento negli attributi del blocco: il controllo del codice attivo ora li confronta interi, come già faceva con l'HTML, per i salvataggi dall'editor, dal connettore, dall'XLIFF e dalla traduzione automatica (audit di sicurezza di Histeon del 3 novembre 2026).

= 1.5.4 =
* Traduzioni dei blocchi Histeon: un testo con grassetto, corsivo o collegamenti (il testo di un riquadro, una didascalia, una risposta) li tiene anche tradotto. Restano solo questi formati e i collegamenti sicuri; il resto si toglie.

= 1.5.3 =
* L'esportazione XLIFF e gli avvisi sui testi non tradotti vedono i testi dei componenti Histeon, compresi i blocchi per chi scrive.

= 1.5.2 =
* Da Histeon Publish: i contenuti tradotti inviati a WordPress arrivano già collegati all'originale (azione `xb_remote_language_link`).

= 1.5.1 =
* Selettore di lingua per Etch: le etichette lette dai lettori di schermo («Lingua: …», «Lingue disponibili») seguono la lingua della pagina, in italiano sulle pagine italiane e in inglese sulle altre.

= 1.5.0 =
* Interfaccia anche in inglese: Lingua Bridge resta scritto in italiano e, per ogni altra lingua senza traduzione propria, carica l'inglese (languages/lingua-bridge-en_US, .mo e JSON degli script). Testi ancora fissi resi traducibili (pannello Codex/MCP, nota nei file XLIFF, importatore).
* L'editor sulla pagina parla la lingua del profilo di chi traduce, non quella della pagina, anche senza i pacchetti di lingua di WordPress.
* Le istruzioni ai modelli di traduzione restano in italiano, come il resto delle istruzioni.
* Test: i controlli in WordPress girano in italiano; il sito di prova resta en_US per il routing.

= 1.4.1 =
* Histeon: le traduzioni da fare (da tradurre, da aggiornare, in bozza) compaiono in Histeon → Panoramica, «Stato del sito», con il link alla dashboard.
* Test: il cruscotto si prova anche su un sito appena installato.
* Integrazione continua su GitHub: controlli PHP da 7.4 a 8.5, test Node, WordPress vero con il browser, scansione dei segreti.

= 1.4.0 =
* Editor sul sito: originale sempre visibile con i termini del glossario, testi da tradurre evidenziati e contati, «Prossimo da tradurre», Ctrl+Invio salva e prosegue, «Lascia così».
* Dashboard come lista di cose da fare: filtri per lingua e stato, percentuale tradotta, azioni di gruppo (bozze, XLIFF, traduzione automatica), lista «Per iniziare».
* Selettore di lingua nel menu senza blocchi né shortcode, anche dalla configurazione guidata (docs/SWITCHER.md).
* Traduzione automatica con la tua chiave (DeepL o compatibile OpenAI, anche modelli locali), cifrata, solo bozze (docs/MACHINE-TRANSLATION.md).
* Memoria di traduzione: le frasi già tradotte tornano nelle traduzioni nuove, nell'editor («Usa», «Applica la memoria»), in XLIFF e nel connettore.
* Aggiornamenti: quando l'originale cambia si vedono solo le frasi nuove o cambiate; «Aggiorna la bozza» ricostruisce la traduzione tenendo il resto; «Segna come aggiornata». L'impronta dell'originale ora si aggiorna.
* Pagine indipendenti, come con Polylang o WPML: pagina vuota o pagina esistente collegata, mai «da aggiornare»; si passa da un modo all'altro dall'editor (docs/MEMORY.md).
* Creazione con un clic (barra di amministrazione, dashboard, pannello «Lingue» dell'editor a blocchi): la bozza si apre subito sul sito.
* Nuova grafica: un solo sistema di stile per tutte le schermate (con lo schema colori dell'amministrazione), intestazione con navigazione, una scheda per lingua con l'avanzamento, «Per iniziare» a passi, «Crea traduzione» con la scelta del contenuto; anche il pannello dell'editor sul sito.
* Sicurezza, due revisioni: tipi di contenuto privati (invii dei moduli, ordini) mai traducibili; l'originale e le pagine indipendenti mai sovrascritti da agenti, XLIFF o Abilities; codice attivo confrontato frammento per frammento dopo aver decodificato entità e JSON; una traduzione tiene la protezione dell'originale (privata, password); memoria solo da traduzioni pubblicate da utenti fidati; traduzione automatica solo su bozze, con budget atomico per utente e per sito e richieste solo verso indirizzi pubblici; la lingua solo dal percorso dell'URL (niente avvelenamento della cache); scorte WooCommerce non modificabili da chi edita solo una traduzione.
* Connettore 3.0.0: il segreto di accoppiamento si passa nella variabile LINGUA_BRIDGE_PAIR (non più come argomento, visibile agli altri utenti del computer) e il saluto porta l'ora (vale una volta, per 10 minuti). Un connettore 2.x non si collega alla 1.4.0: ricopia le istruzioni da «Traduci con l'IA».

= 1.3.0 =
* Impostazioni in schede (Lingue, URL e SEO, Traduzione IA, Avanzate), lingue scelte da un elenco, glossario in tabella, configurazione guidata in tre passi.
* Lingua di WordPress per lingua: tema e plugin si traducono con i loro pacchetti; installazione dei pacchetti mancanti dalle impostazioni.
* Menu nella lingua della pagina (classici e blocco Navigazione): link alle traduzioni, etichette tradotte.
* Editor visuale sul sito: «Traduci questa pagina» dalla barra di amministrazione, clic su un testo, traduzione al suo posto; nel contenuto (Gutenberg, Histeon, Etch) o come stringa del sito; pubblicazione dal pannello (docs/FRONTEND-EDITOR.md).
* Importazione delle stringhe da Polylang, WPML e TranslatePress (anche a plugin disattivato): titolo del sito, widget, testi del tema.
* XLIFF 1.2 per agenzie: esportazione a segmenti di testo, importazione come bozze con la struttura dell'originale (docs/XLIFF.md).
* WooCommerce: dati e varianti nelle traduzioni dei prodotti, prezzi e scorte uguali in tutte le lingue, negozio/carrello/cassa nella lingua della pagina (docs/WOOCOMMERCE.md).
* «Stringhe»: titolo e motto del sito, etichette dei menu e qualsiasi testo fisso delle pagine, tradotti per lingua; dall'IA come bozze da approvare (docs/STRINGS.md).

= 1.2.0 =
* Corretto: la homepage /en/ reindirizzava alla homepage della lingua predefinita.
* Sicurezza (revisione 1.2): connettore accoppiato al sito con un segreto per utente e prove HMAC nei due sensi (nessuna pagina web né programma sulla porta può fingersi scheda o connettore); una traduzione dell'agente non può aggiungere codice attivo che l'originale non ha, anche per gli amministratori; per tradurre serve poter creare quel tipo di contenuto; dashboard senza righe non modificabili; sitemap delle lingue in cache; testi dell'originale a Histeon solo a chi può modificarlo; in multisite il router lo scrive solo un super admin.
* Traduci con l'IA: un agente sul tuo computer (Claude Code, Codex, Opencode, qualsiasi MCP) traduce attraverso una scheda collegata, come il connettore di Histeon. Un solo file da scaricare dal sito, senza npm né dipendenze; operazioni fisse (lavoro da fare, sorgente, bozza, bozza di correzione) controllate da connettore, scheda e WordPress; solo bozze; manuale per l'agente (CONNECTOR.md). Sostituisce il vecchio connettore Codex basato su WebSocket e su un pacchetto npm mai pubblicato.
* Histeon: Lingua Bridge è il motore multilingua del builder (componenti tradotti, tela nella lingua del documento, data.lingua, barra delle lingue; docs/HISTOS.md).
* Static Bridge: cambiando un contenuto tradotto si rigenerano anche gli elenchi della sua lingua (/en/, /en/category/…); la sitemap "lingua" nomina gli archivi di ogni lingua per l'export completo e i motori di ricerca (docs/STATIC-BRIDGE.md).
* Corretto: i link alle traduzioni uscivano senza barra finale, con un reindirizzamento in più per ogni link.
* Corretto: alla radice archivi, ricerca, feed e loop mostravano anche le altre lingue; /en/?s= apriva la homepage invece della ricerca; un contenuto non tradotto sotto /en/ portava all'originale invece di dare 404.
* Router sempre attivo, facoltativo: mantiene /en/, link tradotti, lang e hreflang anche con il plugin disattivato.
* "Crea traduzione" dice prima cosa la bozza tradurrà, cosa copierà soltanto e cosa non porta con sé (ACF/SCF, Etch, meta, JetEngine).
* Gli elementi nascosti sulla homepage tradotta sono un'impostazione (i siti della 1.1 mantengono i loro).
* Connettore: comandi locali con token di sessione (file 0600), niente richieste da pagine web né da host diversi da 127.0.0.1.
* Messaggi d'errore dell'amministrazione non più presi dall'URL; nessuna query per lo schema a ogni visita; pacchetto ZIP da elenco di file ammessi.
* Nuovi test su WordPress vero (dev/, tests/wp).

= 1.1.0 =
* Aggiunti i prefissi lingua opzionali e la homepage tradotta con template WordPress front-page.
* Filtrati gli archivi di contenuti pubblici per lingua senza nascondere menu, template globali e media.
* Rimossi i rewrite Lingua Bridge prima del flush in disattivazione.
* Aggiunti test per impostazioni, routing, homepage, query di lingua e disattivazione.

= 1.0.1 =
* Aggiunta una bozza di correzione separata per le traduzioni pubblicate; non cambia il contenuto live né la relazione linguistica.
* Il salvataggio ripetuto aggiorna la stessa copia di revisione e l'editor mostra i collegamenti alla fonte e alla traduzione pubblicata.
* Il connector locale espone save-correction-draft per copie di revisione collegate o autonome, con le stesse verifiche di sessione e autorizzazione delle altre operazioni.
* Aggiunti un contesto editoriale e un glossario italiano-inglese predefiniti, per il sito per cui il plugin era nato, quando le impostazioni personalizzate sono vuote.

= 1.0.0 =
* Prima release stabile del modello di traduzioni come normali post, pagine e CPT editabili.
* Verifica lo schema DB prima di segnare completata l'installazione e sospende le funzioni se la tabella non è disponibile.
* Protegge le bozze validate da input incompleti, non sovrascrive contenuti con stato diverso da draft e mantiene i gruppi quando viene eliminato il contenuto radice.
* Supporta tag lingua BCP-47 come zh-Hans ed es-419 e collega le pagine figlie al genitore tradotto quando disponibile.
* Riprende l'importazione dalla sessione browser, mostra gli errori restituiti dal server e ripara i riferimenti provider durante reimport idempotenti.
* Aggiunge test di regressione PHP e test d'integrazione loopback del connector.

= 0.4.1 =
* Sostituito il pairing manuale con un connector locale CLI in stile Etch: comando copiabile, scheda in ascolto e pulsante con stati scollegato, in ascolto e collegato.
* Aggiunti i comandi locali limitati per leggere la sorgente e salvare una bozza tradotta.

= 0.4.0 =
* Aggiunto il prototipo locale di collegamento Codex e il relay WordPress autorizzato.

= 0.3.1 =
* Aggiunta in Impostazioni la sezione di collegamento MCP a Codex per la creazione di bozze di traduzione.
* Esposizione esplicita delle Abilities di traduzione al server MCP WordPress.
