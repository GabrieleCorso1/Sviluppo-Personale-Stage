import { DOCUMENT } from '@angular/common';
import { afterNextRender, DestroyRef, inject, Injectable, signal } from '@angular/core';

export type ThemePreference = 'system' | 'light' | 'dark';
export const THEME_STORAGE_KEY = 'stage.theme';

@Injectable({ providedIn: 'root' })
export class Theme {
  private readonly document = inject(DOCUMENT);
  private readonly destroyRef = inject(DestroyRef);
  private readonly selected = signal<ThemePreference>('system');
  private readonly active = signal<'light' | 'dark'>('light');
  private media?: MediaQueryList;
  readonly preference = this.selected.asReadonly();
  readonly effective = this.active.asReadonly();

  constructor() {
    afterNextRender(() => {
      const view = this.document.defaultView;
      if (!view) return;
      this.media = view.matchMedia?.('(prefers-color-scheme: dark)');
      this.selected.set(this.readPreference());
      this.apply();
      const systemChanged = () => this.apply();
      const storageChanged = (event: StorageEvent) => {
        if (event.key !== null && event.key !== THEME_STORAGE_KEY) return;
        this.selected.set(this.readPreference());
        this.apply();
      };
      this.media?.addEventListener('change', systemChanged);
      view.addEventListener('storage', storageChanged);
      this.destroyRef.onDestroy(() => {
        this.media?.removeEventListener('change', systemChanged);
        view.removeEventListener('storage', storageChanged);
      });
    });
  }

  setPreference(preference: ThemePreference): void {
    if (!['system', 'light', 'dark'].includes(preference)) return;
    this.selected.set(preference);
    try {
      this.document.defaultView?.localStorage.setItem(THEME_STORAGE_KEY, preference);
    } catch {
      // The theme remains usable when browser storage is unavailable.
    }
    this.apply();
  }

  private readPreference(): ThemePreference {
    try {
      const value = this.document.defaultView?.localStorage.getItem(THEME_STORAGE_KEY);
      if (value === 'light' || value === 'dark') return value;
    } catch {
      // Fall back to the system preference.
    }
    return 'system';
  }

  private apply(): void {
    const preference = this.selected();
    const effective = preference === 'system' ? (this.media?.matches ? 'dark' : 'light') : preference;
    this.active.set(effective);
    this.document.documentElement.dataset['theme'] = effective;
  }
}
