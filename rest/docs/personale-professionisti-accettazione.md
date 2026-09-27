# Personale e Accettazione

Il Personale è la fonte per nome, cognome e collegamento agenda. Medici e infermieri attivi sono disponibili nelle prestazioni; altri ruoli possono essere abilitati esplicitamente, anche senza agenda. Le specialità sono facoltative nei moduli Personale e disponibili nelle prestazioni. Anche la branca della prestazione è facoltativa.

Le schede di inserimento e modifica usano un selettore con ricerca, checkbox multiple e riepilogo delle scelte rimovibili. «Gestisci specialità» apre l'anagrafica condivisa in una nuova scheda; al ritorno l'elenco si aggiorna mantenendo le selezioni non ancora salvate. È disponibile anche «Aggiorna elenco». Il campo salva gli ID del catalogo, non crea voci dai nomi digitati. Le specialità archiviate già assegnate restano visibili e possono essere mantenute o rimosse; non sono assegnabili ad altre persone. Il server valida tipo, appartenenza al catalogo dello spazio e limite di 15 scelte. Il vecchio formato testuale rimane accettato solo per compatibilità con form già aperti prima dell'aggiornamento. Nessuna migrazione o modifica dei documenti storici.

I campi sono visibili negli spazi con funzioni amministrative opzionali attive e schema già installato. Nessuna modifica strutturale o migrazione dei dati: i metadati usano pc_catalog. Le letture non scrivono. I nuovi collegamenti vengono salvati solo durante azioni esplicite, sotto blocco transazionale dello spazio.

I professionisti precedenti sono abbinati automaticamente solo tramite un collegamento agenda univoco, mai per nome. Per gli altri, cercare la persona in Personale e usare «Collega un professionista già inserito». Si conserva il precedente identificativo: fatture, prestazioni, liquidazioni e snapshot storici non vengono riscritti. Le voci non collegate restano indicate come «da collegare al personale». La disattivazione o cancellazione del personale impedisce nuove operazioni senza cancellare lo storico.

Accettazione è una voce principale su admin/accettazione; i vecchi collegamenti rimandano al nuovo indirizzo. Branche e Professionisti non sono più sottovoci di Fatturazione. Le specialità si gestiscono dal Personale. Il vecchio catalogo professionisti rimanda al Personale.

Verifiche: test SQLite isolati su abbinamenti, storico, versioni, arrivi da personale, collaboratori senza agenda, branche facoltative; test browser sintetici dei moduli e della navigazione. Nessun test di scrittura sul database live.
