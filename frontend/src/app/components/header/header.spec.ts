import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { signal } from '@angular/core';
import { EMPTY, of, Subject } from 'rxjs';
import { Auth } from '../../core/services/auth';
import { Notifications } from '../../core/services/notifications';
import { Header } from './header';

describe('Account dropdown', () => {
  let fixture: ReturnType<typeof TestBed.createComponent<Header>>;
  let auth: { currentUser: ReturnType<typeof signal>; logout: ReturnType<typeof vi.fn> };
  let navigate: ReturnType<typeof vi.spyOn>;
  const button = (selector: string) => fixture.nativeElement.querySelector(selector) as HTMLButtonElement;

  beforeEach(async () => {
    auth = { currentUser: signal({ id: 1, first_name: 'Mario', last_name: 'Rossi' }), logout: vi.fn(() => of(undefined)) };
    await TestBed.configureTestingModule({
      imports: [Header],
      providers: [provideRouter([]), { provide: Auth, useValue: auth }, {
        provide: Notifications, useValue: { list: () => of({ unread_count: 0 }), changed: EMPTY },
      }],
    }).compileComponents();
    navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    fixture = TestBed.createComponent(Header);
    fixture.detectChanges();
    button('.avatar-button').click();
    fixture.detectChanges();
  });

  afterEach(() => { fixture?.destroy(); vi.restoreAllMocks(); });

  it('opens the account dropdown and navigates to the profile without logging out', () => {
    expect(fixture.nativeElement.querySelector('.account-popover')).not.toBeNull();
    button('.account-profile').click();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.account-popover')).toBeNull();
    expect(navigate).toHaveBeenCalledWith(['/home']);
    expect(auth.logout).not.toHaveBeenCalled();
  });

  it('uses the existing logout service and redirects to login on success', () => {
    button('.account-logout').click();
    fixture.detectChanges();
    expect(auth.logout).toHaveBeenCalledTimes(1);
    expect(fixture.nativeElement.querySelector('.account-popover')).toBeNull();
    expect(navigate).toHaveBeenCalledWith(['/login']);
  });

  it('blocks duplicate submissions and keeps the dialog available after a server error', () => {
    const pending = new Subject<void>();
    auth.logout.mockReturnValue(pending);
    button('.account-logout').click();
    fixture.detectChanges();
    expect(button('.account-logout').disabled).toBe(true);
    expect(button('.account-profile').disabled).toBe(true);
    button('.account-logout').click();
    expect(auth.logout).toHaveBeenCalledTimes(1);
    pending.error(new Error('Offline'));
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.account-popover')).not.toBeNull();
    expect(navigate).not.toHaveBeenCalled();
    expect(fixture.nativeElement.querySelector('[role="alert"]').textContent).toContain('Impossibile uscire');
    expect(button('.account-logout').disabled).toBe(false);
  });

  it('closes with Escape without logging out', () => {
    button('.avatar-button').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.account-popover')).toBeNull();
    expect(auth.logout).not.toHaveBeenCalled();
  });

  it('closes when clicking outside or leaving with the keyboard', () => {
    document.body.click();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.account-popover')).toBeNull();
    button('.avatar-button').click();
    fixture.detectChanges();
    button('.account-logout').dispatchEvent(new FocusEvent('focusout', { relatedTarget: document.body, bubbles: true }));
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.account-popover')).toBeNull();
    expect(auth.logout).not.toHaveBeenCalled();
  });
});
