import { DatePipe, DecimalPipe } from '@angular/common';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, ElementRef, computed, inject, signal, viewChild } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Layout } from '../../components/layout/layout';
import { API_URL } from '../../core/auth-http';
import { Auth, hasSettingsRole } from '../../core/services/auth';
import { NotificationItem, Notifications } from '../../core/services/notifications';
import { ProfileAvatar } from '../../core/services/profile-avatar';

type RequestType = 'Ferie' | 'Permesso';
interface CalendarEntry {
  id: number;
  type: string;
  start: string;
  end: string;
  status: string;
  person?: string;
  start_time: string | null;
  end_time: string | null;
}
interface Sprint { number: number; start: string; end: string; }
interface Project { id: number; name: string; sprints: Sprint[]; }
interface DashboardData {
  today: string;
  mode: 'admin' | 'hr' | 'manager' | 'personal';
  counts: Record<string, number>;
  projects: Project[];
  events: CalendarEntry[];
  requests: CalendarEntry[];
  pending: CalendarEntry[];
  leave: { type: string; hours: number }[];
  sbi: { received_unread: number; sent_month: number };
  interns: { id: number; first_name: string; last_name: string }[];
}

@Component({
  imports: [DatePipe, DecimalPipe, FormsModule, Layout, RouterLink],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css',
})
export class Dashboard {
  private readonly http = inject(HttpClient);
  private readonly auth = inject(Auth);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private readonly notifications = inject(Notifications);
  private readonly avatar = inject(ProfileAvatar);
  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('requestDialog');

  readonly data = signal<DashboardData | null>(null);
  readonly activities = signal<NotificationItem[]>([]);
  readonly loading = signal(false);
  readonly activitiesError = signal(false);
  readonly error = signal('');
  readonly notice = signal('');
  readonly saving = signal(false);
  readonly requestError = signal('');
  readonly avatarUrl = this.avatar.url;
  readonly settings = computed(() => {
    const user = this.auth.currentUser();
    return user !== null && hasSettingsRole(user);
  });
  readonly canSendSbi = computed(() => this.auth.currentUser()?.roles.some(role =>
    ['Admin', 'Project Manager', 'Team Leader'].includes(role)) ?? false);
  readonly projectIndex = signal(0);
  readonly project = computed(() => this.data()?.projects[this.projectIndex()]);
  readonly calendarBadges = [
    { type: 'Permesso', icon: 'clock', color: 'amber' },
    { type: 'Ferie', icon: 'airplane', color: 'green' },
  ];
  readonly statusLabels: Record<string, string> = { approved: 'Accettata', pending: 'In attesa', rejected: 'Rifiutata' };

  requestType: RequestType = 'Ferie';
  start = '';
  end = '';
  startTime = '';
  endTime = '';
  notes = '';

  constructor() {
    this.load();
    this.loadActivities();
    this.avatar.load().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({ error: () => undefined });
  }

  load() {
    if (this.loading()) return;
    this.loading.set(true);
    this.error.set('');
    this.http.get<DashboardData>(API_URL + '/api/dashboard').pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: data => {
        this.projectIndex.set(Math.min(this.projectIndex(), Math.max(0, data.projects.length - 1)));
        this.data.set(data);
        this.loading.set(false);
      },
      error: () => {
        this.error.set('Impossibile caricare la Bacheca.');
        this.loading.set(false);
      },
    });
  }

  loadActivities() {
    this.activitiesError.set(false);
    this.notifications.list({}, 1, 6).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: page => this.activities.set(page.data),
      error: () => this.activitiesError.set(true),
    });
  }

  openSettings() { void this.router.navigate(['/impostazioni']); }

  openActivity(item: NotificationItem) {
    if (!item.read_at) {
      this.notifications.mark({ ids: [item.id] }, true).subscribe({
        next: () => this.notifications.refreshLists(),
        error: () => undefined,
      });
    }
  }

  hasHolidays(entries: CalendarEntry[]) { return entries.some(entry => entry.type === 'Ferie'); }
  hasPermits(entries: CalendarEntry[]) { return entries.some(entry => entry.type === 'Permesso'); }

  daysUntil(date: string): number {
    const today = this.data()?.today ?? date;
    return Math.max(0, Math.round((Date.parse(date) - Date.parse(today)) / 86_400_000));
  }

  openRequest(type: RequestType) {
    this.requestType = type;
    this.start = this.end = this.data()?.today ?? '';
    this.startTime = this.endTime = this.notes = '';
    this.requestError.set('');
    this.notice.set('');
    this.dialog().nativeElement.showModal();
  }

  closeRequest() {
    if (!this.saving()) this.dialog().nativeElement.close();
  }

  submitRequest() {
    if (this.saving()) return;
    this.requestError.set('');
    const endDate = this.requestType === 'Permesso' ? this.start : this.end;
    if (!this.start || !endDate || endDate < this.start) {
      this.requestError.set('Controlla le date della richiesta.');
      return;
    }
    this.saving.set(true);
    this.http.post(API_URL + '/api/calendar/requests', {
      type: this.requestType,
      start_date: this.start,
      end_date: endDate,
      start_time: this.requestType === 'Permesso' ? this.startTime || null : null,
      end_time: this.requestType === 'Permesso' ? this.endTime || null : null,
      notes: this.notes.trim() || null,
    }).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: () => {
        this.saving.set(false);
        this.closeRequest();
        this.notice.set('Richiesta inviata e in attesa di approvazione.');
        this.load();
      },
      error: (error: HttpErrorResponse) => {
        this.saving.set(false);
        const first = Object.values(error.error?.errors ?? {}).flat()[0];
        this.requestError.set(typeof first === 'string' ? first : 'Impossibile inviare la richiesta. Riprova.');
      },
    });
  }
}
