
import { Component, DestroyRef, inject, OnInit, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, Observable } from 'rxjs';
import { API_URL } from '../../core/auth-http';
import { Auth, canAccessManagement } from '../../core/services/auth';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ProfileAvatar } from '../../core/services/profile-avatar';
import { Router } from '@angular/router';
import { Layout } from '../../components/layout/layout';

@Component({
  selector: 'app-impostazioni',
  standalone: true,
  imports: [Layout],
  templateUrl: './impostazioni.html',
  styleUrl: './impostazioni.css',
})
export class Impostazioni implements OnInit {
  private readonly avatar = inject(ProfileAvatar);
  protected readonly avatarUrl = this.avatar.url;
  private readonly avatarLoad = takeUntilDestroyed();
  private readonly http = inject(HttpClient);
  private readonly destroyRef = inject(DestroyRef);
  private readonly liveCounts = signal<Record<string, string>>({});
  private readonly auth = inject(Auth);
  private readonly managementAreas = { Progetti: 'projects', Utenti: 'users', Allocazioni: 'allocations' } as const;

  ngOnInit(): void {
    this.avatar.load().pipe(this.avatarLoad).subscribe({ error: () => {} });
    if (canAccessManagement(this.auth.currentUser(), 'projects')) this.loadCount('Progetti', this.http.get<{ counts: { projects: number; timesheets: number } }>(
      API_URL + '/api/projects', { params: { is_timesheet: 0, per_page: 1 } },
    ).pipe(map(response => response.counts.projects + response.counts.timesheets)),
    count => this.auth.currentUser()?.roles.includes('Admin') ? `${count} progetti e timesheet` : `${count} progetti assegnati`);
    if (canAccessManagement(this.auth.currentUser(), 'users')) this.loadCount('Utenti', this.http.get<{ data: { total: number } }>(
      API_URL + '/api/users', { params: { status: 'active', per_page: 1 } },
    ).pipe(map(response => response.data.total)),
    count => `${count} ${count === 1 ? 'utente attivo' : 'utenti attivi'}`);
    if (canAccessManagement(this.auth.currentUser(), 'allocations')) this.loadCount('Allocazioni', this.http.get<{ data: { allocated_resources: number } }>(
      API_URL + '/api/allocations/summary',
    ).pipe(map(response => response.data.allocated_resources)),
    count => `${count} ${count === 1 ? 'persona assegnata' : 'persone assegnate'}`);
  }

  private loadCount(title: string, source: Observable<number>, label: (count: number) => string): void {
    source.pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: count => this.liveCounts.update(counts => ({ ...counts, [title]: label(count) })),
      error: () => this.liveCounts.update(counts => ({ ...counts, [title]: 'Non disponibile' })),
    });
  }

  cardCount(card: { title: string; count: string }): string {
    return this.liveCounts()[card.title] ?? card.count;
  }

  protected readonly router = inject(Router);
  searchText = '';
  openCard(title: string): void {
    const paths: Record<string, string> = { 'Utenti': '/gestione-utenti', 'Progetti': '/gestione-progetti', 'Allocazioni': '/gestione-allocazioni' };
    if (paths[title]) void this.router.navigate([paths[title]]);
  }

  readonly sections = [
    {
      title: 'Gestione piattaforma',
      cards: [
        {
          title: 'Progetti',
          description: 'Crea e gestisci progetti, timesheet e relative informazioni.',
          count: 'Caricamento...',
          icon: 'bi-folder',
          countIcon: 'folder.png',
          color: 'blue',
        },
        {
          title: 'Utenti',
          description: 'Crea e gestisci utenti, ruoli e permessi di accesso.',
          count: 'Caricamento...',
          icon: 'bi-person',
          countIcon: 'person.png',
          color: 'green',
        },
        {
          title: 'Allocazioni',
          description: 'Assegna le risorse ai progetti e monitora il carico di lavoro.',
          count: 'Caricamento...',
          icon: 'bi-person-workspace',
          countIcon: 'perm_contact_calendar.png',
          color: 'blue',
        },
      ],
    },
    {
      title: 'Esempi',
      cards: [
        {
          title: 'Team',
          description: 'Crea team e assegna collaboratori.',
          count: '9 team',
          icon: 'bi-people-fill',
          countIcon: 'groups.png',
          color: 'blue',
        },
        {
          title: 'Tipologie team',
          description: 'Definisci le tipologie di team disponibili nel sistema.',
          count: '7 tipologie',
          icon: 'bi-diagram-3',
          countIcon: 'account_tree.png',
          color: 'blue',
        },
        {
          title: 'Esempio 1',
          description: 'Crea e gestisci progetti, corsi e relative informazioni.',
          count: '18 progetti',
          icon: 'bi-folder',
          countIcon: 'folder.png',
          color: 'blue',
        },
        {
          title: 'Esempio 2',
          description: 'Crea team e assegna collaboratori.',
          count: '9 team',
          icon: 'bi-people-fill',
          countIcon: 'groups.png',
          color: 'blue',
        },
        {
          title: 'Professori',
          description: 'Gestisci i professori e le loro informazioni.',
          count: '4 professori',
          icon: 'bi-mortarboard',
          countIcon: 'school.png',
          color: 'green',
        },
        {
          title: 'Competenze trasversali',
          description: 'Gestisci le competenze trasversali del catalogo.',
          count: '23 competenze',
          icon: 'bi-globe-europe-africa',
          countIcon: 'diamante.png',
          color: 'green',
        },
        {
          title: 'Tecnologie',
          description: 'Gestisci le tecnologie disponibili nel catalogo.',
          count: 'Tecnologie del catalogo',
          icon: 'bi-code-slash',
          countIcon: 'code.png',
          color: 'orange',
        },
        {
          title: 'Hobby',
          description: 'Gestisci gli hobby e gli interessi.',
          count: 'Hobby disponibili',
          icon: 'bi-controller',
          countIcon: 'stadia_controller.png',
          color: 'orange',
        },
        {
          title: 'Domande sondaggi',
          description: 'Gestisci le domande dei sondaggi.',
          count: 'Domande disponibili',
          icon: 'bi-ui-checks',
          countIcon: 'person_raised_hand.png',
          color: 'orange',
        },
      ],
    },
  ];

  get filteredSections() {
    const query = this.searchText.trim().toLocaleLowerCase('it');

    return this.sections
      .filter(section => section.title !== 'Esempi' || this.auth.currentUser()?.roles.includes('Admin'))
      .map((section) => ({
        ...section,
        cards: section.cards.filter((card) => {
          const area = this.managementAreas[card.title as keyof typeof this.managementAreas];
          return (!area || canAccessManagement(this.auth.currentUser(), area)) &&
          `${card.title} ${card.description} ${this.cardCount(card)}`
            .toLocaleLowerCase('it')
            .includes(query);
        }),
      }))
      .filter((section) => section.cards.length > 0);
  }
}
