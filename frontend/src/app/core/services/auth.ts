import { Service } from '@angular/core';
import { Injectable } from '@angular/core';

@Injectable({
  providedIn: 'root'
})
export class Auth {
  private authenticated = false;

  isAuthenticated(): boolean {
    return this.authenticated;
  }

  login(email: string, password: string): boolean {
    // Simulazione temporanea lato frontend.
    if (!email.trim() || !password) {
      return false;
    }

    this.authenticated = true;
    return true;
  }

  logout(): void {
    this.authenticated = false;
  }
}
