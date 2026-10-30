# Verifica manuale Gestione progetti

Collection: Gestione-progetti-completa.postman_collection.json. Contiene **163 richieste** in 10 cartelle, da eseguire in ordine. L'indice-prove.md elenca ogni richiesta. Sostituisce la vecchia collection di 15 prove. Non e stata eseguita automaticamente dall'assistente.

## Preparazione

1. Avviare il backend locale con php artisan serve. Usare un database di sviluppo con i seeder aggiornati. Non serve ripetere il fresh a ogni esecuzione.
2. Importare la collection in Postman e selezionare No Environment, per non sovrascrivere le variabili della collection con vecchi valori.
3. Nelle variabili della collection verificare base_url e credenziali. Sono precompilate solo le credenziali pubbliche di esempio del UserSeeder (Mario Admin e Laura non Admin). Sostituirle se il database e diverso. Nessun token e incluso nel file.
4. Nelle impostazioni Postman impostare Working Directory alla cartella backend di questo repository. I file sono relativi: postman/fixtures/logo.png, delivery-plan.pdf e sprint.csv. Se Postman non risolve i percorsi, selezionare manualmente i file nelle richieste form-data prima del run e salvarle. Non impostare Content-Type manualmente per form-data.
5. Eseguire prima la cartella 01: login salva i token e le altre richieste usano automaticamente quello corretto. La data di prova segue Europe/Rome.

## Esecuzione

Eseguire tutte le cartelle in ordine, singolarmente o tramite Runner. I codici 401, 403, 404 e 422 sono esiti positivi quando previsti dal nome/script. Controllare Test Results: lo stato HTTP da solo non basta. Se fallisce una fase di preparazione, correggerla prima di continuare, per evitare errori a cascata. Non applicare suggerimenti automatici di Postman per trasformare prove negative in richieste valide.

Effetti sul database: crea tre progetti dedicati con un run_id univoco e li soft-elimina alla fine; i nomi restano riservati. Carica/sostituisce il CSV di uno sprint concluso di API Aziendali: il file resta associato; i flag pilota/fatturazione sono ripristinati al valore iniziale. I vecchi file restano sul disco. Non elimina progetti seed o utenti e non esegue migration/fresh. In caso di interruzione gli oggetti di prova possono rimanere: gli ID sono nelle variabili. Per ricominciare la richiesta 001 genera nuovi nomi.

La cartella 10 revoca i due token creati dalla collection. Dopo serve rifare login. Non condividere esportazioni con token reali.

## Cartelle

- 01 - Login e preparazione: 7 richieste.
- 02 - Creazione e validazione: 22 richieste.
- 03 - Dettaglio e modifica: 16 richieste.
- 04 - Liste contatori e menu PM: 23 richieste.
- 05 - Note e partecipanti: 10 richieste.
- 06 - Immagini e delivery plan: 19 richieste.
- 07 - CSV degli sprint: 16 richieste.
- 08 - Permessi su ogni funzione: 34 richieste.
- 09 - Soft delete dei soli progetti di prova: 12 richieste.
- 10 - Logout: 4 richieste.

## Verifiche aggiuntive

Pest verifica anche mezzanotte italiana, assegnazioni future/scadute, incompatibilita PM, unicita temporale TL/SM, corsi esclusi, concorrenza sul nome e file mancanti. Non esistono API di Gestione team per creare questi scenari: la collection non introduce rotte di test o scritture dirette nel database.

I file forniti sono piccoli. I limiti (2 MB loghi, 10 MB PDF/CSV) sono coperti dai test automatici; PHP 10M/12M e stato verificato anche con upload HTTP reali su server temporaneo. Per una prova manuale aggiuntiva selezionare file reali oltre/sotto il limite nelle richieste di upload: oltre post_max_size PHP/Laravel puo rispondere 413, mentre la validazione applicativa restituisce 422.

I seeder hanno sprint storici: next_sprint_review_date puo essere null. La collection non cambia le date degli sprint. Non crea corsi o conteggi Azure.
