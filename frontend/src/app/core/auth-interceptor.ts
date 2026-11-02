import { inject } from '@angular/core';
import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { Auth } from './services/auth';
import { API_URL, SKIP_AUTH_REDIRECT } from './auth-http';

export const authInterceptor: HttpInterceptorFn = (request, next) => {
  // Never send the token to third-party services or public authentication endpoints.
  const url = new URL(request.url, API_URL);
  const api = new URL(API_URL);
  const publicPaths = ['/api/login', '/api/forgot-password', '/api/reset-password'];
  if (url.origin !== api.origin || !url.pathname.startsWith('/api/') ||
      publicPaths.includes(url.pathname)) {
    return next(request);
  }

  const auth = inject(Auth);
  const router = inject(Router);
  const token = auth.getToken();
  const authenticatedRequest = token
    ? request.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
    : request;

  return next(authenticatedRequest).pipe(
    catchError((error: HttpErrorResponse) => {
      if (error.status === 401) {
        const currentToken = auth.getToken();
        if (currentToken === null || currentToken === token) {
          auth.clearSession();
          if (!request.context.get(SKIP_AUTH_REDIRECT)) {
            void router.navigate(['/login'], {
              queryParams: { reason: 'session-expired' }
            });
          }
        }
      }
      return throwError(() => error);
    })
  );
};
