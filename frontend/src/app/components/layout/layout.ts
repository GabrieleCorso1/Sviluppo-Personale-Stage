import { DOCUMENT } from '@angular/common';
import { afterNextRender, Component, DestroyRef, ElementRef, inject, Injector, input, output, signal } from '@angular/core';
import { Header } from '../header/header';
import { Navbar } from '../navbar/navbar';

@Component({
  selector: 'app-layout',
  standalone: true,
  imports: [Header, Navbar],
  templateUrl: './layout.html',
  styleUrl: './layout.css',
  host: { '(document:keydown)': 'handleMenuKey($event)' },
})
export class Layout {
  private readonly document = inject(DOCUMENT);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly destroyRef = inject(DestroyRef);
  private readonly injector = inject(Injector);
  readonly isMobile = signal(false);
  readonly mobileMenuOpen = signal(false);
  private savedOverflow: string | null = null;

  constructor() {
    afterNextRender(() => {
      const media = this.document.defaultView?.matchMedia('(max-width: 680px)');
      if (!media) return;
      const update = () => {
        this.isMobile.set(media.matches);
        if (!media.matches) this.closeMobileMenu(false);
      };
      update();
      media.addEventListener('change', update);
      this.destroyRef.onDestroy(() => media.removeEventListener('change', update));
    });
    this.destroyRef.onDestroy(() => this.restoreScroll());
  }

  toggleMobileMenu(): void {
    if (this.mobileMenuOpen()) {
      this.closeMobileMenu();
      return;
    }
    this.mobileMenuOpen.set(true);
    this.savedOverflow = this.document.body.style.overflow;
    this.document.body.style.overflow = 'hidden';
    afterNextRender(() => {
      requestAnimationFrame(() => {
        if (this.mobileMenuOpen()) this.host.nativeElement.querySelector<HTMLElement>('.sidebar-close')?.focus({ preventScroll: true });
      });
    }, { injector: this.injector });
  }

  closeMobileMenu(restoreFocus = true): void {
    if (!this.mobileMenuOpen()) return;
    this.mobileMenuOpen.set(false);
    this.restoreScroll();
    if (restoreFocus) afterNextRender(() => {
      if (!this.mobileMenuOpen() && this.isMobile()) {
        this.host.nativeElement.querySelector<HTMLElement>('.mobile-menu-toggle')?.focus();
      }
    }, { injector: this.injector });
  }

  private restoreScroll(): void {
    if (this.savedOverflow === null) return;
    this.document.body.style.overflow = this.savedOverflow;
    this.savedOverflow = null;
  }

  handleMenuKey(event: KeyboardEvent): void {
    if (!this.isMobile() || !this.mobileMenuOpen()) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      this.closeMobileMenu();
    } else if (event.key === 'Tab') {
      const controls = Array.from(this.host.nativeElement.querySelectorAll<HTMLElement>('app-navbar button, app-navbar a[href]'));
      const first = controls[0];
      const last = controls.at(-1);
      if (event.shiftKey && this.document.activeElement === first) {
        event.preventDefault();
        last?.focus();
      } else if (!event.shiftKey && this.document.activeElement === last) {
        event.preventDefault();
        first?.focus();
      }
    }
  }
  readonly avatarEditable = input(false);
  readonly avatarUrl = input<string>();
  readonly pageTitle = input('Impostazioni');
  readonly canAccessSettings = input(false);
  readonly openAvatar = output<void>();
  readonly openSettings = output<void>();
}
