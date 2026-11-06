import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { API_URL } from '../auth-http';

export const RECOVERY_MESSAGE = 'Se l’indirizzo è associato a un account, riceverai le istruzioni per reimpostare la password.';

@Injectable({ providedIn: 'root' })
export class PasswordRecovery {
  private http = inject(HttpClient);

  requestLink(email: string) {
    return this.http.post<{ message: string }>(`${API_URL}/api/forgot-password`, { email });
  }

  resetPassword(token: string, email: string, password: string, confirmation: string) {
    return this.http.post<{ message: string }>(`${API_URL}/api/reset-password`, {
      token, email, password, password_confirmation: confirmation
    });
  }
}
