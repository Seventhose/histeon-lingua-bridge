# Traduzione automatica con la tua chiave

Impostazioni → Traduzione IA → **Traduzione automatica con la tua chiave**. Non è una licenza di Lingua Bridge: è la chiave del tuo account presso il servizio, che decide il costo.

| Servizio | Cosa serve |
|---|---|
| **DeepL** | La chiave. Quella che finisce con `:fx` usa l'API gratuita. |
| **Compatibile OpenAI** (OpenAI, Mistral, un modello sul tuo server…) | Indirizzo (`https://…/v1`, oppure `http://localhost…` per un modello locale), modello, chiave se serve. |

Il pulsante **Salva e prova** traduce una frase di prova e mostra il risultato.

## Dove si usa
- **Editor sul sito:**
  - **Traduci automaticamente** traduce le frasi ancora nelle parole dell'originale e lascia quelle già tradotte;
  - **Proposta automatica** riempie solo il campo del testo scelto e non salva nulla.
- **Dashboard:** azione di gruppo **Traduci automaticamente (bozze)**. Crea la bozza se manca, poi la traduce.

## Regole
- **Solo bozze:** una traduzione pubblicata non si traduce mai automaticamente; una pagina indipendente nemmeno.
- **Memoria:** la memoria di traduzione impara da una traduzione automatica solo quando qualcuno la pubblica, cioè dopo averla rivista.
- **Indicazioni per OpenAI:** il servizio compatibile OpenAI riceve contesto, indicazioni e glossario del sito, con la regola che i testi sono dati e non istruzioni. Le espressioni tra graffe, gli URL e i codici restano identici. Risponde con un oggetto JSON, che viene controllato: stesso numero di testi, solo testo.
- **Cosa entra nella pagina:** solo testo semplice, con l'escape di HTML o JSON dove finisce.

## Sicurezza
- **Dove sta la chiave:** la costante `LINGUA_BRIDGE_MT_KEY` in `wp-config.php` (consigliato), oppure salvata cifrata (sodium secretbox, chiave derivata da `AUTH_SALT`).
- **Mai visibile:** non si mostra né si restituisce mai, e nei messaggi d'errore viene sostituita da `••••`. Lasciando vuoto il campo resta quella salvata.
- **Indirizzi:** solo HTTPS, tranne `localhost`, `127.0.0.1` e `[::1]` (filtro `lingua_bridge_mt_http_hosts`). Niente credenziali nell'indirizzo. Gli host esterni passano da `wp_safe_remote_post`.
- **Chi la usa:** configurarla richiede `manage_options`; usarla, poter modificare la traduzione.

Test: `tests/wp/machine-run.php` e `tests/wp/dashboard.browser.mjs`, contro un servizio finto dello stack di sviluppo (nessuna chiave vera, nessuna rete).
