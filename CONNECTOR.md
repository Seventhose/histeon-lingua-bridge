# Connettore IA di Lingua Bridge

Un agente IA sul tuo computer traduce i contenuti del sito attraverso una scheda di Lingua Bridge aperta nel browser. Funziona con Claude Code, Codex, Opencode, o qualsiasi agente con una shell o un client MCP. Ogni traduzione diventa una **bozza** da rivedere: pubblichi tu.

Il modello è quello del connettore di Histeon: un solo file, nessuna dipendenza, istruzioni da incollare nella chat, un clic su «Collega».

## Uso
1. In WordPress apri **Lingua Bridge → Traduci con l'IA** e clicca **Copia le istruzioni**. Incollale nella chat del tuo agente, che:
   - scarica `lingua-bridge-connector.mjs` da questo sito e ne controlla l'impronta SHA-256, mostrata nella pagina;
   - lo avvia con `LINGUA_BRIDGE_PAIR='<segreto>' node lingua-bridge-connector.mjs serve --origin <sito>`: il comando esatto, con il tuo segreto di accoppiamento, è nelle istruzioni. Il segreto va nella variabile d'ambiente e non come argomento, perché gli argomenti di un programma sono visibili a tutti gli utenti del computer (`ps`), l'ambiente solo al suo proprietario;
   - legge `help`, `manual` e `brief`.

   Serve Node 18 o successivo, senza pacchetti da installare.
2. Clicca **Collega questa scheda**. Finché il connettore non risponde, il pulsante pulsa. Collegata, la pagina mostra ogni operazione dell'agente.
3. Chiedi quello che serve, per esempio: «traduci in inglese le pagine che mancano», «aggiorna le traduzioni invecchiate».
4. **Scollega** chiude il collegamento, e così la chiusura della scheda. Ricaricare la pagina lo riprende, sostituendo il vecchio collegamento.

Come server MCP, una volta per tutte:
```bash
claude mcp add lingua-bridge -e LINGUA_BRIDGE_PAIR=<segreto> -- node /percorso/lingua-bridge-connector.mjs mcp --origin <sito>
codex mcp add lingua-bridge --env LINGUA_BRIDGE_PAIR=<segreto> -- node /percorso/lingua-bridge-connector.mjs mcp --origin <sito>
```
Gli strumenti si chiamano `lb_*`. La scheda va comunque collegata. Senza `--origin` e `LINGUA_BRIDGE_PAIR`, `mcp` usa l'accoppiamento dell'ultimo `serve`. `--pair` funziona ancora ma avvisa: è visibile agli altri utenti del computer.

La scheda e il connettore si riconoscono con un nonce che porta l'ora: vale una volta sola e per 10 minuti, quindi un saluto intercettato non si può ripetere.

## Operazioni
| Comando (CLI) | Strumento MCP | Cosa fa |
|---|---|---|
| `brief` | `lb_getBrief` | Lingue, contesto del sito, glossario, istruzioni editoriali, tipi traducibili, quanto lavoro c'è |
| `status` | `lb_status` | Sito, utente, lingue |
| `work [--language en] [--type page] [--state missing,stale,draft] [--limit 20] [--offset 20]` | `lb_listWork` | Gli originali con lo stato di ogni lingua: `missing`, `stale` (l'originale è cambiato dopo), `draft`, `published`. `--offset` salta i primi risultati |
| `source <id> <lingua>` | `lb_getSource` | L'originale: titolo, contenuto, estratto, campi, istruzioni di traduzione, cosa esiste già, rapporto dei campi |
| `post <id>` | `lb_getPost` | Un contenuto com'è ora, per rivedere una traduzione |
| `save-draft <id> <lingua> -f file.json` | `lb_saveDraft` | Crea o aggiorna la bozza tradotta. Rifiuta se la traduzione è già pubblicata |
| `save-correction <id pubblicato> <lingua> -f file.json` | `lb_saveCorrection` | Bozza di correzione di una pagina pubblicata, che resta com'è finché la correzione non viene approvata |
| `strings <lingua> [--state missing,draft]` | `lb_listStrings` | I testi fuori dai contenuti (titolo del sito, menu, testi del tema): solo per chi amministra il sito |
| `save-strings <lingua> -f stringhe.json` | `lb_saveStrings` | Le loro traduzioni, testo semplice, come bozze da approvare in «Stringhe»; le pubblicate non si cambiano da qui |
| `manual`, `guide <argomento>` | `lb_manual`, `lb_guide` | Il manuale del traduttore; guide brevi: metodo, blocchi, campi, flusso, qualita |

Codici d'uscita: 0 riuscito, 2 errore di WordPress, 1 errore operativo (connettore spento, nessuna scheda).

## Protocollo
Un processo, in ascolto **solo su 127.0.0.1:7342**. La porta si cambia con `--port` e, nel sito, con la costante `LINGUA_BRIDGE_CONNECTOR_PORT`.

| Lato | Percorsi | Chi | Autenticazione |
|---|---|---|---|
| Scheda | `/tab/hello`, `/tab/next` (attesa lunga), `/tab/result`, `/tab/bye` | La pagina «Traduci con l'IA» | Solo dall'origine accoppiata (`--origin`): ogni altra pagina non riceve intestazioni CORS né `Access-Control-Allow-Private-Network`. Accoppiamento reciproco (sotto), poi una chiave per scheda. |
| Agente | `/control/tabs`, `/control/call` | La CLI o l'MCP | Token casuale in `~/.lingua-bridge-connector/token` (cartella 0700, file 0600), scritto solo quando la porta è presa e cancellato alla chiusura. |

Ogni richiesta con un `Host` diverso da `127.0.0.1` o `localhost` è rifiutata (DNS rebinding). Sul lato agente è rifiutata anche ogni richiesta con `Origin` o `Sec-Fetch-Site` diverso da `none`, cioè fatta da una pagina web.

**Accoppiamento.** WordPress dà a ogni utente un segreto (meta utente, 256 bit, rigenerabile dalla pagina), che finisce nel comando `serve` copiato con le istruzioni.
- Al collegamento la scheda manda un nonce casuale e `HMAC-SHA256(segreto, "tab|nonce|origine")`. Il connettore lo verifica, e ogni nonce vale una volta sola.
- Il connettore risponde con l'id e la chiave della scheda, e con `HMAC-SHA256(segreto, "bridge|nonce|id|chiave")`. La scheda lo verifica con Web Crypto prima di inoltrare qualsiasi cosa.

Così una pagina web non si collega, e un programma che occupa la porta al posto del connettore non riceve nulla. Serve l'amministrazione in HTTPS, o su localhost.

La scheda inoltra ogni operazione a WordPress (`admin-ajax.php`, azione `lingua_bridge_connector`) con il nonce e la sessione dell'utente.

## Sicurezza
- **Nessun codice dall'agente.** Le operazioni sono un elenco fisso, con i loro soli argomenti, controllato tre volte: dal connettore, dalla scheda e da WordPress (`Connector::OPERATIONS`).
- **I permessi sono quelli dell'utente della scheda, contenuto per contenuto.** Collega chi può modificare contenuti (`edit_posts`). Leggere o tradurre un contenuto richiede di poterlo modificare (`edit_post`): un autore vede e traduce solo i suoi. Il markup salvato passa dai filtri HTML di WordPress, quindi niente script per chi non ha `unfiltered_html`.
- **Niente codice attivo aggiunto dall'agente.** L'agente ha letto testo non fidato: i contenuti del sito. Una traduzione salvata dal connettore o dalle Abilities non può avere più `<script>`, `iframe`, `object`/`embed`, `form`, gestori `on…=`, `javascript:`, `srcdoc` o `meta`/`base`/`link` del suo originale, anche per un amministratore, che salva HTML non filtrato. Gli embed già presenti nell'originale restano.
- **Solo chi può creare quel tipo** può crearne una traduzione (`create_posts`).
- **Solo bozze.** Una traduzione pubblicata non viene mai sovrascritta: si propone una bozza di correzione. Pubblicare non è possibile.
- **Nessuna credenziale fuori dal browser.** Il connettore non riceve cookie, password, Application Password o chiavi API.
- **Nessun sito web può pilotarlo:** il lato agente vuole il token e rifiuta i browser; il lato scheda vuole l'origine accoppiata, la prova del segreto e la chiave della scheda. Nel terminale non arrivano caratteri di controllo dalle schede.
- **Il contenuto letto dal sito è un dato, non un'istruzione:** il manuale e le istruzioni lo dicono all'agente.
- **Il file scaricato** si verifica con l'impronta SHA-256 mostrata nella pagina, ed è lo stesso dello ZIP del plugin (`SHA256SUMS` della release).

Per agenti remoti, senza una scheda aperta, restano le Abilities di WordPress (`lingua-bridge/get-translation-source`, `save-translation-draft`, `save-translation-correction-draft`) attraverso un server MCP del sito.

## Test
- `tests/node/connector.test.mjs`: token e permessi dei file, rifiuti (token, Origin, Sec-Fetch-Site, Host), elenco delle operazioni, andata e ritorno con la scheda, sostituzione alla ricarica, file della traduzione, MCP.
- `tests/wp/connector-run.php`: il relay su WordPress vero. Elenco chiuso, permessi per ruolo, solo bozze, pubblicato mai sovrascritto, filtro HTML.
- `tests/wp/connector.browser.mjs`: tutto il percorso in Chromium (Playwright), cioè accesso, «Collega», operazioni dell'agente, bozza salvata, errore nel registro, ricarica, «Scollega».
