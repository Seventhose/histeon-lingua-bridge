# Lingua Bridge e Histeon

Lingua Bridge è il motore multilingua di Histeon, il builder visuale GPL. Histeon non conosce nessun plugin delle lingue: offre hook neutri, e `includes/class-histos.php` li usa. Senza Histeon quegli hook non partono e la classe non fa nulla.

Specifica degli hook, dalla parte di Histeon: `docs/spec/multilingual.md` nel repository di Histeon.

## Cosa succede
| Dove | Cosa fa Lingua Bridge |
|---|---|
| Pagina pubblica in inglese | I componenti Histeon (`wp_block`) si disegnano con la traduzione inglese, se è pubblicata (`xb_component_post`). Senza traduzione resta l'originale. |
| Tela del builder | Mentre la tela disegna una traduzione (`xb_render_document`), elenchi, ricerche e componenti usano la lingua del documento, come sul sito. Dopo (`xb_render_document_done`) tutto torna come prima. |
| Espressioni | `data.lingua`: `code`, `name`, `default`, `alternates[]` con `code`, `name`, `url`, `current`. Solo le traduzioni **pubblicate**. |
| Barra delle lingue del builder | La lingua del documento, le traduzioni da aprire in Histeon (se l'utente può modificarle) e un **+XX** per ogni lingua mancante. |
| Creazione di una traduzione da Histeon | Il **+XX** apre la schermata «Crea traduzione» di Lingua Bridge (con `lb_return=histos`). La traduzione nasce **bozza**, con il rapporto dei campi copiati e da tradurre, e si apre nel builder. |
| Invio da Histeon Publish | Finito l'invio, l'azione `xb_remote_language_link` collega ogni traduzione al suo originale, nelle lingue di questo sito. Le bozze restano bozze; uno slug arriva come `<slug>-<lingua>`, perché WordPress lo vuole unico. |
| Controlli del builder | I testi dell'originale arrivano al builder, che segnala quelli rimasti identici nella traduzione. |

## Regole
- Ogni lingua è un post WordPress normale: le release, le versioni e la revisione di Histeon valgono per ogni lingua.
- Il selettore di lingua è uno snippet Histeon (o un componente Etch, sui siti Etch), non HTML del plugin.
- Nessuna traduzione si pubblica da sola. L'IA e il connettore creano solo bozze e non sovrascrivono mai i contenuti pubblicati.
- I link della barra puntano solo all'amministrazione del sito. Histeon scarta ogni altro valore.

## Test
Lo stack di sviluppo monta Histeon accanto a Lingua Bridge:
```bash
cd dev && COMPOSE_FILE=compose.yml:compose.histos.yml ./up.sh && COMPOSE_FILE=compose.yml:compose.histos.yml ./test.sh
```
- `tests/wp/histos-seed.php`: pagina IT/EN con un componente tradotto e un elenco;
- `tests/wp/histos-run.php`: tela EN/IT, `data.lingua`, barra delle lingue, testi dell'originale, link per creare;
- `tests/wp/histos.test.mjs`: le pagine pubbliche IT/EN su HTTP.

Il percorso di Histeon si cambia con `HISTOS_PLUGIN` (predefinito `../../builder/plugin`).
