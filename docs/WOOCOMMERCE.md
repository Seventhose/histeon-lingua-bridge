# WooCommerce

Un prodotto in un'altra lingua è un prodotto a sé, con i suoi testi e il suo indirizzo, ma vende la stessa cosa.

| Cosa | Come |
|---|---|
| **Traduzione nuova** | Prende tutti i dati del prodotto (prezzi, scorte, dimensioni, attributi, download, upsell…), le tassonomie (tipo, visibilità, categorie) e le varianti. Ogni variante è collegata alla variante originale. |
| **Dati condivisi** | Prezzi, saldi, scorte, gestione scorte, dimensioni, tasse, download: li decide il prodotto originale. Salvati lì arrivano in tutte le traduzioni; salvata una traduzione, riprende quelli dell'originale. Chi può modificare solo una traduzione non cambia mai ciò che tutti comprano. |
| **Varianti di una traduzione in bozza** | Restano private finché il prodotto tradotto non è pubblicato, poi vanno online con lui. |
| **Vendite** | Una vendita di un prodotto pubblicato, in qualsiasi lingua, abbassa le scorte di tutte, anche per le varianti. |
| **SKU** | Resta solo sull'originale, perché WooCommerce lo vuole unico. |
| **Pagine** | Negozio, carrello, cassa, account e condizioni si aprono nella pagina della lingua del visitatore, se è tradotta e pubblicata. |
| **Carrello** | Mostra i prodotti nella lingua della pagina. |
| **Testi di WooCommerce** | Pulsanti, email, cassa: li traduce il pacchetto di WordPress della lingua (Impostazioni → Lingue). |
| **Attributi globali** (`pa_colore`) | Dalla 1.8.2 sono termini traducibili, come categorie e tag: «Rosso» ha la sua versione «Red», e le varianti del prodotto tradotto usano il termine tradotto. Gli attributi scritti a mano sul prodotto si traducono come stringhe (docs/STRINGS.md). |
| **Email degli ordini** | Dalla 1.8.2 un ordine ricorda la lingua in cui è stato fatto; le sue email partono in quella lingua anche quando il negozio le manda dopo, dall'amministrazione (rimborso, spedizione, nota al cliente). Senza lingua salvata vale quella del profilo del cliente. Serve il pacchetto di WordPress della lingua. |

Test: `tests/wp/woocommerce-run.php` (prodotto semplice e con varianti, prezzi nei due sensi, scorte, collegamento delle varianti) e `tests/wp/woocommerce.test.mjs` (carrello nella lingua della pagina). Lo stack monta WooCommerce da una copia locale (`dev/.third-party/woocommerce`, mai nel repository).

Non coperti: i testi delle email scritti a mano nelle impostazioni di WooCommerce (oggetto e intestazione personalizzati), che restano nella lingua in cui sono scritti; le tabelle di prodotti personalizzate (HPOS dei prodotti, quando arriverà).
