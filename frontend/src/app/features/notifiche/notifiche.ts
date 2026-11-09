import { Component, DestroyRef, inject, OnInit } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { Layout } from '../../components/layout/layout';
import { Auth, hasSettingsRole } from '../../core/services/auth';
import { ProfileAvatar } from '../../core/services/profile-avatar';
import { NotificationCase } from './notification-case';
import { NotificationList } from './notification-list';

@Component({
  selector: 'app-notifiche',
  imports: [Layout, NotificationList, NotificationCase],
  template: `
    <app-layout pageTitle="Notifiche" [avatarUrl]="avatar.url()"
      [canAccessSettings]="canAccessSettings" (openSettings)="router.navigate(['/impostazioni'])">
      @if (casePage) {
        <app-notification-case />
      } @else {
        <app-notification-list />
      }
    </app-layout>
  `,
})
export class Notifiche implements OnInit {
  protected readonly casePage = inject(ActivatedRoute).snapshot.paramMap.has('id');
  protected readonly avatar = inject(ProfileAvatar);
  protected readonly router = inject(Router);
  private readonly auth = inject(Auth);
  private readonly destroyRef = inject(DestroyRef);

  protected get canAccessSettings(): boolean {
    const user = this.auth.currentUser();
    return user !== null && hasSettingsRole(user);
  }

  ngOnInit(): void {
    this.avatar.load().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({ error: () => {} });
  }
}
