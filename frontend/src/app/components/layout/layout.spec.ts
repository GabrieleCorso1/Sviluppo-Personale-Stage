import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Layout } from './layout';

describe('Mobile navigation', () => {
  let fixture: ReturnType<typeof TestBed.createComponent<Layout>>;
  let media: MediaQueryList;
  let originalOverflow: string;

  beforeEach(async () => {
    originalOverflow = document.body.style.overflow;
    media = Object.assign(new EventTarget(), { matches: true, media: '(max-width: 680px)' }) as MediaQueryList;
    vi.stubGlobal('matchMedia', vi.fn(() => media));
    await TestBed.configureTestingModule({
      imports: [Layout],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    }).compileComponents();
    fixture = TestBed.createComponent(Layout);
    await fixture.whenStable();
  });

  afterEach(() => {
    fixture.destroy();
    document.body.style.overflow = originalOverflow;
    vi.unstubAllGlobals();
  });

  it('hides closed mobile navigation from keyboard access and releases page scroll on close', () => {
    const layout = fixture.componentInstance;
    expect(layout.isMobile()).toBe(true);
    expect(fixture.nativeElement.querySelector('app-navbar').hasAttribute('inert')).toBe(true);
    layout.toggleMobileMenu();
    fixture.detectChanges();
    expect(document.body.style.overflow).toBe('hidden');
    expect(fixture.nativeElement.querySelector('main').hasAttribute('inert')).toBe(true);
    layout.closeMobileMenu();
    fixture.detectChanges();
    expect(document.body.style.overflow).toBe(originalOverflow);
    expect(fixture.nativeElement.querySelector('main').hasAttribute('inert')).toBe(false);
  });

  it('closes with Escape and restores focus to the opener', async () => {
    fixture.componentInstance.toggleMobileMenu();
    fixture.detectChanges();
    const opener = fixture.nativeElement.querySelector('.mobile-menu-toggle') as HTMLButtonElement;
    const focus = vi.spyOn(opener, 'focus');
    fixture.componentInstance.handleMenuKey(new KeyboardEvent('keydown', { key: 'Escape', cancelable: true }));
    fixture.detectChanges();
    expect(fixture.componentInstance.mobileMenuOpen()).toBe(false);
    await new Promise<void>(resolve => requestAnimationFrame(() => resolve()));
    expect(focus).toHaveBeenCalled();
  });

  it('closes and restores scrolling when returning to desktop', () => {
    fixture.componentInstance.toggleMobileMenu();
    Object.defineProperty(media, 'matches', { value: false });
    media.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    expect(fixture.componentInstance.isMobile()).toBe(false);
    expect(fixture.componentInstance.mobileMenuOpen()).toBe(false);
    expect(document.body.style.overflow).toBe(originalOverflow);
  });

  it('restores scrolling when navigation destroys the layout', () => {
    fixture.componentInstance.toggleMobileMenu();
    fixture.destroy();
    expect(document.body.style.overflow).toBe(originalOverflow);
  });
});
