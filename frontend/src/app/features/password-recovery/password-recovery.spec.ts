import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router, convertToParamMap, provideRouter } from '@angular/router';
import { ForgotPassword } from './forgot-password';
import { ResetPassword } from './reset-password';
import { API_URL } from '../../core/auth-http';
import { RECOVERY_MESSAGE } from '../../core/services/password-recovery';

describe('Password recovery', () => {
  let http: HttpTestingController;
  let router: Router;
  let params: Record<string, string>;

  beforeEach(() => {
    params = { token: 'reset-token', email: 'mario@example.com' };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]), provideHttpClient(), provideHttpClientTesting(),
        { provide: ActivatedRoute, useValue: {
          snapshot: { get queryParamMap() { return convertToParamMap(params); } }
        } }
      ]
    });
    http = TestBed.inject(HttpTestingController);
    router = TestBed.inject(Router);
    vi.spyOn(router, 'navigate').mockResolvedValue(true);
    localStorage.clear();
  });
  afterEach(() => { http.verify(); localStorage.clear(); });

  it('requests a link once and renders only the neutral confirmation', async () => {
    const fixture = TestBed.createComponent(ForgotPassword);
    fixture.detectChanges();
    fixture.componentInstance.email = ' mario@example.com ';
    fixture.componentInstance.invia();
    fixture.componentInstance.invia();
    const request = http.expectOne(API_URL + '/api/forgot-password');
    expect(request.request.body).toEqual({ email: 'mario@example.com' });
    request.flush({ message: 'ignored', reset_url: 'sensitive-debug-url' });
    await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('[role="status"]').textContent).toContain(RECOVERY_MESSAGE);
    expect(fixture.nativeElement.textContent).not.toContain('sensitive-debug-url');
    expect(fixture.componentInstance.caricamento()).toBe(false);
  });

  it('rejects invalid email without a request', () => {
    const fixture = TestBed.createComponent(ForgotPassword);
    fixture.componentInstance.email = 'invalid';
    fixture.componentInstance.invia();
    expect(fixture.componentInstance.errore()).toContain('email valido');
    http.expectNone(API_URL + '/api/forgot-password');
  });

  it.each([422, 429, 503])('displays request-link error %s and enables retry', async status => {
    const fixture = TestBed.createComponent(ForgotPassword);
    fixture.detectChanges();
    fixture.componentInstance.email = 'mario@example.com';
    fixture.componentInstance.invia();
    http.expectOne(API_URL + '/api/forgot-password').flush(
      { errors: { email: ['Email non valida.'] } }, { status, statusText: 'Error' });
    await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('[role="alert"]')).not.toBeNull();
    expect(fixture.componentInstance.caricamento()).toBe(false);
    expect(fixture.componentInstance.messaggio()).toBe('');
  });

  it('blocks an incomplete reset link', () => {
    params = {};
    const fixture = TestBed.createComponent(ResetPassword);
    fixture.detectChanges();
    fixture.componentInstance.aggiorna();
    expect(fixture.nativeElement.querySelector('form')).toBeNull();
    expect(fixture.componentInstance.errore()).toContain('incompleto');
    http.expectNone(API_URL + '/api/reset-password');
  });

  it('checks minimum length and password confirmation', () => {
    const component = TestBed.createComponent(ResetPassword).componentInstance;
    component.password = 'short';
    component.aggiorna();
    expect(component.errore()).toContain('8 caratteri');
    component.password = 'password123';
    component.confirmation = 'different';
    component.aggiorna();
    expect(component.errore()).toContain('non coincidono');
    http.expectNone(API_URL + '/api/reset-password');
  });

  it('posts the reset payload, clears the session and returns to login', async () => {
    const fixture = TestBed.createComponent(ResetPassword);
    const component = fixture.componentInstance;
    component.password = component.confirmation = 'password123';
    localStorage.setItem('access_token', 'old-token');
    localStorage.setItem('expires_at', new Date(Date.now() + 60000).toISOString());
    component.aggiorna();
    component.aggiorna();
    const request = http.expectOne(API_URL + '/api/reset-password');
    expect(request.request.body).toEqual({
      token: 'reset-token', email: 'mario@example.com',
      password: 'password123', password_confirmation: 'password123'
    });
    request.flush({ message: 'Password aggiornata.' });
    await fixture.whenStable();
    expect(localStorage.getItem('access_token')).toBeNull();
    expect(localStorage.getItem('expires_at')).toBeNull();
    expect(router.navigate).toHaveBeenCalledWith(['/login'], {
      queryParams: { passwordReset: 'success' }, replaceUrl: true
    });
  });

  it('handles an expired link and hides the form', async () => {
    const fixture = TestBed.createComponent(ResetPassword);
    fixture.detectChanges();
    const component = fixture.componentInstance;
    component.password = component.confirmation = 'password123';
    component.aggiorna();
    http.expectOne(API_URL + '/api/reset-password').flush(
      { errors: { email: ['Invalid token'] } }, { status: 422, statusText: 'Invalid' });
    await fixture.whenStable();
    expect(component.errore()).toContain('scaduto');
    expect(fixture.nativeElement.querySelector('form')).toBeNull();
    expect(fixture.nativeElement.querySelector('a').getAttribute('href')).toBe('/forgot-password');
  });

  it('allows retry after a reset network error', () => {
    const component = TestBed.createComponent(ResetPassword).componentInstance;
    component.password = component.confirmation = 'password123';
    component.aggiorna();
    http.expectOne(API_URL + '/api/reset-password').error(new ProgressEvent('error'));
    expect(component.caricamento()).toBe(false);
    expect(component.linkNonValido()).toBe(false);
    expect(component.errore()).toContain('connessione');
    component.aggiorna();
    http.expectOne(API_URL + '/api/reset-password').flush({ message: 'OK' });
  });
});
