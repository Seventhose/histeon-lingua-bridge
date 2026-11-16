# Il selettore di lingua

Tre modi, dal più semplice:

| Modo | Come | Per chi |
|---|---|---|
| **Nel menu, da sé** | Impostazioni → URL e SEO → «Selettore di lingua nel menu», oppure la casella della configurazione guidata | Chiunque. Su un tema a blocchi va in fondo al blocco Navigazione (il primo della pagina, quello dell'header); su un tema classico, in fondo al menu della posizione scelta |
| **Blocco o shortcode** | Il blocco «Selettore lingua — Lingua Bridge», oppure `[lingua_bridge_switcher]` | Chi vuole metterlo altrove |
| **Componente** | Il componente Etch (`etch/`) o lo snippet «Selettore di lingua» di Histeon | Siti Etch e Histeon |

**Dove portano i link.** Su una pagina tradotta portano alle sue versioni pubblicate; altrove (archivi, ricerca) portano alle home delle lingue.

**Come sono fatti.** Codici brevi (IT · EN) con il nome della lingua per i lettori di schermo, `hreflang`, `lang` e `aria-current` sulla lingua attuale. Il selettore compare solo quando c'è più di una versione.

La configurazione guidata lo mette da sé: nel blocco Navigazione su un tema a blocchi, altrimenti nella prima posizione di menu che ha un menu.
