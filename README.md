# Lingua Bridge

Traduzioni per WP come normali contenuti: ogni lingua è una pagina WP a sé. Modulo di Histeon, funziona anche da solo. Licenza GPL-2.0-or-later.

Questo repository contiene i sorgenti del plugin e ne pubblica le **release**.

## Release

Ogni release contiene:

- `lingua-bridge-<versione>.zip`: il plugin da installare;
- `lingua-bridge-<versione>.zip.minisig`: la firma dello ZIP;
- `SHA256SUMS`: le impronte dei file;
- `lingua-bridge-<versione>-sorgenti.tar.gz`: i sorgenti completi di quella versione.

Prima di installare, controlla l'impronta con `SHA256SUMS` (`sha256sum -c SHA256SUMS`) e la firma con [minisign](https://jedisct1.github.io/minisign/):

```bash
minisign -Vm lingua-bridge-<versione>.zip -P RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W
```

## Aggiornamenti

Il plugin installato da solo si aggiorna dalle release di questo repository, dall'amministrazione di WP come ogni plugin: installa solo uno ZIP con una firma valida della chiave qui sopra, senza licenze, telemetria o dati del sito inviati. Come funziona e come si spegne: [docs/UPDATES.md](docs/UPDATES.md). Quando Lingua Bridge è un modulo dentro Histeon lo aggiorna la release di Histeon.

Anche uno strumento che aggiorna i plugin dagli allegati delle release di GitHub può seguire questo repository.

## Che cosa c'è qui

- `lingua-bridge.php`, `includes/`, `assets/`, `languages/`: il plugin; `readme.txt` è la sua scheda, con il changelog.
- `connector/`: il connettore IA, un solo file per Node; si usa come descritto in [CONNECTOR.md](CONNECTOR.md).
- `etch/`: il componente del selettore di lingua per i siti Etch ([etch/README.md](etch/README.md)).
- `docs/`: le guide, una per argomento:
  - [editor visuale sul sito](docs/FRONTEND-EDITOR.md);
  - [memoria di traduzione, aggiornamenti e pagine indipendenti](docs/MEMORY.md);
  - [stringhe fuori dai contenuti, menu e importazione da altri plugin](docs/STRINGS.md);
  - [selettore di lingua](docs/SWITCHER.md);
  - [traduzione automatica con la tua chiave](docs/MACHINE-TRANSLATION.md);
  - [XLIFF per traduttori e agenzie](docs/XLIFF.md);
  - [WooCommerce](docs/WOOCOMMERCE.md);
  - [Histeon](docs/HISTOS.md) e [Static Bridge](docs/STATIC-BRIDGE.md);
  - [aggiornamenti firmati](docs/UPDATES.md).
- `tests/` e `dev/`: le prove (PHP e Node senza WP, poi un WP vero in Docker con `dev/up.sh` e `dev/test.sh`).
- `scripts/`: costruzione dello ZIP, traduzioni dell'interfaccia, copia pubblica e controllo dei segreti.

## Licenza e sorgenti

GPL-2.0-or-later: il testo è in [LICENSE.txt](LICENSE.txt). I sorgenti completi di ogni versione sono anche nell'archivio allegato alla sua release.

- Segnalare una falla, in privato: [SECURITY.md](SECURITY.md).
- Il nome Histeon e i marchi di altri (nessuna affiliazione con i prodotti citati): [TRADEMARKS.md](TRADEMARKS.md).
