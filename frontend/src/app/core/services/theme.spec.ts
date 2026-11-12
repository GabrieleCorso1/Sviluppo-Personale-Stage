import { Component, inject } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Theme, THEME_STORAGE_KEY } from './theme';

@Component({ template: '' })
class ThemeHost { readonly theme = inject(Theme); }

describe('Theme preference', () => {
  let media: EventTarget & { matches: boolean; media: string };
  let originalMatchMedia: PropertyDescriptor | undefined;

  beforeEach(() => {
    localStorage.removeItem(THEME_STORAGE_KEY);
    originalMatchMedia = Object.getOwnPropertyDescriptor(window, 'matchMedia');
    media = Object.assign(new EventTarget(), { matches: true, media: '(prefers-color-scheme: dark)' });
    Object.defineProperty(window, 'matchMedia', { configurable: true, value: () => media });
    TestBed.configureTestingModule({ imports: [ThemeHost] });
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    localStorage.removeItem(THEME_STORAGE_KEY);
    delete document.documentElement.dataset['theme'];
    if (originalMatchMedia) Object.defineProperty(window, 'matchMedia', originalMatchMedia);
    else Reflect.deleteProperty(window, 'matchMedia');
    vi.restoreAllMocks();
  });

  async function start(): Promise<Theme> {
    const fixture = TestBed.createComponent(ThemeHost);
    await fixture.whenStable();
    return fixture.componentInstance.theme;
  }

  it('follows system changes until an explicit preference is selected', async () => {
    const theme = await start();
    expect(document.documentElement.dataset['theme']).toBe('dark');
    media.matches = false;
    media.dispatchEvent(new Event('change'));
    expect(theme.effective()).toBe('light');
    theme.setPreference('dark');
    media.dispatchEvent(new Event('change'));
    expect(theme.effective()).toBe('dark');
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark');
    theme.setPreference('system');
    expect(theme.effective()).toBe('light');
  });

  it('restores a saved override instead of the device preference', async () => {
    localStorage.setItem(THEME_STORAGE_KEY, 'light');
    const theme = await start();
    expect(theme.preference()).toBe('light');
    expect(document.documentElement.dataset['theme']).toBe('light');
  });

  it('synchronizes changes and resets from another tab', async () => {
    const theme = await start();
    localStorage.setItem(THEME_STORAGE_KEY, 'light');
    window.dispatchEvent(new StorageEvent('storage', { key: THEME_STORAGE_KEY }));
    expect(theme.effective()).toBe('light');
    localStorage.clear();
    window.dispatchEvent(new StorageEvent('storage', { key: null }));
    expect(theme.preference()).toBe('system');
    expect(theme.effective()).toBe('dark');
  });

  it('falls back safely for invalid or unavailable storage', async () => {
    localStorage.setItem(THEME_STORAGE_KEY, 'unexpected');
    const theme = await start();
    expect(theme.preference()).toBe('system');
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked'); });
    expect(() => theme.setPreference('light')).not.toThrow();
    expect(document.documentElement.dataset['theme']).toBe('light');
  });
});
