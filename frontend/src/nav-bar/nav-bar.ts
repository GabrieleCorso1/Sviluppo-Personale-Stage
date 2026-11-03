
import { Component } from '@angular/core';

@Component({
  selector: 'app-root',
  standalone: true,
  templateUrl: './app.html',
  styleUrl: './app.css',
})
export class App {
  searchText = '';

  readonly sections = [
    {
      title: 'Organizzazione',
      cards: [
        {
          title: 'Progetti / Corsi',
          description: 'Crea e gestisci progetti, corsi e relative informazioni.',
          count: '18 progetti',
          icon: 'bi-folder',
          color: 'blue',
        },
        {
          title: 'Team',
          description: 'Crea team e assegna collaboratori.',
          count: '9 team',
          icon: 'bi-people-fill',
          color: 'blue',
        },
        {
          title: 'Tipologie team',
          description: 'Definisci le tipologie di team disponibili nel sistema.',
          count: '7 tipologie',
          icon: 'bi-diagram-3',
          color: 'blue',
        },
        {
          title: 'Risorse',
          description: 'Assegna le risorse ai progetti e monitora il carico di lavoro.',
          count: '29 risorse allocate',
          icon: 'bi-person-workspace',
          color: 'blue',
        },
        {
          title: 'Esempio 1',
          description: 'Crea e gestisci progetti, corsi e relative informazioni.',
          count: '18 progetti',
          icon: 'bi-folder',
          color: 'blue',
        },
        {
          title: 'Esempio 2',
          description: 'Crea team e assegna collaboratori.',
          count: '9 team',
          icon: 'bi-people-fill',
          color: 'blue',
        },
      ],
    },
    {
      title: 'Persone',
      cards: [
        {
          title: 'Utenti',
          description: 'Crea e gestisci utenti, ruoli e permessi di accesso.',
          count: '62 utenti',
          icon: 'bi-person',
          color: 'green',
        },
        {
          title: 'Professori',
          description: 'Gestisci i professori e le loro informazioni.',
          count: '4 professori',
          icon: 'bi-mortarboard',
          color: 'green',
        },
        {
          title: 'Competenze trasversali',
          description: 'Gestisci le competenze trasversali del catalogo.',
          count: '23 competenze',
          icon: 'bi-globe-europe-africa',
          color: 'green',
        },
      ],
    },
    {
      title: 'Contenuti',
      cards: [
        {
          title: 'Tecnologie',
          description: 'Gestisci le tecnologie disponibili nel catalogo.',
          count: 'Tecnologie del catalogo',
          icon: 'bi-code-slash',
          color: 'orange',
        },
        {
          title: 'Hobby',
          description: 'Gestisci gli hobby e gli interessi.',
          count: 'Hobby disponibili',
          icon: 'bi-controller',
          color: 'orange',
        },
        {
          title: 'Domande sondaggi',
          description: 'Gestisci le domande dei sondaggi.',
          count: 'Domande disponibili',
          icon: 'bi-ui-checks',
          color: 'orange',
        },
      ],
    },
  ];

  get filteredSections() {
    const query = this.searchText.trim().toLocaleLowerCase('it');

    return this.sections
      .map((section) => ({
        ...section,
        cards: section.cards.filter((card) =>
          `${card.title} ${card.description} ${card.count}`
            .toLocaleLowerCase('it')
            .includes(query),
        ),
      }))
      .filter((section) => section.cards.length > 0);
  }
}
