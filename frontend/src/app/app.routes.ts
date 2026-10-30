import { Routes } from '@angular/router';
import { Login } from './features/login/login';
import { Home } from './features/home/home';
import { authGuardGuard } from './core/guards/auth-guard-guard';

export const routes: Routes = [
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
