# Upload locali

Impostazioni richieste nel php.ini utilizzato dal server PHP:

    upload_max_filesize = 10M
    post_max_size = 12M

I limiti Laravel sono 2 MB per le immagini e 10 MB per PDF e CSV. post_max_size include il contenuto multipart, quindi deve essere maggiore del singolo file. Il php.ini e locale e non viene distribuito con Git.

Individuare la configurazione con php --ini e verificare i valori con php -i. Dopo averla modificata, fermare il server con Ctrl+C e riavviare php artisan serve. Server web diversi o container possono caricare un altro php.ini e avere ulteriori limiti.

I file personalizzati restano nello storage privato local. Un riferimento a un file assente non viene cancellato automaticamente: il logo usa il default, il delivery plan non espone un URL disponibile, e i CSV vengono classificati nuovamente come mancanti/non richiesti/in attesa secondo lo sprint. I contatori usano la stessa verifica sullo storage.
