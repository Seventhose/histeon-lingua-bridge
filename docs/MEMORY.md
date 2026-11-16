# Memoria di traduzione, aggiornamenti, pagine indipendenti

## Due modi di avere una lingua
| Modo | Quando | Cosa vale |
|---|---|---|
| **Segue l'originale** (copia da tradurre) | Quasi sempre: la stessa pagina nella nuova lingua | Frasi allineate all'originale nell'editor, memoria di traduzione, «da aggiornare» quando l'originale cambia, aggiornamento delle sole frasi cambiate |
| **Indipendente** (pagina vuota o pagina esistente collegata) | Una pagina diversa per quella lingua | Collegata all'originale per selettore di lingua, `hreflang`, menu e copia statica; contenuto tutto suo; mai «da aggiornare»; non insegna nulla alla memoria |

- **Dove si sceglie:** in «Crea traduzione» (copia da tradurre, pagina vuota, una pagina che esiste già) e nel pannello «Lingue» dell'editor a blocchi («Pagina diversa»).
- **Come si cambia:** dall'editor sul sito, con «Rendi indipendente» oppure «Segui l'originale». Tornando a seguire l'originale, la versione attuale dell'originale diventa il punto di partenza.
- **Dove si salva:** nel meta `_lingua_bridge_mode` della traduzione.

## Memoria di traduzione (`includes/class-memory.php`)
- **Cosa contiene:** coppie frase originale → frase tradotta, per lingua, nella tabella `{prefix}lingua_bridge_memory`.
- **Da dove impara:** da ogni salvataggio di una traduzione che segue l'originale, segmento per segmento (lo stesso segmentatore di XLIFF), titolo compreso. Le frasi rimaste identiche non contano.
- **Prima costruzione:** a lotti di 100 traduzioni mentre si usa l'amministrazione. Si ricostruisce da Impostazioni → Avanzate.

Dove si usa:
- **Traduzione nuova:** nasce con le frasi già note, solo dove ci sono ancora le parole dell'originale, e l'editor dice quante.
- **Editor sul sito:** per un testo da tradurre propone «Dalla memoria» con «Usa». «Applica la memoria (N)» le mette tutte in una volta.
- **XLIFF:** senza una traduzione esistente, i target noti escono come `needs-review-translation` (`state-qualifier="tm-suggestion"`).
- **Connettore IA:** `source` restituisce `memory`, cioè le frasi già tradotte del contenuto, da riusare identiche.

## Aggiornamenti delle sole frasi cambiate (`includes/class-updates.php`)
**La fotografia.** Quando una traduzione nasce o torna in pari con l'originale, se ne salva la «fotografia» (`_lingua_bridge_source_snapshot`) e l'impronta (`source_hash`). Succede alla creazione, a «Aggiorna la bozza», a «Segna come aggiornata», al salvataggio dal connettore e all'importazione XLIFF. Prima della 1.4 l'impronta non si aggiornava mai: una traduzione «da aggiornare» restava tale per sempre.

**Il confronto.** Quando l'originale cambia, la fotografia si confronta con l'originale attuale: inizio e fine comuni, poi la sottosequenza comune più lunga sul centro (fino a 400 frasi). Le frasi non abbinate sono nuove o cambiate. Nello stesso tratto si abbinano in ordine alle vecchie, così si vede «prima → ora» e la traduzione che avevano.

Nell'editor sul sito compare un riquadro con le frasi cambiate:
- **Bozza che segue l'originale:** «Aggiorna la bozza» la ricostruisce sulla nuova struttura. Le frasi invariate tengono la traduzione, le altre prendono la memoria o restano nelle parole dell'originale, evidenziate da tradurre.
- **Traduzione pubblicata:** non si ricostruisce mai, perché nessuna frase non tradotta deve andare online. «Vai» porta alla frase da correggere; alla fine «Segna come aggiornata».
- **Traduzione precedente alla 1.4** (senza fotografia): si dice che l'originale è cambiato senza sapere dove; «Segna come aggiornata» dopo la revisione.

Test: `tests/wp/memory-run.php` (memoria, confronto, ricostruzione, pubblicate, indipendenti, XLIFF) e `tests/wp/update.browser.mjs` (tutto il percorso in Chromium).
