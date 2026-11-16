# Stringhe: tutto ciò che non è un contenuto

I contenuti (pagine, articoli, componenti) si traducono come post WordPress, uno per lingua. Una pagina però mostra anche altri testi, e questa parte li porta nella lingua della pagina.

## La lingua di WordPress per lingua (`includes/class-locale.php`)
Una pagina inglese si disegna con la lingua di WordPress dell'inglese, così tema e plugin traducono da sé i loro testi con i pacchetti di traduzione: pulsanti, date, WooCommerce, moduli. Funziona in due momenti:
- **Prima della query**, se l'indirizzo ha il prefisso di lingua: anche i testi caricati presto (quelli del tema) escono nella lingua giusta.
- **Al momento di `wp`**, per ogni pagina tradotta, anche senza prefisso: `switch_to_locale()`.

La lingua di WordPress di ogni lingua si sceglie nelle impostazioni (Lingue). Se resta vuota si usa quella consueta per il codice (`en` → `en_US`, `pt-br` → `pt_BR`…). Le lingue senza pacchetto si installano dalla stessa scheda.

L'amministrazione, REST, il cron, la CLI e la lingua originale tengono la lingua del sito.

## Menu (`includes/class-menus.php`)
Su una pagina in un'altra lingua:
- un link a un contenuto va alla sua traduzione pubblicata, e ne prende il titolo se l'etichetta non è personalizzata;
- un'etichetta personalizzata è una stringa `menu`;
- un link alla home o a un elenco (termine, archivio) va allo stesso elenco nella lingua.

Vale per i menu classici e per il blocco Navigazione. Senza traduzione il link resta com'è.

## La tabella delle stringhe (`includes/class-strings.php`, schermata «Stringhe»)
| Contesto | Cosa | Dove si applica |
|---|---|---|
| `option` | Titolo e motto del sito | `option_blogname`, `option_blogdescription` |
| `menu` | Etichette personalizzate dei menu | Menus |
| `html` | Qualsiasi testo che una pagina mostra così com'è: footer, banner, testi fissi del tema o di un plugin | Nell'HTML della pagina: un nodo di testo intero, o un attributo `alt`, `title`, `placeholder`, `aria-label` intero |

Regole:
- **Solo testi interi:** un testo che contiene la stringa insieme ad altro non cambia. Gli spazi diversi non contano.
- **Mai dentro** `script`, `style`, `textarea`, `code`, `pre` o i commenti HTML.
- **Solo testo semplice:** le traduzioni sono senza tag né parentesi angolari, e hanno sempre l'escape dove finiscono. Una stringa non può aggiungere markup a una pagina.
- **Bozze e pubblicazione:** una stringa si applica solo quando è pubblicata. Scritta nella schermata è pubblicata subito; scritta dall'IA (connettore: `strings`, `save-strings`) resta una bozza finché non la si approva.
- **Chi le traduce:** chi amministra il sito (`manage_options`).
- **Prestazioni:** sulla pagina si carica una mappa per lingua (transient, invalidata a ogni modifica), e il passaggio sull'HTML avviene solo se la lingua ha stringhe pubblicate.

Le stringhe nascono così:
- titolo, motto ed etichette dei menu (classici e blocchi) da sé, alla prima apertura di «Stringhe»;
- gli altri testi a mano, oppure con un clic nell'editor visuale in frontend.

## Da Polylang, WPML e TranslatePress
Importa da plugin → **Stringhe**. Si legge dai dati dell'altro plugin, anche se è già disattivato; i suoi dati non cambiano.

| Sorgente | Dove sono | Stato qui |
|---|---|---|
| Polylang | post `polylang_mo_<lingua>` con `_pll_strings_translations` | pubblicate |
| WPML | `icl_strings` e `icl_string_translations` | complete (stato 10) pubblicate, le altre bozze |
| TranslatePress | `trp_dictionary_<origine>_<lingua>` e `trp_gettext_<lingua>` | riviste da una persona (stato 2) pubblicate, automatiche (1) bozze |

- **Titolo e motto del sito** diventano stringhe `option`, il resto stringhe `html`.
- **Già pubblicate:** una stringa già pubblicata in Lingua Bridge resta com'è.
- **Testi dei contenuti:** quelli di TranslatePress che fanno già parte dei contenuti si saltano, perché si traducono come pagine e appesantirebbero ogni richiesta.
- **Quante:** al massimo 3000 per sorgente a ogni importazione.

Verificato con dati nel formato di ciascun plugin (`tests/wp/string-import-run.php`), non con i plugin installati.

## Static Bridge
Una stringa cambiata vale per tutte le pagine: dopo una modifica rigenera la copia statica completa.
