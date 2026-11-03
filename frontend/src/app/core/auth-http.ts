import { HttpContextToken } from '@angular/common/http';

export const API_URL = 'http://localhost:8000';
export const SKIP_AUTH_REDIRECT = new HttpContextToken<boolean>(() => false);
