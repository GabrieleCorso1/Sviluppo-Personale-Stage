import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Subject, catchError, map, of, startWith, switchMap } from 'rxjs';
import { Notifications } from '../../core/services/notifications';
import { NOTIFICATION_EXAMPLES } from './notification-examples';

@Component({
  selector: 'app-notification-case',
  imports: [DatePipe, RouterLink],
  templateUrl: './notification-case.html',
  styleUrl: './notification-case.css',
})
export class NotificationCase {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(Notifications);
  private readonly destroyRef = inject(DestroyRef);
  private readonly reload = new Subject<void>();
  protected readonly sending = signal(false);
  protected readonly actionError = signal('');
  protected readonly answer = signal('');
  protected readonly answered = signal(false);

  protected readonly state = toSignal(this.route.paramMap.pipe(
    switchMap(params => {
      this.answer.set('');
      this.answered.set(false);
      this.actionError.set('');
      const category = params.get('category');
      const id = params.get('id') ?? '';
      const example = NOTIFICATION_EXAMPLES.find(item => item.category === category && item.id === id);
      if (example) return of({ kind: 'example', example } as const);

      return this.reload.pipe(startWith(undefined), switchMap(() => this.api.show(id).pipe(
        switchMap(item => {
          if (item.data.category !== category) return of({ kind: 'error' } as const);
          const result = { kind: 'notification', item, project: null } as const;
          const projectId = item.data.project_id;
          if (!projectId) return of(result);
          return this.api.projectDetails(projectId, item.data.category === 'timesheet').pipe(
            map(({ data }) => ({ ...result, project: data })),
            catchError(() => of(result)),
            startWith(result),
          );
        }),
        catchError(() => of({ kind: 'error' } as const)),
        startWith({ kind: 'loading' } as const),
      )));
    }),
  ), { initialValue: { kind: 'loading' } as const });

  protected readonly heading = computed(() => {
    const state = this.state();
    if (state.kind === 'example') return state.example.title;
    if (state.kind === 'notification') {
      if (state.project) return state.project.alias;
      return state.item.category_label ?? 'Dettaglio notifica';
    }
    return 'Dettaglio notifica';
  });

  protected decide(id: string, status: 'approved' | 'rejected'): void {
    this.sending.set(true);
    this.actionError.set('');
    this.api.decide(id, status).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: () => {
        this.sending.set(false);
        this.api.refreshLists();
        if (this.route.snapshot.paramMap.get('id') === id) this.reload.next();
      },
      error: (error: HttpErrorResponse) => {
        this.sending.set(false);
        if (this.route.snapshot.paramMap.get('id') !== id) return;
        if (error.status === 409) this.reload.next();
        const validation = Object.values(error.error?.errors ?? {}).flat()[0];
        this.actionError.set(typeof validation === 'string' ? validation : error.status === 409 ? 'La richiesta è già stata gestita.' : 'Operazione non riuscita.');
      },
    });
  }
}
