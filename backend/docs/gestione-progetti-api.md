# Gestione progetti - contratto API e verifica Figma

Questa guida descrive il backend del branch feature/gestione-progetti. Le modifiche recenti non sono ancora in un nuovo commit. Documentazione e collection non attestano da sole il superamento della verifica manuale.

## Ambito

Sono gestiti due tipi nella tabella projects: normale (is_timesheet=false, is_course=false) e timesheet (is_timesheet=true, is_course=false). Il timesheet di questa sezione e un'anagrafica progetto, non la funzione di registrazione ore.

I corsi sono esclusi da queste liste, contatori e operazioni. Le rotte con ID corso restituiscono 404. is_course e vietato nel body e nei parametri gestiti; il cambio is_timesheet e vietato in modifica. Nessuna API di creazione corsi e implementata.

Dati e link Azure sono supportati; sincronizzazione e conteggi automatici bug/PBI non sono previsti in questa fase. Il frontend deve mostrare il dato non disponibile (-), senza inventare zero. Non sono implementate le API complete della Gestione team, profilo utente, upload avatar o cambio password.

## Accesso e formati

Base locale: http://127.0.0.1:8000. Tutte le API di seguito richiedono auth:sanctum con Bearer e ruolo Admin. Gli URL pubblici delle immagini predefinite non richiedono autenticazione.

Header JSON:

    Accept: application/json
    Content-Type: application/json
    Authorization: Bearer <token>

Upload: multipart/form-data, con Content-Type generato dal client (non impostarlo a application/json).

Codici principali: 200 lettura/modifica/upload/eliminazione, 201 creazione, 401 token assente/non valido/scaduto, 403 ruolo non autorizzato, 404 risorsa inesistente/eliminata/fuori ambito, 422 dati non validi. Una richiesta superiore a post_max_size puo ricevere 413 prima della validazione Laravel.

## Rotte

| Metodo | Percorso | Operazione |
|---|---|---|
| GET | /api/projects | Elenco paginato e contatori |
| POST | /api/projects | Creazione |
| GET | /api/projects/{project} | Dettaglio |
| PATCH | /api/projects/{project} | Modifica parziale |
| DELETE | /api/projects/{project} | Soft delete |
| PATCH | /api/projects/{project}/note | Scrittura/svuotamento nota |
| GET | /api/projects/{project}/users | Partecipanti correnti |
| GET | /api/projects/{project}/users/{user}/avatar | Avatar di un partecipante corrente |
| GET | /api/project-options/users | Utenti selezionabili come primo PM |
| POST | /api/projects/{project}/logo | Caricamento/sostituzione logo |
| GET | /api/projects/{project}/logo | Lettura logo o default |
| POST | /api/projects/{project}/delivery-plan | Caricamento/sostituzione PDF |
| GET | /api/projects/{project}/delivery-plan | Lettura PDF privata |
| GET | /api/projects/{project}/csv | Sprint raggruppati per stato CSV |
| POST | /api/projects/{project}/sprints/{sprint}/csv | Caricamento/sostituzione CSV |
| GET | /api/projects/{project}/sprints/{sprint}/csv | Download CSV privato |
| PATCH | /api/projects/{project}/sprints/{sprint}/csv-type | Tipo normale/pilota/fatturazione |

Lo sprint deve appartenere al progetto nell'URL. Gli allegati delivery plan e CSV non sono previsti per i progetti timesheet.

## Creazione normale

Esempio (sostituire l'ID utente con uno attivo):

    {
      "alias": "Portale esempio",
      "is_timesheet": false,
      "project_manager_id": 2,
      "start_date": "2026-10-30",
      "status": "in corso",
      "priority": "media",
      "description": "Descrizione facoltativa"
    }

alias, is_timesheet, project_manager_id, start_date, status e priority sono obbligatori. Il nome ha massimo 255 caratteri ed e univoco nello stesso tipo, inclusi i record soft-deleted. Il database garantisce lo stesso vincolo; anche i conflitti intervenuti dopo la validazione diventano errori 422 su alias. Rinominare un progetto non riserva permanentemente il suo nome precedente.

status: in corso, manutenzione, sospeso, concluso. priority: bassa, media, alta. start_date: YYYY-MM-DD.

project_manager_id deve riferirsi a un utente non eliminato, indipendentemente dal suo ruolo di accesso. Creazione e prima assegnazione PM sono atomiche. L'assegnazione inizia alla data di inizio del progetto; se e futura, il PM non e ancora corrente e non viene mostrato nell'elenco dei PM correnti.

Facoltativi: description (massimo 250), is_daily/is_azure/is_ux_ui (booleani, default false), azure_project_name/azure_team_name (stringhe fino a 255), bug_count_url/pbi_without_feature_url/pbi_past_feature_url (URL HTTP/HTTPS fino a 255). Questi nomi e link non attivano alcuna integrazione esterna. is_course e impostato dal server a false.

I file non fanno parte del JSON di creazione. Il frontend salva prima il progetto e poi carica logo/PDF con le rotte dedicate se selezionati. Questo e un flusso in due richieste: il fallimento dell'upload non annulla automaticamente il progetto creato; mostrare errore e permettere di riprovare.

## Creazione timesheet

    {
      "alias": "Attivita interne",
      "is_timesheet": true,
      "description": "Facoltativa"
    }

Sono consentiti i campi del modulo ridotto: nome, descrizione, nomi/link Azure e logo tramite rotta dedicata. PM, TL, SM, start_date/end_date, status/priority, is_daily/is_azure/is_ux_ui/is_course e delivery_plan non devono essere inviati; vengono rifiutati anche se null o false. I booleani tecnici non previsti vengono salvati a false dal server.

## Modifica

PATCH invia solo i campi da cambiare. I campi omessi restano invariati; un campo facoltativo puo essere svuotato con null. Il nome e i campi obbligatori normali non possono essere svuotati. description conserva il limite di 250.

project_manager_id e vietato nel PATCH: dopo la prima selezione, le variazioni dei PM saranno gestite nelle assegnazioni della Gestione team. Le API restituiscono project_managers come elenco senza gerarchia, non project_manager_id/project_manager singoli. La modifica non sincronizza automaticamente la data di inizio progetto con periodi di collaborazione gia creati: sono informazioni distinte.

## Elenchi e filtri

GET /api/projects richiede is_timesheet=0 oppure 1.

| Parametro | Regola |
|---|---|
| search | Facoltativo, ricerca parziale su alias, massimo 255 |
| sort_direction | asc o desc; default asc |
| page | Intero >=1 |
| per_page | Intero da 1 a 100; default 10 |
| status | Solo normali, uno degli stati ammessi; vuoto non filtra |
| priority | Solo normali, bassa/media/alta; vuoto non filtra |
| project_manager_id | Solo normali, ID utente non eliminato |
| team_leader_id | Solo normali, ID utente non eliminato |
| scrum_master_id | Solo normali, ID utente non eliminato |

I filtri si combinano con AND. I filtri responsabili usano le assegnazioni correnti, non le colonne legacy di projects. Sul tab Timesheet stato, priorita e filtri responsabili sono rifiutati (422), anche se vuoti. L'ordinamento secondario e per id crescente.

La risposta mantiene data, total, current_page, per_page, last_page e i link Laravel. counts.projects e counts.timesheets sono i totali globali dei due tipi non corso, senza record eliminati e indipendenti da ricerca e filtri. total e invece il numero di risultati filtrati.

## Dati restituiti per ogni progetto

I dati del progetto sono accompagnati da:

- project_managers: elenco degli utenti con incarico PM corrente, ciascuno con id/first_name/last_name;
- team_leader_id/team_leader e scrum_master_id/scrum_master, ricavati dalle assegnazioni correnti; null se assenti;
- logo_url e logo_requires_auth;
- delivery_plan_url e delivery_plan_requires_auth; URL null se non disponibile;
- next_sprint_review_date: YYYY-MM-DD o null;
- missing_csv_count: numero degli sprint conclusi non di fatturazione senza file CSV reale.

Le colonne legacy dei responsabili rimangono nel database, ma non sono la fonte del risultato delle API. managed_alias e un campo tecnico del vincolo univoco e non viene restituito. I corsi non vengono serializzati da queste rotte.

La prossima review e la fine piu vicina, da oggi in avanti incluso oggi, degli sprint non conclusi/non eliminati. Non coincide necessariamente con lo sprint attualmente in corso: uno sprint futuro puo essere il prossimo. Per timesheet e null. Nessun endpoint di scrittura sprint e stato aggiunto da questa funzione.

## Assegnazioni e partecipanti

Il giorno corrente segue Europe/Rome (ora legale/solare automatica); i timestamp tecnici restano UTC. Un'assegnazione e corrente se inizio <= oggi e fine nulla oppure >= oggi. La fine e inclusiva. Date fine precedenti all'inizio sono rifiutate dal model.

PM e altri incarichi della stessa persona sullo stesso progetto non possono sovrapporsi. Una persona puo essere PM su piu progetti. Non e stato introdotto un divieto globale di incarichi diversi in altri progetti, perche non confermato. Sono ammessi piu PM per progetto senza gerarchia. TL e SM ammettono una sola assegnazione per progetto e periodo. Le verifiche usano transazione e lock del progetto; le future API devono usare ProjectUser::save/create/update. Le scritture dirette DB o query bulk le aggirano.

GET /users raggruppa ogni persona una volta con tutti gli incarichi correnti. Esclude utenti e assegnazioni eliminati. Parametro search: nome O cognome (non ricerca combinata nome+cognome). Risposta data e total con id, first_name, last_name, assignment_roles, avatar_url, avatar_requires_auth.

work_allocation resta un dato tecnico non usato per calcoli. Le nuove assegnazioni usano 0, mentre valori preesistenti non vengono alterati. Nessuna somma di ore o percentuali e implementata.

## Menu PM e integrazione col collega

GET /api/project-options/users legge gli utenti, non li modifica. Tutti gli utenti non eliminati sono selezionabili. Restituisce solo id/first_name/last_name, ricerca per nome e cognome anche insieme, page e per_page (1-100, default 20). Accesso Admin.

Non e stato importato il branch crud e non e stato cambiato il suo contratto GET /api/users. La gestione utenti, profilo, password e l'upload avatar restano al collega. La convenzione per i file avatar e spiegata in project-user-integration.md.

## Immagini

I default SVG in public/images/defaults sono pubblici e inclusi nel repository. Non serve storage:link. Avatar, progetto e team hanno ciascuno un file predefinito. Il frontend usa l'origine del backend, non quella Angular, per i loro URL.

Loghi personalizzati: PNG/JPEG/WebP fino a 2 MB, campo form-data logo. Storage local privato projects/logos. Upload e visualizzazione riservati agli Admin. Se il file manca o non e un riferimento valido, logo_url torna al default e logo_requires_auth=false; anche la rotta logo restituisce il default.

Foto dei partecipanti: default pubblico oppure percorso privato users/avatars rispettando filestorage. La rotta avatar controlla che l'utente sia un partecipante corrente del progetto. Un file mancante usa il default. Non e implementata la modifica della foto utente.

Quando *_requires_auth=true, il frontend scarica il file con Bearer token e crea un URL locale per visualizzarlo; img src da solo non trasmette il token. I file caricati non arrivano ai colleghi con Git. Su un futuro server condiviso useranno tutti lo stesso storage.

## Delivery plan

Solo normali, facoltativo, campo multipart delivery_plan. PDF fino a 10 MB, percorso privato projects/delivery-plans salvato nel campo esistente. Lettura inline application/pdf tramite token Admin. Nessuna interpretazione del contenuto.

Se il file manca: delivery_plan_url=null e GET del documento restituisce 404. Un nuovo upload sostituisce l'associazione; il vecchio file rimane conservato. Nessuna cancellazione automatica o funzione di rimozione allegato e stata introdotta.

## CSV

Solo normali. Campo multipart csv, estensione .csv, tipi MIME testuali consentiti, massimo 10 MB. XLS/XLSX non sono CSV e non sono accettati come tali. File privato sprints/csv; percorso nella nuova colonna sprints.csv_path. Il file non viene letto per popolare dati o calcolare statistiche.

GET /csv restituisce data e counts per uploaded, missing, not_required, pending. La classificazione verifica la presenza reale sullo storage:

| Condizione | Stato |
|---|---|
| File realmente presente | uploaded |
| File assente e sprint fatturazione | not_required |
| File assente, non fatturazione, concluso | missing |
| File assente, non fatturazione, non concluso | pending |

Pilota richiede CSV come normale. PATCH csv-type richiede entrambi i booleani is_billing e is_pilot. false/false = normale; false/true = pilota; true/false = fatturazione; true/true e rifiutato. Cambiare tipo non cancella un CSV gia caricato. Lo sprint di fatturazione non accetta nuovi upload; un file preesistente puo restare scaricabile e classificato uploaded.

has_csv storico senza file reale non basta per considerarlo caricato. Upload aggiorna csv_path e has_csv, mentre il risultato API si basa sul file reale. Nessun riferimento viene cancellato se il file manca. Download sempre autenticato, attachment sprint-N.csv. La sostituzione conserva i vecchi file.

## Eliminazione e setup locale

DELETE esegue soft delete: il progetto esce da liste e contatori; le rotte con quel progetto restituiscono 404. Assegnazioni, sprint e file restano conservati. I nomi rimangono riservati nello stesso tipo anche dopo la cancellazione. Nessuna rotta di ripristino progetto e stata aggiunta.

In questa fase tutti usano dati di esempio: e consentito ricreare il proprio database locale con php artisan migrate:fresh --seed. Questo elimina tabelle/dati/token e riesegue migration e DatabaseSeeder; non elimina i file dal disco. Farlo solo sul database di sviluppo, mai per aggiornare dati reali da conservare.

I seeder generano 5 utenti, 2 progetti normali, 1 timesheet, 11 assegnazioni e 4 sprint. Gli sprint seed sono storici, quindi una review futura nulla e normale. Non sono previsti file CSV/PDF personalizzati gia caricati.

Limiti PHP e riavvio server: vedere uploads.md. Nessuna modifica .env viene distribuita attraverso questa guida.

## Verifica Figma e deviazioni concordate

| Funzione | Esito |
|---|---|
| Due tab, contatori, CRUD, filtri e paginazione | Implementati |
| Note e indicazione presenza tramite note | Implementati nel backend |
| Partecipanti con incarichi e immagini | Lettura implementata; upload profilo esterno |
| Logo e delivery plan | Implementati tramite upload separati |
| CSV e indicatore mancanti | Implementati come allegati, non importazione |
| Prossima review | Derivata dalla fine sprint secondo regola concordata |
| Nomi/link Azure | Implementati |
| Conteggi bug/PBI e rework automatici | Esclusi intenzionalmente |
| Creazione corsi | Fuori ambito, schermate di creazione non disponibili |
| Gestione team completa | Da affrontare dopo Gestione progetti |

Scelte da comunicare al frontend: PM modificati solo in Gestione team; Scrum Master assegnato li come TL; PDF come unico delivery plan; nome team Azure facoltativo nonostante l'asterisco del mockup; soft delete; nota massimo 255; nomi univoci nello stesso tipo; nessun cambio tipo dopo creazione. I contatori globali non seguono i filtri.

## Verifica finale

La suite automatica Pest contiene i controlli su database/storage simulati per casi non riproducibili dalle sole API di progetto, inclusi confini temporali, assegnazioni e file mancanti. Le prove manuali vanno ancora eseguite con postman/Gestione-progetti-completa.postman_collection.json: istruzioni in postman/README.md, indice in postman/indice-prove.md. Non e necessario eseguire o modificare Gestione team per lanciare la collection.

Nessun commit, push o merge deve essere eseguito senza richiesta esplicita dell'utente.
