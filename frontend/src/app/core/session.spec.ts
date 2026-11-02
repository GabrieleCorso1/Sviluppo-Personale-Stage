import { TestBed } from '@angular/core/testing';
import { HttpClient, HttpContext, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRouteSnapshot, Router, RouterStateSnapshot, provideRouter } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { Auth } from './services/auth';
import { authInterceptor } from './auth-interceptor';
import { authGuardGuard } from './guards/auth-guard-guard';
import { API_URL, SKIP_AUTH_REDIRECT } from './auth-http';

describe('Session lifecycle', () => {
  let auth: Auth;
  let http: HttpTestingController;
  let client: HttpClient;
  let router: Router;
  const user = { id: 1, first_name: 'Mario', last_name: 'Rossi', email: 'a@b.it', roles: [], permissions: [] };
  const future = () => new Date(Date.now() + 60000).toISOString();
  const seed = () => {
    localStorage.setItem('access_token', 'test-token');
    localStorage.setItem('expires_at', future());
  };
  const guard = () => TestBed.runInInjectionContext(() =>
    authGuardGuard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot));

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({ providers: [
      provideRouter([]), provideHttpClient(withInterceptors([authInterceptor])), provideHttpClientTesting()
    ] });
    auth = TestBed.inject(Auth);
    http = TestBed.inject(HttpTestingController);
    client = TestBed.inject(HttpClient);
    router = TestBed.inject(Router);
    vi.spyOn(router, 'navigate').mockResolvedValue(true);
  });
  afterEach(() => { http.verify(); localStorage.clear(); });

  it('stores expiry and attaches the token when loading the user', async () => {
    const expiry = future();
    const result = firstValueFrom(auth.login('a@b.it', 'password', false));
    const login = http.expectOne(`${API_URL}/api/login`);
    expect(login.request.headers.has('Authorization')).toBe(false);
    login.flush({ access_token: 'test-token', expires_at: expiry });
    const request = http.expectOne(`${API_URL}/api/user`);
    expect(request.request.headers.get('Authorization')).toBe('Bearer test-token');
    request.flush(user);
    expect(await result).toEqual(user);
    expect(localStorage.getItem('expires_at')).toBe(expiry);
  });

  it.each([null, 'invalid', '2000-01-01T00:00:00Z'])('rejects missing, invalid or expired expiry: %s', expiry => {
    seed();
    if (expiry === null) localStorage.removeItem('expires_at');
    else localStorage.setItem('expires_at', expiry);
    expect(auth.isAuthenticated()).toBe(false);
    expect(localStorage.getItem('access_token')).toBeNull();
  });

  it('clears a revoked session and redirects on protected 401', async () => {
    seed();
    const result = firstValueFrom(auth.getUser());
    const rejected = expect(result).rejects.toMatchObject({ status: 401 });
    http.expectOne(`${API_URL}/api/user`).flush({}, { status: 401, statusText: 'Unauthorized' });
    await rejected;
    expect(auth.isAuthenticated()).toBe(false);
    expect(router.navigate).toHaveBeenCalled();
  });

  it('does not attach credentials to another origin', async () => {
    seed();
    const result = firstValueFrom(client.get('https://example.com/api/users'));
    const request = http.expectOne('https://example.com/api/users');
    expect(request.request.headers.has('Authorization')).toBe(false);
    request.flush({});
    await result;
  });

  it('preserves the session on server errors', async () => {
    seed();
    const result = firstValueFrom(auth.getUser());
    const rejected = expect(result).rejects.toMatchObject({ status: 503 });
    http.expectOne(`${API_URL}/api/user`).flush({}, { status: 503, statusText: 'Unavailable' });
    await rejected;
    expect(auth.isAuthenticated()).toBe(true);
    expect(router.navigate).not.toHaveBeenCalled();
  });

  it('allows a protected route only after backend verification', async () => {
    seed();
    const result = firstValueFrom(guard() as import('rxjs').Observable<unknown>);
    http.expectOne(`${API_URL}/api/user`).flush(user);
    expect(await result).toBe(true);
  });

  it('denies entry on network failure without deleting the session', async () => {
    seed();
    const result = firstValueFrom(guard() as import('rxjs').Observable<unknown>);
    http.expectOne(`${API_URL}/api/user`).error(new ProgressEvent('error'));
    expect(router.serializeUrl(await result as import('@angular/router').UrlTree)).toContain('verification-unavailable');
    expect(auth.isAuthenticated()).toBe(true);
  });

  it('redirects a revoked session from the guard without duplicate navigation', async () => {
    seed();
    const result = firstValueFrom(guard() as import('rxjs').Observable<unknown>);
    http.expectOne(`${API_URL}/api/user`).flush({}, { status: 401, statusText: 'Unauthorized' });
    expect(router.serializeUrl(await result as import('@angular/router').UrlTree)).toContain('session-expired');
    expect(auth.isAuthenticated()).toBe(false);
    expect(router.navigate).not.toHaveBeenCalled();
  });

  it.each([200, 401])('clears the session on logout response %s', async status => {
    seed();
    const result = firstValueFrom(auth.logout());
    http.expectOne(`${API_URL}/api/logout`).flush({}, { status, statusText: status === 200 ? 'OK' : 'Unauthorized' });
    await result;
    expect(localStorage.getItem('access_token')).toBeNull();
    expect(localStorage.getItem('expires_at')).toBeNull();
  });

  it('preserves the session after a failed logout so it can be retried', async () => {
    seed();
    const result = firstValueFrom(auth.logout());
    const rejected = expect(result).rejects.toMatchObject({ status: 0 });
    http.expectOne(`${API_URL}/api/logout`).error(new ProgressEvent('error'));
    await rejected;
    expect(auth.isAuthenticated()).toBe(true);
  });
});
