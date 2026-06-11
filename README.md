# SFT & PayStream

> Progetto universitario integrato — Corso di **Basi di Dati e di Conoscenza** (2° anno)

Due web application PHP/MySQL collegate tra loro:

- **SFT** (Servizio Ferroviario Turistico): vendita biglietti online con prenotazione posti, gestione orari, composizioni treni e backoffice amministrativo.
- **PayStream**: gateway di pagamento per esercenti e consumatori registrati, integrabile in applicazioni esterne come SFT.

---

## 🚆 SFT — Servizio Ferroviario Turistico

Web application per la vendita online di biglietti di una società ferroviaria che gestisce una linea turistica di **54 km con 10 stazioni**.

### Funzionalità principali
- Acquisto biglietti online con prenotazione del posto a sedere
- Calcolo automatico del prezzo in base ai km percorsi
- Gestione di **4 coppie di treni storici** nei giorni festivi (tutto l'anno) e **1 coppia** nei feriali (1° giugno – 30 settembre)
- Composizione variabile di carrozze e locomotive per ogni treno
- **Backoffice amministrativo**: calcolo della redditività di ogni treno
- **Backoffice di esercizio**: gestione orari e composizione treni

---

## 💳 PayStream — Sistema di Pagamento Online

Web application per il pagamento online di servizi e beni, utilizzabile da qualunque applicazione esterna (come SFT).

### Funzionalità principali
- Registrazione utenti **consumatori** ed **esercenti**
- Ogni utente possiede un **conto corrente in euro** o **disponibilità di carta di credito**
- API richiamabile da applicazioni esterne con parametri esercizio + importo
- Verifica delle credenziali del consumatore
- Riepilogo del bene/servizio con accettazione esplicita della transazione
- Comunicazione dell'avvenuto pagamento all'applicazione chiamante
- Accredito automatico sul conto dell'esercente

---

## 🛠️ Tecnologie utilizzate

- **Backend**: PHP
- **Database**: MySQL
- **Frontend**: HTML, CSS, Bootstrap
- **Sicurezza password**: BCrypt (`password_hash` / `password_verify`)

---

## ⚙️ Configurazione

I file `SFT/connessione.php` e `PAY/connessione.php` contengono dei segnaposto al posto delle credenziali reali del database.

Per far funzionare i progetti, sostituire i seguenti valori con quelli del proprio ambiente:

\`\`\`php
\$host     = "INSERIRE_HOST";
\$user     = "INSERIRE_USER";
\$password = "INSERIRE_PASSWORD";
\$database = "INSERIRE_NOME_DB";
\`\`\`

---

## 👤 Autrice

**Giorgia Fuso** — Progetto realizzato individualmente.
