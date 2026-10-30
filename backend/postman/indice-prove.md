# Indice prove Postman

## 01 - Login e preparazione

Eseguire per prima. Usa solo account di esempio. Non azzera il database.

- 001 - Login Admin e nuova esecuzione — POST {{base_url}}/api/login
- 002 - Profilo Admin e ID primo PM — GET {{base_url}}/api/user
- 003 - Login non Admin — POST {{base_url}}/api/login
- 004 - Verifica account non Admin — GET {{base_url}}/api/user
- 005 - Trova Portale Clienti dei seeder — GET {{base_url}}/api/projects?is_timesheet=0&search=Portale%20Clienti
- 006 - Trova API Aziendali dei seeder — GET {{base_url}}/api/projects?is_timesheet=0&search=API%20Aziendali
- 007 - Trova sprint di prova CSV e conserva il tipo — GET {{base_url}}/api/projects/{{csv_project_id}}/csv

## 02 - Creazione e validazione

Crea tre record di prova con nomi distinti per esecuzione.

- 008 - Crea progetto normale e prima assegnazione PM — POST {{base_url}}/api/projects
- 009 - Crea timesheet con lo stesso nome di un progetto normale — POST {{base_url}}/api/projects
- 010 - Crea secondo timesheet per verifica duplicati — POST {{base_url}}/api/projects
- 011 - Duplicato normale nello stesso tipo — POST {{base_url}}/api/projects
- 012 - Duplicato timesheet nello stesso tipo — POST {{base_url}}/api/projects
- 013 - Normale senza quattro campi obbligatori — POST {{base_url}}/api/projects
- 014 - Tipo mancante — POST {{base_url}}/api/projects
- 015 - Normale: valore non valido project_manager_id — POST {{base_url}}/api/projects
- 016 - Normale: valore non valido status — POST {{base_url}}/api/projects
- 017 - Normale: valore non valido priority — POST {{base_url}}/api/projects
- 018 - Normale: valore non valido start_date — POST {{base_url}}/api/projects
- 019 - Normale: valore non valido description — POST {{base_url}}/api/projects
- 020 - Normale: valore non valido bug_count_url — POST {{base_url}}/api/projects
- 021 - Timesheet rifiuta project_manager_id — POST {{base_url}}/api/projects
- 022 - Timesheet rifiuta start_date — POST {{base_url}}/api/projects
- 023 - Timesheet rifiuta status — POST {{base_url}}/api/projects
- 024 - Timesheet rifiuta priority — POST {{base_url}}/api/projects
- 025 - Timesheet rifiuta is_daily — POST {{base_url}}/api/projects
- 026 - Timesheet rifiuta is_azure — POST {{base_url}}/api/projects
- 027 - Timesheet rifiuta is_ux_ui — POST {{base_url}}/api/projects
- 028 - Timesheet rifiuta delivery_plan — POST {{base_url}}/api/projects
- 029 - Creazione corso vietata — POST {{base_url}}/api/projects

## 03 - Dettaglio e modifica

Aggiornamenti soltanto sui record creati dalla collection.

- 030 - Dettaglio normale e default — GET {{base_url}}/api/projects/{{project_id}}
- 031 - Dettaglio timesheet — GET {{base_url}}/api/projects/{{timesheet_id}}
- 032 - ID progetto inesistente — GET {{base_url}}/api/projects/999999999
- 033 - Modifica nome priorita e checkbox — PATCH {{base_url}}/api/projects/{{project_id}}
- 034 - Modifica nome con lo stesso valore consentita — PATCH {{base_url}}/api/projects/{{project_id}}
- 035 - Modifica normale rifiuta alias — PATCH {{base_url}}/api/projects/{{project_id}}
- 036 - Modifica normale rifiuta priority — PATCH {{base_url}}/api/projects/{{project_id}}
- 037 - Modifica normale rifiuta status — PATCH {{base_url}}/api/projects/{{project_id}}
- 038 - Modifica normale rifiuta start_date — PATCH {{base_url}}/api/projects/{{project_id}}
- 039 - Modifica normale rifiuta is_timesheet — PATCH {{base_url}}/api/projects/{{project_id}}
- 040 - Modifica normale rifiuta is_course — PATCH {{base_url}}/api/projects/{{project_id}}
- 041 - Modifica normale rifiuta project_manager_id — PATCH {{base_url}}/api/projects/{{project_id}}
- 042 - Timesheet modifica descrizione e dati Azure — PATCH {{base_url}}/api/projects/{{timesheet_id}}
- 043 - Timesheet svuota descrizione — PATCH {{base_url}}/api/projects/{{timesheet_id}}
- 044 - Timesheet rifiuta stato anche in modifica — PATCH {{base_url}}/api/projects/{{timesheet_id}}
- 045 - Modifica rifiuta duplicato nello stesso tipo — PATCH {{base_url}}/api/projects/{{aux_timesheet_id}}

## 04 - Liste contatori e menu PM

Tutte richieste di lettura.

- 046 - Lista normali e contatori di riferimento — GET {{base_url}}/api/projects?is_timesheet=0
- 047 - Lista timesheet — GET {{base_url}}/api/projects?is_timesheet=1
- 048 - Ricerca nome normale — GET {{base_url}}/api/projects?is_timesheet=0&search={{run_id}}
- 049 - Ricerca senza risultati e contatori invariati — GET {{base_url}}/api/projects?is_timesheet=0&search=nessun-risultato-{{run_id}}
- 050 - Nome A-Z — GET {{base_url}}/api/projects?is_timesheet=0&sort_direction=asc
- 051 - Nome Z-A — GET {{base_url}}/api/projects?is_timesheet=0&sort_direction=desc
- 052 - Paginazione due elementi — GET {{base_url}}/api/projects?is_timesheet=0&per_page=2&page=1
- 053 - Stato priorita nome e PM combinati — GET {{base_url}}/api/projects?is_timesheet=0&status=in%20corso&priority=alta&project_manager_id={{project_manager_id}}&search={{run_id}}
- 054 - Partecipanti seed e ID Luca — GET {{base_url}}/api/projects/{{seed_project_id}}/users?search=Luca
- 055 - Filtro team_leader_id — GET {{base_url}}/api/projects?is_timesheet=0&team_leader_id={{participant_id}}
- 056 - Filtro scrum_master_id — GET {{base_url}}/api/projects?is_timesheet=0&scrum_master_id={{participant_id}}
- 057 - Menu PM ricerca nome e cognome — GET {{base_url}}/api/project-options/users?search=Mario%20Rossi&per_page=10
- 058 - Parametro elenco non valido: sort_direction / is_timesheet=0&sort_direction=xyz — GET {{base_url}}/api/projects?is_timesheet=0&sort_direction=xyz
- 059 - Parametro elenco non valido: per_page / is_timesheet=0&per_page=101 — GET {{base_url}}/api/projects?is_timesheet=0&per_page=101
- 060 - Parametro elenco non valido: page / is_timesheet=0&page=0 — GET {{base_url}}/api/projects?is_timesheet=0&page=0
- 061 - Parametro elenco non valido: status / is_timesheet=0&status=errato — GET {{base_url}}/api/projects?is_timesheet=0&status=errato
- 062 - Parametro elenco non valido: priority / is_timesheet=0&priority=errata — GET {{base_url}}/api/projects?is_timesheet=0&priority=errata
- 063 - Parametro elenco non valido: team_leader_id / is_timesheet=0&team_leader_id=999999999 — GET {{base_url}}/api/projects?is_timesheet=0&team_leader_id=999999999
- 064 - Parametro elenco non valido: status / is_timesheet=1&status=in%20corso — GET {{base_url}}/api/projects?is_timesheet=1&status=in%20corso
- 065 - Parametro elenco non valido: priority / is_timesheet=1&priority=alta — GET {{base_url}}/api/projects?is_timesheet=1&priority=alta
- 066 - Parametro elenco non valido: project_manager_id / is_timesheet=1&project_manager_id={{project_manager_id}} — GET {{base_url}}/api/projects?is_timesheet=1&project_manager_id={{project_manager_id}}
- 067 - Parametro elenco non valido: is_course / is_timesheet=0&is_course=true — GET {{base_url}}/api/projects?is_timesheet=0&is_course=true
- 068 - Elenco senza tipo — GET {{base_url}}/api/projects

## 05 - Note e partecipanti

Non modifica gli incarichi degli utenti.

- 069 - Salva nota project_id — PATCH {{base_url}}/api/projects/{{project_id}}/note
- 070 - Svuota nota project_id — PATCH {{base_url}}/api/projects/{{project_id}}/note
- 071 - Salva nota timesheet_id — PATCH {{base_url}}/api/projects/{{timesheet_id}}/note
- 072 - Svuota nota timesheet_id — PATCH {{base_url}}/api/projects/{{timesheet_id}}/note
- 073 - Nota mancante — PATCH {{base_url}}/api/projects/{{project_id}}/note
- 074 - Nota troppo lunga — PATCH {{base_url}}/api/projects/{{project_id}}/note
- 075 - Partecipanti progetto seed — GET {{base_url}}/api/projects/{{seed_project_id}}/users
- 076 - Avatar partecipante corrente — GET {{base_url}}/api/projects/{{seed_project_id}}/users/{{participant_id}}/avatar
- 077 - Utente inesistente non leggibile come partecipante — GET {{base_url}}/api/projects/{{seed_project_id}}/users/999999999/avatar
- 078 - Timesheet di prova senza assegnazioni — GET {{base_url}}/api/projects/{{timesheet_id}}/users

## 06 - Immagini e delivery plan

Richiede Working Directory Postman impostata alla cartella backend. Gli upload usano i file in postman/fixtures.

- 079 - Default pubblico avatar — GET {{base_url}}/images/defaults/default-avatar.svg
- 080 - Default pubblico project — GET {{base_url}}/images/defaults/default-project.svg
- 081 - Default pubblico team — GET {{base_url}}/images/defaults/default-team.svg
- 082 - Logo di default via rotta protetta — GET {{base_url}}/api/projects/{{project_id}}/logo
- 083 - Carica PNG su project_id — POST {{base_url}}/api/projects/{{project_id}}/logo
- 084 - Leggi PNG su project_id — GET {{base_url}}/api/projects/{{project_id}}/logo
- 085 - Carica PNG su timesheet_id — POST {{base_url}}/api/projects/{{timesheet_id}}/logo
- 086 - Leggi PNG su timesheet_id — GET {{base_url}}/api/projects/{{timesheet_id}}/logo
- 087 - Sostituisci logo normale — POST {{base_url}}/api/projects/{{project_id}}/logo
- 088 - Logo: rifiuta CSV — POST {{base_url}}/api/projects/{{project_id}}/logo
- 089 - Logo: file obbligatorio — POST {{base_url}}/api/projects/{{project_id}}/logo
- 090 - Delivery assente — GET {{base_url}}/api/projects/{{project_id}}/delivery-plan
- 091 - Carica PDF — POST {{base_url}}/api/projects/{{project_id}}/delivery-plan
- 092 - Leggi PDF — GET {{base_url}}/api/projects/{{project_id}}/delivery-plan
- 093 - Sostituisci PDF — POST {{base_url}}/api/projects/{{project_id}}/delivery-plan
- 094 - Delivery rifiuta PNG — POST {{base_url}}/api/projects/{{project_id}}/delivery-plan
- 095 - Delivery richiede il file — POST {{base_url}}/api/projects/{{project_id}}/delivery-plan
- 096 - Timesheet rifiuta delivery plan — POST {{base_url}}/api/projects/{{timesheet_id}}/delivery-plan
- 097 - Timesheet non ha lettura delivery — GET {{base_url}}/api/projects/{{timesheet_id}}/delivery-plan

## 07 - CSV degli sprint

Usa uno sprint concluso di API Aziendali: cambia temporaneamente il tipo e carica/sostituisce il CSV dimostrativo. Ripristina i flag originali, ma il nuovo allegato resta associato.

- 098 - Imposta sprint normale — PATCH {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv-type
- 099 - Imposta sprint pilota — PATCH {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv-type
- 100 - Tipi incompatibili — PATCH {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv-type
- 101 - Conferma fatturazione senza file — PATCH {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv-type
- 102 - Upload vietato su fatturazione — POST {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv
- 103 - Ripristina tipo normale per upload — PATCH {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv-type
- 104 - Carica CSV come allegato — POST {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv
- 105 - Scarica CSV — GET {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv
- 106 - Sostituisci CSV — POST {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv
- 107 - CSV richiede file — POST {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv
- 108 - CSV rifiuta PNG — POST {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv
- 109 - CSV lista gruppi e contatori — GET {{base_url}}/api/projects/{{csv_project_id}}/csv
- 110 - CSV non accessibile da altro progetto — GET {{base_url}}/api/projects/{{project_id}}/sprints/{{csv_sprint_id}}/csv
- 111 - Tipo sprint non modificabile da altro progetto — PATCH {{base_url}}/api/projects/{{project_id}}/sprints/{{csv_sprint_id}}/csv-type
- 112 - CSV non previsto nel tab Timesheet — GET {{base_url}}/api/projects/{{timesheet_id}}/csv
- 113 - Ripristina flag originali dello sprint seed — PATCH {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv-type

## 08 - Permessi su ogni funzione

Richieste intenzionalmente negate: 401 senza token e 403 con utente non Admin. Non cambiano dati.

- 114 - elenco senza token — GET {{base_url}}/api/projects?is_timesheet=0
- 115 - elenco non Admin — GET {{base_url}}/api/projects?is_timesheet=0
- 116 - creazione senza token — POST {{base_url}}/api/projects
- 117 - creazione non Admin — POST {{base_url}}/api/projects
- 118 - dettaglio senza token — GET {{base_url}}/api/projects/{{project_id}}
- 119 - dettaglio non Admin — GET {{base_url}}/api/projects/{{project_id}}
- 120 - modifica senza token — PATCH {{base_url}}/api/projects/{{project_id}}
- 121 - modifica non Admin — PATCH {{base_url}}/api/projects/{{project_id}}
- 122 - eliminazione senza token — DELETE {{base_url}}/api/projects/{{project_id}}
- 123 - eliminazione non Admin — DELETE {{base_url}}/api/projects/{{project_id}}
- 124 - nota senza token — PATCH {{base_url}}/api/projects/{{project_id}}/note
- 125 - nota non Admin — PATCH {{base_url}}/api/projects/{{project_id}}/note
- 126 - partecipanti senza token — GET {{base_url}}/api/projects/{{seed_project_id}}/users
- 127 - partecipanti non Admin — GET {{base_url}}/api/projects/{{seed_project_id}}/users
- 128 - menu PM senza token — GET {{base_url}}/api/project-options/users
- 129 - menu PM non Admin — GET {{base_url}}/api/project-options/users
- 130 - lettura logo senza token — GET {{base_url}}/api/projects/{{project_id}}/logo
- 131 - lettura logo non Admin — GET {{base_url}}/api/projects/{{project_id}}/logo
- 132 - upload logo senza token — POST {{base_url}}/api/projects/{{project_id}}/logo
- 133 - upload logo non Admin — POST {{base_url}}/api/projects/{{project_id}}/logo
- 134 - lettura delivery senza token — GET {{base_url}}/api/projects/{{project_id}}/delivery-plan
- 135 - lettura delivery non Admin — GET {{base_url}}/api/projects/{{project_id}}/delivery-plan
- 136 - upload delivery senza token — POST {{base_url}}/api/projects/{{project_id}}/delivery-plan
- 137 - upload delivery non Admin — POST {{base_url}}/api/projects/{{project_id}}/delivery-plan
- 138 - elenco CSV senza token — GET {{base_url}}/api/projects/{{csv_project_id}}/csv
- 139 - elenco CSV non Admin — GET {{base_url}}/api/projects/{{csv_project_id}}/csv
- 140 - upload CSV senza token — POST {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv
- 141 - upload CSV non Admin — POST {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv
- 142 - download CSV senza token — GET {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv
- 143 - download CSV non Admin — GET {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv
- 144 - tipo CSV senza token — PATCH {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv-type
- 145 - tipo CSV non Admin — PATCH {{base_url}}/api/projects/{{csv_project_id}}/sprints/{{csv_sprint_id}}/csv-type
- 146 - avatar senza token — GET {{base_url}}/api/projects/{{seed_project_id}}/users/{{participant_id}}/avatar
- 147 - avatar non Admin — GET {{base_url}}/api/projects/{{seed_project_id}}/users/{{participant_id}}/avatar

## 09 - Soft delete dei soli progetti di prova

Elimina solo gli ID creati dalla collection. Non elimina utenti, progetti seed o sprint. Gli allegati restano su disco e i nomi dei record eliminati restano riservati.

- 148 - Elimina project_id — DELETE {{base_url}}/api/projects/{{project_id}}
- 149 - Dettaglio eliminato project_id — GET {{base_url}}/api/projects/{{project_id}}
- 150 - Elimina timesheet_id — DELETE {{base_url}}/api/projects/{{timesheet_id}}
- 151 - Dettaglio eliminato timesheet_id — GET {{base_url}}/api/projects/{{timesheet_id}}
- 152 - Elimina aux_timesheet_id — DELETE {{base_url}}/api/projects/{{aux_timesheet_id}}
- 153 - Dettaglio eliminato aux_timesheet_id — GET {{base_url}}/api/projects/{{aux_timesheet_id}}
- 154 - Elenco esclude i normali eliminati — GET {{base_url}}/api/projects?is_timesheet=0&search={{run_id}}
- 155 - Elenco esclude i timesheet eliminati — GET {{base_url}}/api/projects?is_timesheet=1&search={{run_id}}
- 156 - Nome normale eliminato resta riservato — POST {{base_url}}/api/projects
- 157 - Nome timesheet eliminato resta riservato — POST {{base_url}}/api/projects
- 158 - Logo progetto eliminato non accessibile — GET {{base_url}}/api/projects/{{project_id}}/logo
- 159 - Delivery progetto eliminato non accessibile — GET {{base_url}}/api/projects/{{project_id}}/delivery-plan

## 10 - Logout

Revoca solo i due token creati per questa esecuzione.

- 160 - Logout non Admin — POST {{base_url}}/api/logout
- 161 - Token non Admin revocato — GET {{base_url}}/api/user
- 162 - Logout Admin — POST {{base_url}}/api/logout
- 163 - Token Admin revocato — GET {{base_url}}/api/user
