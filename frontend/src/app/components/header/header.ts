import { Component, ElementRef, inject, input, output, signal } from '@angular/core';

import { RouterLink } from '@angular/router';
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
