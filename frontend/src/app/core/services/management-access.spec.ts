import { canAccessManagement, hasSettingsRole, User } from './auth';

describe('Management access', () => {
  const user = (roles: string[], permissions: string[] = []): User => ({
    id: 1, first_name: 'Test', last_name: 'User', email: 'test@example.com', roles, permissions,
  });

  it('allows HR to enter settings and user management only', () => {
    const hr = user(['HR Manager'], ['users.view']);
    expect(hasSettingsRole(hr)).toBe(true);
    expect(canAccessManagement(hr, 'users')).toBe(true);
    expect(canAccessManagement(hr, 'projects')).toBe(false);
    expect(canAccessManagement(hr, 'allocations')).toBe(false);
  });

  it('allows project managers to enter project management only', () => {
    const pm = user(['Project Manager']);
    expect(hasSettingsRole(pm)).toBe(true);
    expect(canAccessManagement(pm, 'projects')).toBe(true);
    expect(canAccessManagement(pm, 'users')).toBe(false);
    expect(canAccessManagement(pm, 'allocations')).toBe(false);
  });

  it('denies settings to standard users and HR without viewing permission', () => {
    expect(hasSettingsRole(user(['User']))).toBe(false);
    expect(hasSettingsRole(user(['HR Manager']))).toBe(false);
    expect(canAccessManagement(null, 'projects')).toBe(false);
  });

  it('retains all admin management areas', () => {
    const admin = user(['Admin'], ['users.view']);
    for (const area of ['projects', 'users', 'allocations'] as const) {
      expect(canAccessManagement(admin, area)).toBe(true);
    }
  });
});
