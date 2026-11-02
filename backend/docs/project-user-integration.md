# Collegamenti della Gestione progetti con gli utenti

La Gestione progetti non modifica User, i ruoli di accesso, il profilo o le password.

## Selezione Project Manager

GET /api/project-options/users richiede un token Admin e restituisce solo id, first_name e last_name degli utenti non eliminati. Parametri: search (ricerca per nome/cognome, anche insieme), page, per_page (1-100, default 20).

Questa e una API di sola lettura, dedicata ai menu progetto. Non importa e non sostituisce GET /api/users del branch crud, che include dati contrattuali non necessari al menu. Il frontend seleziona un utente e invia project_manager_id alla creazione progetto. Le assegnazioni successive saranno gestite in Gestione team.

## Avatar dei partecipanti

GET /api/projects/{project}/users include avatar_url e avatar_requires_auth per ciascun partecipante corrente. Default pubblico: /images/defaults/default-avatar.svg.

La convenzione proposta per le foto personalizzate e lo storage privato local in users/avatars, con record filestorage: path = users/avatars, name = nome generato senza estensione, extension = png/jpg/jpeg/webp, type = image. Non sono supportati percorsi assoluti o riferimenti a documenti. Se il file manca o il riferimento non rispetta questa convenzione, viene usato il default.

GET /api/projects/{project}/users/{user}/avatar richiede Admin e verifica che la persona sia un partecipante corrente del progetto. Il frontend deve recuperare il file con Bearer token quando avatar_requires_auth e true; un semplice img src non aggiunge il token.

Non e stato implementato un upload foto utente: la convenzione va concordata col collega prima di collegare il suo modulo profilo. I test usano solo storage simulato.

## Assegnazioni

ProjectUser::save applica transazione e lock sul progetto. PM e altri incarichi della stessa persona sullo stesso progetto non possono avere periodi sovrapposti. Team Leader e Scrum Master ammettono una sola assegnazione per progetto e periodo. Le date finali sono inclusive. Le scritture dirette con DB::table, query update o insert bypassano queste verifiche: le future API di Gestione team devono salvare tramite il model.

Le regole trasversali agli altri progetti e il significato operativo di work_allocation non sono ancora definiti. I nuovi incarichi usano 0 provvisorio; nessun totale o percentuale viene derivato da questo campo.

## Giorno di riferimento

Le date delle assegnazioni e la prossima Sprint Review usano il calendario Europe/Rome, configurato in config/projects.php. ProjectCalendar::today() e il punto comune usato da relazioni responsabili, filtri, partecipanti, avatar e selezione review. I campi date sono confrontati come date, senza conversione dei valori gia salvati. La data finale di una collaborazione e inclusiva.

config/app.php mantiene timezone = UTC: created_at, updated_at e timestamp tecnici non vengono spostati. Europe/Rome gestisce automaticamente ora solare e ora legale. Non occorre modificare il database. Se si usa config:cache, rigenerare la cache di configurazione dopo gli aggiornamenti.

## Integrazione con Gestione allocazione

Le tre tipologie sono distinte: Progetti = is_course false e is_timesheet false; Timesheet = is_course false e is_timesheet true; Corsi = is_course true. Lo stesso nome puo esistere in tipi diversi; l'assegnazione usa sempre l'id del progetto. ProjectUser::save ricava allocation_type dai flag del progetto anche quando il chiamante non invia il campo.

Le API /api/allocations richiedono Admin e gestiscono soltanto Frontend, Backend, Full stack, Designer e Team Leader. PM e Scrum Master restano nelle API Gestione progetti: non compaiono nei conteggi delle allocazioni e non possono essere modificati tramite gli endpoint allocazioni, neppure conoscendone l'id. Le regole condivise del model restano valide, inclusa l'incompatibilita tra PM e incarichi tecnici sullo stesso progetto nello stesso periodo.

GET /api/allocations/summary restituisce sempre il riepilogo dei Progetti normali, indipendentemente dalla scheda aperta, dai filtri e dal parametro type. Il sovraccarico conta almeno tre progetti distinti in corso o in manutenzione. Nei dettagli Timesheet e Corsi un'assegnazione corrente conta anche senza stato, e non genera indicatori di sovraccarico o difficolta.

I loghi caricati dalla Gestione progetti sono riutilizzati tramite logo_url e logo_requires_auth. Avatar e loghi delle allocazioni usano endpoint Admin dedicati per supportare anche risorse non ancora allocate e Corsi. I file restano sullo storage privato e richiedono Bearer token; in assenza di file valido sono usate le immagini predefinite condivise.

## Aggiornamento dei database esistenti

Dopo l'integrazione eseguire php artisan migrate, senza migrate:fresh e senza rieseguire i seeder. La migration align_existing_projects_schema rende facoltativi i campi gia previsti come nullable dalla migration iniziale aggiornata e allinea allocation_type ai flag del progetto, conservando id, ruoli preesistenti e periodi.

La migration backfill_legacy_project_responsibilities trasferisce PM, Team Leader e Scrum Master dai vecchi campi ai record di assegnazione soltanto se per quel ruolo non esiste alcuno storico, neppure eliminato. Non ripristina incarichi rimossi e non sostituisce assegnazioni esistenti. Eventuali conflitti con le regole del model interrompono la transazione. Il rollback di queste due migration conserva i dati e i campi nullable, coerenti con la migration iniziale attuale.
