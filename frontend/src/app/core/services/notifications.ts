import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Subject } from 'rxjs';
import { API_URL } from '../auth-http';

export interface NotificationItem {
  id: string;
  category_label?: string;
  data: {
    category: string;
    message: string;
    project_id?: number;
    sbi_id?: number;
    sbi_reply?: boolean;
    calendar_request_id?: number;
    calendar_status?: 'pending' | 'approved' | 'rejected' | null;
  };
  read_at: string | null;
  created_at: string;
}

export interface NotificationFilters {
  categories?: string[];
  unread?: true;
}

export type NotificationSelection = { ids: string[] } | { all: true; filters: NotificationFilters };

export interface NotificationPage {
  data: NotificationItem[];
  categories: { key: string; label: string; unread_count: number }[];
  unread_count: number;
  current_page: number;
  last_page: number;
  total: number;
}

export interface NotificationProject {
  alias: string;
  description?: string | null;
}

@Injectable({ providedIn: 'root' })
export class Notifications {
  private readonly http = inject(HttpClient);
  private readonly endpoint = API_URL + '/api/notifications';
  private readonly changes = new Subject<void>();
  readonly changed = this.changes.asObservable();

  refreshLists(): void {
    this.changes.next();
  }

  list(filters: NotificationFilters, page: number, perPage: number) {
    let params = new HttpParams().set('page', page).set('per_page', perPage);
    if (filters.unread) params = params.set('unread', '1');
    for (const category of filters.categories ?? []) params = params.append('categories[]', category);
    return this.http.get<NotificationPage>(this.endpoint, { params });
  }

  show(id: string) {
    return this.http.get<NotificationItem>(this.endpoint + '/' + encodeURIComponent(id));
  }

  mark(selection: NotificationSelection, read: boolean) {
    return this.http.patch(this.endpoint, { ...selection, read });
  }

  remove(selection: NotificationSelection) {
    return this.http.delete(this.endpoint, { body: selection });
  }

  decide(id: string, status: 'approved' | 'rejected') {
    return this.http.patch(API_URL + '/api/admin/notifications/' + id + '/decision', { status });
  }

  projectDetails(id: number, timesheet: boolean) {
    const path = timesheet ? `allocations/projects/${id}?type=timesheet` : `projects/${id}`;
    return this.http.get<{ data: NotificationProject }>(API_URL + '/api/' + path);
  }
}
