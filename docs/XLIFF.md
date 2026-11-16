# XLIFF per traduttori e agenzie

Lingua Bridge → **XLIFF (agenzie)**. È XLIFF 1.2, il formato che leggono Trados, memoQ, Phrase, Smartcat, OmegaT e gli altri programmi di traduzione.

## Esportare
Si sceglie la lingua, il tipo di contenuto e cosa esportare: da tradurre o da aggiornare, oppure tutto, per una revisione. Il file contiene fino a 100 contenuti, quelli che l'utente può modificare, i più recenti. Ogni contenuto è un `<file original="lingua-bridge:post:ID">` con:
- **un segmento per testo:** titolo, estratto, ogni testo visibile dei blocchi (nodi di testo, `alt`, `title`, `placeholder`, `aria-label`, valori di testo del JSON dei blocchi Histeon, Etch e Gutenberg) e ogni campo traducibile;
- **niente codice:** `script`, `style`, `code`, `pre`, `textarea`, `svg`, commenti e le espressioni del builder da sole (`{post.title}`) non diventano segmenti;
- **i target già pieni,** se esiste una traduzione con la stessa struttura: il traduttore aggiorna invece di ripartire da zero;
- **l'impronta dell'originale** (`lb:source-hash`).

## Importare
Si carica il file tradotto. Per ogni contenuto:
- **Struttura:** si ricostruisce la pagina dalla struttura dell'originale con i testi tradotti, con l'escape di HTML, attributi o JSON. Il markup non è mai del traduttore.
- **Dove finisce:**
  - una traduzione assente o in bozza diventa una **bozza**;
  - una traduzione pubblicata riceve una **bozza di correzione**.

  Nulla viene pubblicato.
- **Testi non tradotti:** un segmento senza target resta nella lingua originale e l'esito lo conta.
- **Originale cambiato:** se l'originale è cambiato dopo l'esportazione (impronta diversa), il file non si importa; si esporta di nuovo, e il programma di traduzione ritrova le frasi nella sua memoria.
- **Codice attivo:** un testo non può aggiungere codice attivo (stessa protezione del connettore IA).

## Sicurezza
- **Permessi:** solo chi può modificare i contenuti (`edit_posts` per la pagina, `edit_post` per ogni contenuto, `create_posts` per crearne la traduzione).
- **Il file letto:** al massimo 10 MB; rifiutato se contiene `<!DOCTYPE` o `<!ENTITY`; letto con `LIBXML_NONET`.
- **Le richieste:** esportazione e importazione con nonce. L'esito è salvato per l'utente, mai nell'indirizzo.

Test: `tests/wp/xliff-run.php` (ricostruzione identica, giro completo, rifiuti, permessi) e `tests/wp/xliff.browser.mjs` (scaricare, tradurre, caricare in Chromium).
