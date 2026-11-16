# Aggiornamenti firmati

Implementazione: `includes/class-release-updater.php`. Test: `tests/wp/updater-run.php` (in `dev/test.sh`).

Dalla 1.5.8 Lingua Bridge si aggiorna dalle release pubbliche di `Seventhose/histeon-lingua-bridge`, come ogni plugin dall'amministrazione di WordPress, con lo stesso schema degli aggiornamenti di Histeon: nessuna licenza, nessuna telemetria, nessun dato del sito inviato.

## Come funziona

- **Acceso di serie, solo per il plugin autonomo.** Quando Lingua Bridge è un modulo dentro Histeon, lo aggiorna la release di Histeon e questo meccanismo resta spento.
- **Scelte in `wp-config.php`:** `LINGUA_BRIDGE_UPDATES` a `false` lo spegne; `LINGUA_BRIDGE_UPDATE_REPOSITORY` (`proprietario/nome`) e `LINGUA_BRIDGE_UPDATE_PUBLIC_KEY` sostituiscono il repository e la chiave di rilascio.
- **`Update URI`** nell'intestazione del plugin: WordPress non chiede mai alla directory dei plugin un aggiornamento con lo stesso slug, che potrebbe essere di qualcun altro.
- **Controllo.** Una richiesta anonima all'API pubblica di GitHub (`/releases/latest`) al massimo ogni 12 ore. Una release conta solo se non è bozza né pre-release, ha il tag `vX.Y.Z` e contiene `lingua-bridge-X.Y.Z.zip` con accanto `lingua-bridge-X.Y.Z.zip.minisig`, entrambi da `https://github.com/`.
- **Verifica prima di installare** (`upgrader_pre_download`). Lo ZIP (al massimo 60 MB) e la firma (al massimo 4 KB) vengono scaricati, poi:
  - la firma minisign deve essere della chiave di rilascio, con il suo id: Ed25519 sul BLAKE2b-512 del file e sul commento fidato;
  - il nome del file nel commento fidato (`file:`) deve essere esattamente `lingua-bridge-X.Y.Z.zip`, l'allegato della release.

  Se qualcosa non torna, l'aggiornamento si ferma con il motivo e il file scaricato viene cancellato. Un'offerta che non corrisponde più all'ultima release firmata viene rifiutata: il pacchetto non è mai installato senza verifica.

## Firmare una release

La chiave è quella di Histeon (id `7245FEACC0E94D12`):

```
RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W
```

Si firma con lo script `scripts/sign-release.php` del repository di Histeon:

```bash
php <histeon>/scripts/sign-release.php sign <chiave-segreta> dist/lingua-bridge-X.Y.Z.zip
```

Nella release vanno lo ZIP e il suo `.minisig`. La chiave segreta resta fuori dal repository e dai pacchetti.
