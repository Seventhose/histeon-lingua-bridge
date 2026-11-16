# Lingua Bridge e Static Bridge

Static Bridge tiene una copia statica del sito. Con i prefissi di lingua (`/en/…`) le pagine singole hanno già la lingua nel permalink. Gli **elenchi** invece esistono una volta per lingua solo sotto il prefisso: home, archivi dei tipi, categorie, autori. Prima di questa integrazione nessuno li nominava, quindi:

| Caso | Prima | Ora |
|---|---|---|
| Cambia un articolo inglese | rigenerati solo gli elenchi italiani (`/`, `/category/news/`), quelli inglesi restavano vecchi | rigenerati anche `/en/`, `/en/category/news/`, `/en/eventi/`, `/en/author/…` |
| Export completo | gli archivi inglesi entravano solo se un link interno li raggiungeva | la sitemap `lingua` li nomina tutti |
| Export completo con Static Bridge 2.2 (1.5.10) | Static Bridge 2.2 non legge più le sitemap: gli archivi inglesi tornavano a dipendere dai link interni | il filtro `static_bridge_export_urls` li aggiunge all'inventario |
| Cambia una pagina tradotta (1.5.10, con Static Bridge 2.2.11) | riscritta solo la sua copia; le traduzioni, il cui selettore e hreflang la nominano, restavano vecchie | riscritte anche le copie delle sue traduzioni pubblicate |

## Come
`includes/class-archives.php`, attivo solo con i prefissi di lingua.
- **Rigenerazione incrementale:** il filtro `static_bridge_archive_urls_for_post` di Static Bridge (dalla 2.0.25) **aggiunge** gli elenchi nella lingua dell'articolo. Non può togliere quelli che Static Bridge sceglie da sé. Static Bridge scarta gli URL di altri siti e quelli con query, e ne accetta al massimo 100.
- **Traduzioni negli incrementali (1.5.10):** al filtro `static_bridge_translated_urls( string[] $urls, string $url )` di Static Bridge (2.2.9) Lingua Bridge risponde, per ogni pagina che Static Bridge mette in coda, con i permalink delle traduzioni pubblicate di quel contenuto. Solo se `$url` è il permalink di un contenuto pubblico (pubblicato, senza password; anche la home tradotta su `/en/`) e solo traduzioni pubbliche: un elenco, una pagina `/page/N/`, un contenuto ritirato o eliminato non ricevono nulla, così una rimozione non si porta dietro una traduzione ancora pubblicata. Solo indirizzi dello stesso sito, al massimo 32 in tutto. Funziona anche senza prefissi. Con Static Bridge 2.2.9 e 2.2.10 le traduzioni in coda venivano tolte invece che riscritte (confronto con il permalink dell'originale): serve la 2.2.11.
  Limite: se un contenuto pubblicato viene eliminato definitivamente senza passare dal cestino, Static Bridge mette in coda la rimozione della sua pagina quando è ancora pubblicata, e con lei quella delle traduzioni. Le traduzioni tornano statiche al loro prossimo aggiornamento o all'export completo; nel frattempo le serve WordPress.
- **Export completo (1.5.10):** Static Bridge 2.2 fa l'inventario da sé (post a blocchi, poi archivi) e non legge più le sitemap. Al filtro `static_bridge_export_urls` Lingua Bridge aggiunge gli stessi elenchi della sitemap `lingua`.
- **Sitemap:** `wp-sitemap-lingua-1.xml` elenca, per ogni lingua diversa dalla predefinita, gli archivi dei tipi e i termini delle tassonomie pubbliche che hanno contenuti pubblicati in quella lingua (al massimo 2000). La leggono i motori di ricerca e le versioni di Static Bridge che leggevano le sitemap all'export completo.

Nessun reindirizzamento in base al browser: ogni lingua è una cartella della copia statica (`en/index.html`, `en/category/news/index.html`).

## Test
```bash
cd dev && STATIC_BRIDGE_PLUGIN=/percorso/static-bridge/plugin ./test.sh
```
Static Bridge richiede PHP 8.4: `compose.static.yml` usa le immagini PHP 8.4 e monta il plugin in sola lettura. `tests/wp/static-bridge-run.php` chiama il **codice vero** di Static Bridge:
- le rotte rigenerate per un articolo inglese, uno italiano e un evento inglese;
- le traduzioni che una pagina in coda porta con sé (`with_translated_urls`);
- l'inventario dell'export;
- gli archivi dell'export completo (`discover_wordpress_archive_url_batch`, con `static_bridge_export_urls`).

`tests/wp/static.test.mjs` controlla su HTTP che ogni elenco della sitemap risponda in inglese, con i soli contenuti inglesi.

Non provato qui: il servizio dei file statici da Nginx o Apache. È compito dei gate di rilascio di Static Bridge e non dipende dalla lingua.
