<p align="center"><img src="logo.svg" width="96" alt="dotmarrotonda"></p>

# dotmarrotonda — Arrotonda prezzi scontati per PrestaShop

Modulo PrestaShop che **arrotonda il prezzo scontato IVA inclusa** quando un prodotto ha uno sconto in percentuale. Con un -10% su 2.349,00 € il prezzo diventa 2.114 € invece di 2.114,10 €, e su 1.701,00 € diventa 1.531 € invece di 1.530,90 €.

Il prezzo arrotondato vale ovunque: scheda prodotto, listati, carrello, ordini, fatture, email e feed. La differenza viene aggiunta o tolta allo sconto, quindi prezzo pieno − sconto = prezzo arrotondato e i conti tornano.

> *English summary: PrestaShop module that rounds discounted prices (tax included) to the nearest euro, or to a configurable step, when a percentage discount applies (catalog price rules or specific prices). The rounded price is used everywhere (product page, listings, cart, orders, invoices, feeds). Includes a test mode limited to given IP addresses. Back office in Italian.*

## Funzionalità

- **Arrotondamento del prezzo scontato IVA inclusa** per gli sconti in percentuale (regole prezzi del catalogo e prezzi specifici). A scelta anche per gli sconti in importo.
- **Passo**: 0,10 · 0,50 · 1 (euro intero) · 5 · 10.
- **Verso**: al più vicino (,50 per eccesso), per difetto (sempre a favore del cliente) o per eccesso.
- **Modalità di prova**: l'arrotondamento si attiva solo per gli IP indicati. Si controllano schede, carrello e un ordine di prova, poi lo si accende per tutti.
- **Prezzo senza IVA coerente**: viene ricalcolato dal prezzo arrotondato, così riepilogo, ordine e fattura tornano al centesimo.
- **I prodotti senza sconto non cambiano.**

Per mostrare al cliente prezzo pieno barrato, percentuale e risparmio anche nel checkout, nella conferma d'ordine e nelle email (dove PrestaShop mostra solo il prezzo finale) c'è il modulo gemello [dotmsconti](https://github.com/dotmdotit/ps_visualizza_sconti).

## Requisiti

- PrestaShop **1.7.7** o successivo
- PHP 7.2 o successivo

Da PrestaShop **8.1** il modulo usa l'hook `actionProductPriceCalculation` e non installa override. Sulle versioni **1.7.7 – 8.0** l'hook non esiste: il modulo installa un override di `Product::priceCalculation` (`override/classes/Product.php`), che richiama il prezzo originale e applica l'arrotondamento.

## Installazione

1. Scarica la repo (*Code → Download ZIP*, oppure una release).
2. La cartella del modulo **deve chiamarsi `dotmarrotonda`**. Lo ZIP di GitHub contiene la cartella `ps_arrotonda_prezzi_scontati-main`: rinominala e ricrea lo ZIP:
   ```sh
   unzip ps_arrotonda_prezzi_scontati-main.zip && mv ps_arrotonda_prezzi_scontati-main dotmarrotonda
   zip -r dotmarrotonda.zip dotmarrotonda -x '*.git*'
   ```
3. In back office: *Moduli → Gestione moduli → Carica un modulo* e scegli `dotmarrotonda.zip`.
   In alternativa copia la cartella in `modules/dotmarrotonda/` sul server e installa il modulo dal Gestore moduli.
4. Apri *Configura*: all'installazione l'arrotondamento è **spento**.

## Configurazione

| Impostazione | Predefinito | Note |
|---|---|---|
| Arrotonda i prezzi scontati | No | No · Solo per gli IP di prova · Sì, per tutti |
| IP di prova | vuoto | Uno o più indirizzi separati da virgola; la pagina mostra il tuo IP attuale. |
| Arrotonda a | 1 | Passo dell'arrotondamento. |
| Verso | Al più vicino | Al più vicino, per difetto o per eccesso. |
| Anche gli sconti in importo | No | Di norma si arrotondano solo gli sconti in percentuale. |

## Dopo l'installazione

1. Imposta **Solo per gli IP di prova** con il tuo IP.
2. Controlla alcune schede prodotto scontate (anche le combinazioni), una categoria e il carrello.
3. Fai un **ordine di prova** e controlla riepilogo, email e fattura.
4. Imposta **Sì, per tutti**.

Se usi altri moduli che lavorano sullo stesso hook `actionProductPriceCalculation` (ad esempio moduli di opzioni a pagamento che sommano un supplemento al prezzo), in *Aspetto → Posizioni* metti **dotmarrotonda prima** di loro. Così si arrotonda il prezzo del prodotto e il supplemento si somma dopo.

## Personalizzazione

Il modulo non contiene nulla di specifico per un negozio: tutto passa dalla configurazione. Non ha testi né stili nel front office.

## Struttura

```
dotmarrotonda.php                 classe del modulo: installazione, configurazione, arrotondamento (hook 8.1+)
override/classes/Product.php      solo per PrestaShop 1.7.7 – 8.0: richiama l'arrotondamento dopo priceCalculation
upgrade/                          script di aggiornamento tra versioni
```

## Aggiornamento e disinstallazione

- Per aggiornare, sostituisci i file del modulo e avvia l'aggiornamento dal Gestore moduli: gli script in `upgrade/` aggiornano la configurazione.
- Disinstallando il modulo i prezzi tornano a quelli calcolati da PrestaShop (con i decimali), e su 1.7.7 – 8.0 viene rimosso l'override. Gli ordini già fatti non cambiano.

## Licenza

Rilasciato con licenza [MIT](LICENSE).

## Autore

Sviluppato da [dotm.it](https://dotm.it).
