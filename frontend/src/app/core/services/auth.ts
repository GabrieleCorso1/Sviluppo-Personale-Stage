import { Injectable, inject, PLATFORM_ID, signal } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { HttpClient, HttpContext, HttpErrorResponse } from '@angular/common/http';
import { Observable, catchError, of, switchMap, tap, throwError } from 'rxjs';
import { API_URL } from '../auth-http';

export interface User {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  hiring_date?: string | null;
  roles: string[];
  permissions: string[];
}

export function hasSettingsRole(user: User): boolean {
  return user.roles.some(role => role === 'Admin' || role === 'Project Manager');
}

interface LoginResponse {
  message: string;
  token_type: string;
  access_token: string;
  expires_at: string;
}

@Injectable({ providedIn: 'root' })
export class Auth {
  private readonly sessionUser = signal<User | null>(null);
  readonly currentUser = this.sessionUser.asReadonly();

  private http = inject(HttpClient);
  private readonly platformId = inject(PLATFORM_ID);
  private readonly TOKEN_KEY = 'access_token';
  private readonly EXPIRY_KEY = 'expires_at';

  login(email: string, password: string, remember: boolean): Observable<User> {
    return this.http.post<LoginResponse>(`${API_URL}/api/login`, {
      email, password, remember
    }).pipe(
      tap(response => {
        if (isPlatformBrowser(this.platformId)) {
          this.sessionUser.set(null);
          localStorage.setItem(this.TOKEN_KEY, response.access_token);
          localStorage.setItem(this.EXPIRY_KEY, response.expires_at);
        }
      }),
      switchMap(() => this.getUser())
    );
  }

  getUser(context = new HttpContext()): Observable<User> {
    const token = this.getToken();
    return this.http.get<User>(`${API_URL}/api/user`, { context }).pipe(
      tap(user => {
        if (token && this.getToken() === token) this.sessionUser.set(user);
      }),
    );
  }

  logout(): Observable<void> {
    if (!this.isAuthenticated()) {
      this.clearSession();
      return of(undefined);
    }

    return this.http.post<void>(`${API_URL}/api/logout`, {}).pipe(
      tap(() => this.clearSession()),
      catchError((error: HttpErrorResponse) => {
        if (error.status === 401) {
          this.clearSession();
          return of(undefined);
        }
        return throwError(() => error);
      })
    );
  }

  clearSession(): void {
    this.sessionUser.set(null);
    if (isPlatformBrowser(this.platformId)) {
      localStorage.removeItem(this.TOKEN_KEY);
      localStorage.removeItem(this.EXPIRY_KEY);
    }
  }

  getToken(): string | null {
    if (!isPlatformBrowser(this.platformId)) {
      return null;
    }

    const token = localStorage.getItem(this.TOKEN_KEY);
    const expiresAt = localStorage.getItem(this.EXPIRY_KEY);
    const expiry = expiresAt ? Date.parse(expiresAt) : NaN;
    if (!token || !Number.isFinite(expiry) || expiry <= Date.now()) {
      this.clearSession();
      return null;
    }
    return token;
  }

  isAuthenticated(): boolean {
    return this.getToken() !== null;
  }
}
