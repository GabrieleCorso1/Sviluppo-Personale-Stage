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
