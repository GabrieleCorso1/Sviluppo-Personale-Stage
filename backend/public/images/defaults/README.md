# Immagini predefinite condivise

I file in backend/public/images/defaults sono asset versionati: dopo commit e push, il pull li rende disponibili a tutti. Non e necessario storage:link per questi file.

URL relativi al server Laravel:
- /images/defaults/default-avatar.svg
- /images/defaults/default-project.svg
- /images/defaults/default-team.svg

Il frontend deve anteporre l'origine del backend (in locale http://127.0.0.1:8000), non quella del server Angular. Questi URL sono pubblici e non richiedono token.

Convenzione dei record filestorage per i valori predefiniti:
- path: images/defaults (relativo alla directory public)
- name: nome del file senza estensione, ad esempio default-avatar
- extension: svg
- type: image

FileStorageSeeder aggiorna o inserisce i tre record usando name come chiave. Per riallineare un database esistente senza ricrearlo, dalla cartella backend:

    php artisan db:seed --class=FileStorageSeeder

Gli ID esistenti restano invariati; non serve migrate:fresh. Il seeder non aggiunge immagini fisiche: sono gia presenti in Git.

Se manca un'immagine personalizzata, usare il relativo URL predefinito. Gli SVG inclusi sono segnaposto e possono essere sostituiti mantenendo i nomi. La gestione utenti potra usare default-avatar senza modifiche al suo modello.

Gli upload personalizzati sono separati: al momento il logo progetto resta nello storage privato local e viene letto tramite GET /api/projects/{project}/logo con Bearer Admin. Questo endpoint restituisce il logo predefinito se manca l'associazione o se punta a default-project, anche per i vecchi record seed. Non memorizzare gli upload in questa cartella: non vengono distribuiti tramite pull.
