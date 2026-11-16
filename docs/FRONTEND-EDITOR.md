# Editor visuale sul sito

Si traduce sulla pagina vera, con il tema vero; ogni lingua resta una pagina WordPress a sé (SEO, indirizzi, copia statica).

## Come si usa
1. Su una pagina del sito, dalla barra di amministrazione: **Traduci questa pagina → English**.
   - Se la traduzione esiste, si apre in **modalità traduzione** (`/?lb_translate=<id>`), anche se è una bozza.
   - Se manca, si crea (schermata «Crea traduzione») e poi si apre lì.
2. Passando sui testi li vedi evidenziati. Clicchi un testo, o un'immagine per il suo `alt`, scrivi la traduzione nel pannello e la vedi al suo posto. Ctrl+Invio salva.
3. **Naviga** sospende la selezione (i link tornano a funzionare). **Pubblica la traduzione** pubblica la bozza, se hai il permesso. **Apri nell'editor** porta all'editor di WordPress.

## Tradurre di seguito
- **L'originale sempre in vista:** per ogni testo il pannello mostra la frase dell'originale. L'allineamento è segmento per segmento, finché la traduzione ha la struttura dell'originale; altrimenti si riconoscono i testi ancora identici. Sotto l'originale compaiono i termini del glossario che contiene, con la resa prevista.
- **I testi da tradurre sono evidenziati** in giallo sulla pagina, e il contatore dice quanti ne restano («5 di 7 testi tradotti»).
- **Prossimo da tradurre** porta al testo seguente e lo apre. **Ctrl+Invio** salva e passa al prossimo. Il campo si apre con le parole dell'originale selezionate, così si scrive sopra.
- **Lascia così** segna come fatto un testo che resta uguale per scelta (un nome, un marchio). Il ricordo resta nella traduzione (`_lingua_bridge_kept`).

## Creare con un clic
- **Dove:** «Traduci questa pagina → lingua» nella barra di amministrazione, «+ Crea e traduci» nella dashboard, «Crea e traduci» nel pannello **Lingue** dell'editor a blocchi.
- **Cosa succede:** la bozza nasce subito (azione con nonce, stessi controlli di «Crea traduzione») e si apre sul sito. Il pannello dice che è una copia dell'originale e cosa non è stato copiato.
- **Se non si può creare:** si apre la schermata «Crea traduzione», che spiega perché.
- **Nell'editor a blocchi** il pannello **Lingue** mostra lo stato di ogni lingua e porta a «Traduci sul sito». La vecchia scatola resta solo per l'editor classico.

## Dove va un testo
| Il testo è… | Si salva… | Chi può |
|---|---|---|
| nel contenuto della traduzione (HTML dei blocchi, o attributi JSON dei blocchi Histeon ed Etch) | nel contenuto: il testo intero, in tutti i punti in cui compare | chi può modificare quella traduzione |
| il titolo della traduzione | nel titolo | idem |
| altrove: tema, footer, menu, plugin | come stringa (`docs/STRINGS.md`), pubblicata: vale su tutte le pagine di quella lingua | chi amministra il sito |

Le regole della sostituzione:
- **Mai una parte di un testo:** un testo più lungo che lo contiene non cambia.
- **Spazi e virgolette:** spazi diversi non contano. Le virgolette e i trattini tipografici che WordPress stampa (’ “ ” – …) si riconoscono anche se nel contenuto sono scritti semplici. Nel JSON valgono gli escape di WordPress (`&`, `<`, `"`…) e `\uXXXX` per le lettere.
- **Solo testo semplice:** la traduzione è senza tag, con l'escape di HTML o JSON dove finisce. L'editor non aggiunge markup a una pagina.
- **Revisioni:** il salvataggio passa da `wp_update_post`, quindi con le revisioni di WordPress e i suoi filtri. La traduzione torna «da revisionare».

## Sicurezza
- La modalità traduzione vale solo per chi può modificare quella traduzione. Per gli altri `lb_translate` non fa nulla.
- Le pagine in modalità traduzione non vanno in cache e non si indicizzano (`nocache_headers`, `X-Robots-Tag: noindex`).
- Salvare e pubblicare passano da `admin-ajax` con nonce legato alla traduzione e permessi controllati di nuovo: `edit_post`, `publish_post`, `manage_options` per le stringhe.
- Il pannello vive in uno shadow DOM e scrive solo testo (`textContent`, `setAttribute`): nulla di ciò che si legge o si digita diventa HTML.

Test: `tests/wp/run.php` (sostituzione: Gutenberg, attributi, JSON Histeon, escape, mai una parte) e `tests/wp/editor.browser.mjs` (tutto il percorso in Chromium).
