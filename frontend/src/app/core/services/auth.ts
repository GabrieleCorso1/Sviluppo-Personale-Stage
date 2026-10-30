
import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable, switchMap, tap } from 'rxjs';

const API_URL = 'http://localhost:8000';

export interface User {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  roles: string[];
  permissions: string[];
}

interface LoginResponse {
  message: string;
  token_type: string;
  access_token: string;
  expires_at: string;
}

@Injectable({
  providedIn: 'root'
})
export class Auth {
  private http = inject(HttpClient);

  private readonly TOKEN_KEY = 'access_token';

  login(
    email: string,
    password: string,
    remember: boolean
  ): Observable<User> {
    return this.http.post<LoginResponse>(
      `${API_URL}/api/login`,
      { email, password, remember }
    ).pipe(
      tap(response => {
        localStorage.setItem(
          this.TOKEN_KEY,
          response.access_token
        );
      }),
      switchMap(() => this.getUser())
    );
  }

  getUser(): Observable<User> {
    const token = localStorage.getItem(this.TOKEN_KEY);

    const headers = new HttpHeaders({
      Authorization: `Bearer ${token}`
    });

    return this.http.get<User>(
      `${API_URL}/api/user`,
      { headers }
    );
  }

  logout(): Observable<void> {
    const token = localStorage.getItem(this.TOKEN_KEY);

    const headers = new HttpHeaders({
      Authorization: `Bearer ${token}`
    });

    return this.http.post<void>(
      `${API_URL}/api/logout`,
      {},
      { headers }
    ).pipe(
      tap(() => {
        localStorage.removeItem(this.TOKEN_KEY);
      })
    );
  }

  isAuthenticated(): boolean {
    return !!localStorage.getItem(this.TOKEN_KEY);
  }
}
