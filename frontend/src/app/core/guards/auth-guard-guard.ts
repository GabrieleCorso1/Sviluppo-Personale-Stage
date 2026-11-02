import { CanActivateFn, Router } from '@angular/router';
import { inject } from '@angular/core';
import { HttpContext, HttpErrorResponse } from '@angular/common/http';
import { catchError, map, of } from 'rxjs';
import { Auth } from '../services/auth';
import { SKIP_AUTH_REDIRECT } from '../auth-http';

export const authGuardGuard: CanActivateFn = () => {
  const auth = inject(Auth);
  const router = inject(Router);
  if (!auth.isAuthenticated()) {
    return router.createUrlTree(['/login']);
  }

  return auth.getUser(new HttpContext().set(SKIP_AUTH_REDIRECT, true)).pipe(
    map(() => true),
    catchError((error: HttpErrorResponse) => {
      if (error.status === 401) {
        auth.clearSession();
      }
      return of(router.createUrlTree(['/login'], {
        queryParams: {
          reason: error.status === 401 ? 'session-expired' : 'verification-unavailable'
        }
      }));
    })
  );
};
