import { HttpContextToken } from '@angular/common/http';

// The browser uses the same origin on both desktop and phone.
// During development, proxy.conf.cjs forwards backend requests locally.
export const API_URL = typeof window === 'undefined'
  ? 'http://localhost:4200'
  : window.location.origin;
export const SKIP_AUTH_REDIRECT = new HttpContextToken<boolean>(() => false);
