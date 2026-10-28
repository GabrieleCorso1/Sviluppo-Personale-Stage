# Backend - Sviluppo Personale, gruppo 2

API Laravel con autenticazione Sanctum tramite token Bearer. Il frontend Angular si trova nella cartella `frontend` del repository.

## Prerequisiti

- PHP 8.5.0 per allinearsi all'ambiente del gruppo. I requisiti effettivi sono verificati dal lock di Composer.
- Composer e MySQL avviato.
- Estensioni PHP: pdo_mysql, mbstring, openssl, curl e fileinfo; intl e zip abilitate nell'ambiente del gruppo.

I comandi seguenti vanno eseguiti in PowerShell dalla cartella `backend`.

## Prima configurazione sul proprio PC

1. Installare le dipendenze dalle versioni del lock:

   ```powershell
   composer install
   composer check-platform-reqs --lock
   ```

2. Creare il file personale di configurazione solo se non esiste:

   ```powershell
   if (!(Test-Path .env)) { Copy-Item .env.example .env }
   ```

3. Modificare `.env`: inserire database, utente e password MySQL locali. L'esempio usa `sviluppo_personale_stage`, ma il database deve essere creato prima in MySQL Workbench. Non condividere il proprio `.env`.

4. Solo alla prima configurazione, se `APP_KEY` è vuota:

   ```powershell
   php artisan key:generate
   ```

   Non rigenerare la chiave di un ambiente già configurato per un normale aggiornamento.

5. Caricare la configurazione e controllare le migration:

   ```powershell
   php artisan config:clear
   php artisan migrate:status
   ```

   Su un database nuovo e vuoto, `Migration table not found` è atteso. Applicare le migration:

   ```powershell
   php artisan migrate
   ```

   Se il database contiene già tabelle ma manca lo storico delle migration, fermarsi e verificare la situazione. Non usare `migrate:fresh` o `migrate:refresh` per aggiornare il progetto: ricreano le tabelle e possono cancellare dati.

6. Facoltativo, solo per popolare un database locale dimostrativo:

   ```powershell
   php artisan db:seed
   ```

   I seeder inseriscono dati e aggiornano record esistenti: UserSeeder reimposta le password degli utenti dimostrativi e FileStorageSeeder inserisce nuovi record a ogni esecuzione. Non rieseguirli automaticamente dopo ogni pull.

7. Avviare il backend, lasciando aperto il terminale:

   ```powershell
   php artisan serve --host=localhost --port=8000
   ```

## Configurazione locale condivisa

`.env.example` non contiene credenziali reali né APP_KEY. Prevede:

- MySQL come database.
- `APP_URL=http://localhost:8000` e `FRONTEND_URL=http://localhost:4200`.
- `SESSION_DRIVER=file`, `CACHE_STORE=file` e `QUEUE_CONNECTION=sync`: non richiedono tabelle aggiuntive per questi servizi. La cache viene usata anche dal limite ai tentativi di login.
- `MAIL_MAILER=log`: non consegna email reali.
- `AUTH_EXPOSE_RESET_URL=false` per impostazione predefinita. Per le prove locali Postman impostarlo a `true` nel proprio `.env`, poi eseguire `php artisan config:clear`.

CORS è configurato in `config/cors.php` per le sole rotte `api/*` e per `http://localhost:4200`, senza credenziali cookie. Il Bearer viene inviato tramite Authorization e Retry-After è leggibile dal frontend. Se il frontend cambia indirizzo, va aggiornato anche quello; cambiare soltanto FRONTEND_URL modifica la destinazione dei link di recupero, non CORS.

La login attuale usa Bearer, non cookie di sessione: non richiede il passaggio `/sanctum/csrf-cookie` né `X-XSRF-TOKEN`.

## Aggiornamenti successivi

Salvare prima il proprio lavoro e aggiornare il branch secondo gli accordi del gruppo. Dopo il pull, dalla cartella backend:

```powershell
composer install
php artisan config:clear
php artisan migrate
```

Non sovrascrivere `.env`, non rigenerare APP_KEY e non eseguire i seeder per un normale aggiornamento. Confrontare `.env.example` per eventuali nuove impostazioni da riportare nel proprio `.env`.

## Prove backend con Postman

Guida passo passo, con richieste e script da copiare: [PROVE_POSTMAN_LOGIN.txt](PROVE_POSTMAN_LOGIN.txt).

Impostare `Accept: application/json`. Per i POST scegliere Body > raw > JSON. Base URL: `http://localhost:8000`.

| Metodo e percorso | Autenticazione | Scopo |
| --- | --- | --- |
| POST /api/login | No Auth | Email, password e remember opzionale |
| GET /api/user | Bearer Token | Utente autenticato |
| POST /api/logout | Bearer Token | Revoca del token corrente |
| POST /api/forgot-password | No Auth | Richiesta recupero password |
| POST /api/reset-password | No Auth | Impostazione nuova password |

Login con i dati dimostrativi, se il seeder non è stato modificato:

```json
{
  "email": "mario.rossi@example.com",
  "password": "Password123!",
  "remember": false
}
```

La risposta contiene `access_token`: copiarlo in Authorization > Bearer Token per user e logout, senza aggiungere manualmente la parola Bearer. La durata è 8 ore oppure 30 giorni con `remember: true`. Dopo il logout, lo stesso token deve ricevere 401 su `/api/user`.

Per richiedere il recupero:

```json
{
  "email": "mario.rossi@example.com"
}
```

Solo con `AUTH_EXPOSE_RESET_URL=true`, `APP_ENV=local` e `APP_DEBUG=true`, la risposta contiene `reset_url` al posto dell'invio della notifica email. Il link punta alla futura pagina Angular, non all'endpoint API. Non occorre aprire la porta 4200 per provare il backend: utilizzare email e token del link nel body seguente su POST `/api/reset-password`:

```json
{
  "email": "mario.rossi@example.com",
  "token": "TOKEN_DI_RECUPERO_DAL_LINK",
  "password": "NuovaPassword123!",
  "password_confirmation": "NuovaPassword123!"
}
```

Usare il token di recupero, non il Bearer della login; l'email nel JSON va scritta con @, non %40. Il token scade dopo 60 minuti ed è utilizzabile una sola volta. Un nuovo link è soggetto a un intervallo minimo di 60 secondi; le rotte di recupero hanno anche un limite di 5 richieste al minuto per IP.

Dopo il reset, usare la nuova password per la login. Tutti i token Bearer precedenti dell'utente vengono revocati. Non eseguire di nuovo il seeder per verificare il risultato, perché reimposterebbe la password dimostrativa.

Collezione pronta: [POSTMAN_LOGIN_GRUPPO2.postman_collection.json](POSTMAN_LOGIN_GRUPPO2.postman_collection.json). In Postman selezionare Import e scegliere il file. Si ottiene una nuova collezione con 20 richieste, script di acquisizione token e controlli delle risposte. Non eseguire subito Run: nelle Variables impostare prima password_current alla password attuale del proprio utente dimostrativo e scegliere password_new diversa. I token e password_current sono vuoti nel file condiviso. Eseguire le cartelle numerate in ordine: la cartella 02 cambia davvero la password demo e revoca tutti i suoi token. Dopo un reset riuscito lo script aggiorna password_current. Non esportare i valori compilati. La collezione contiene le prove principali e negative; scadenze temporali e matrice di configurazione sono coperte dalla suite PHP. L'importazione non esegue le richieste. Una prova Postman non sostituisce l'integrazione Angular nel browser.

## Test automatici

Dalla cartella backend:

```powershell
php vendor/bin/pest
```

La suite usa SQLite `:memory:` con cache e sessioni in memoria e notifiche simulate per il recupero. Non richiede utenti dimostrativi né seeder e non invia email reali. `Tests\TestCase` interrompe l'avvio se la configurazione effettiva punta a MySQL o a un database su file, prima dell'esecuzione delle migration di test. Se si ferma per configurazione errata, verificare `phpunit.xml` ed eventuale configurazione Laravel in cache; non rimuovere il controllo di isolamento.

I test di autenticazione coprono login, validazione, tentativi eccessivi, scadenze, risposta del profilo, logout e recupero password (inclusi revoca dei vecchi accessi e limiti della scorciatoia locale). La factory utenti è allineata allo schema del progetto e prepara i record necessari ai test; i seeder restano separati.

## Invio email e pubblicazione

Se manca una delle tre condizioni (opzione attiva, ambiente local, debug attivo), il link non viene restituito nella risposta: viene usato il mailer configurato. Con `MAIL_MAILER=log` il messaggio finisce in `storage/logs/laravel.log`; per consegnarlo a una casella reale occorre configurare un servizio email.

L'esempio è per sviluppo locale. Prima di pubblicare online servono configurazione di produzione (`APP_ENV=production`, `APP_DEBUG=false`), HTTPS, servizio email e indirizzi corretti. Non condividere token, link di recupero, password, APP_KEY o credenziali SMTP.
