import { Component, ElementRef, inject, input, output, signal } from '@angular/core';

import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { catchError, EMPTY, map, merge, of, startWith, switchMap, timer } from 'rxjs';
import { Auth } from '../../core/services/auth';
import { Notifications } from '../../core/services/notifications';
import { NotificationList } from '../../features/notifiche/notification-list';

@Component({
  selector: 'app-header',
  standalone: true,
  imports: [RouterLink, NotificationList],
  templateUrl: './header.html',
  styleUrl: './header.css',
  host: { '(document:click)': 'closeOutside($event)' },
})
export class Header {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  protected readonly notificationsOpen = signal(false);
  private readonly notifications = inject(Notifications);
  protected readonly unreadCount = toSignal(toObservable(inject(Auth).currentUser).pipe(
    switchMap(user => user ? merge(timer(0, 30_000), this.notifications.changed).pipe(
      switchMap(() => this.notifications.list({ unread: true }, 1, 1).pipe(
        map(page => page.unread_count),
        catchError(() => EMPTY),
      )),
      startWith(0),
    ) : of(0)),
  ), { initialValue: 0 });
  readonly avatarEditable = input(false);
  readonly avatarUrl = input<string>();
  readonly pageTitle = input('Impostazioni');
  readonly canAccessSettings = input(false);
  readonly openAvatar = output<void>();
  readonly openSettings = output<void>();

  protected closeOutside(event: MouseEvent): void {
    if (this.notificationsOpen() && !this.host.nativeElement.querySelector('.notification-bell')?.contains(event.target as Node)) {
      this.notificationsOpen.set(false);
    }
  }

  protected closeFromPreview(event: Event, bell: HTMLButtonElement): void {
    if ((event.target as HTMLElement).closest('dialog')) return;
    this.notificationsOpen.set(false);
    bell.focus();
  }
}
