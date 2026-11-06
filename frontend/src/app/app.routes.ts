import { Routes } from '@angular/router';
import { Login } from './features/login/login';
import { Home } from './features/home/home';
import { authGuardGuard } from './core/guards/auth-guard-guard';

export const routes: Routes = [
  { path: 'forgot-password', loadComponent: () => import('./features/password-recovery/forgot-password').then(m => m.ForgotPassword) },
  { path: 'reset-password', loadComponent: () => import('./features/password-recovery/reset-password').then(m => m.ResetPassword) },
    {
    path: 'login',
    component: Login
  },
  {
    path: 'home',
    component: Home,
    canActivate: [authGuardGuard]
  },

  {
    path: '',
    redirectTo: 'login',
    pathMatch: 'full'
  },
  
  {
    path: '**',
    redirectTo: 'login'
  }
];
