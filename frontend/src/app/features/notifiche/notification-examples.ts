export const NOTIFICATION_EXAMPLES = [
  {
    id: 'esempio-wiki', category: 'wiki', label: 'Wiki',
    title: 'Nuova risorsa nella Wiki', message: 'È stata pubblicata una guida nella sezione Documentazione.',
    description: 'La guida raccoglie i primi passi per entrare in un progetto e trovare le risorse del team.',
    action: 'Apri documentazione', icon: 'bi bi-globe2',
  },
  {
    id: 'esempio-sondaggio', category: 'surveys', label: 'Sondaggi',
    title: 'Sondaggio Feedback 360°', message: 'Hai un nuovo sondaggio da compilare.',
    description: 'Valuta quanto ti sono state utili le informazioni ricevute durante il progetto.',
    action: 'Rispondi al sondaggio', icon: 'bi bi-clipboard-data',
  },
  {
    id: 'esempio-sbi', category: 'sbi', label: 'SBI',
    title: 'Nuovo feedback SBI', message: 'Hai ricevuto un feedback su una riunione di progetto.',
    description: 'Durante la riunione hai riepilogato le decisioni prima di chiudere. Il team ha potuto chiarire le responsabilità e i prossimi passi.',
    action: 'Leggi feedback SBI', icon: 'bi bi-credit-card',
  },
] as const;
