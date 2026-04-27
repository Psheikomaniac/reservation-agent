import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    restaurant: Restaurant | null;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface Restaurant {
    id: number;
    name: string;
    timezone: string;
    tonality: 'formal' | 'casual' | 'family';
}

export type UserRole = 'owner' | 'staff';

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    restaurant_id: number | null;
    role: UserRole;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export type ReservationStatus = 'new' | 'in_review' | 'replied' | 'confirmed' | 'declined' | 'cancelled';
export type ReservationSource = 'web_form' | 'email';

export interface ReservationRequestRow {
    id: number;
    status: ReservationStatus;
    source: ReservationSource;
    guest_name: string;
    guest_email: string | null;
    guest_phone: string | null;
    party_size: number;
    desired_at: string | null;
    needs_manual_review: boolean;
    created_at: string | null;
    has_raw_email: boolean;
}

export interface PaginatorMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface PaginatedReservationRequests {
    data: ReservationRequestRow[];
    meta: PaginatorMeta & { links: PaginatorLink[] };
    links: { first: string | null; last: string | null; prev: string | null; next: string | null };
}

export interface DashboardFilters {
    status?: ReservationStatus[];
    source?: ReservationSource[];
    from?: string;
    to?: string;
    q?: string;
}

export interface DashboardStats {
    new: number;
    in_review: number;
}

export type ReservationReplyStatus = 'draft' | 'approved' | 'sent' | 'failed';

export interface ReservationReplySummary {
    id: number;
    status: ReservationReplyStatus;
    body: string;
    approved_at: string | null;
    sent_at: string | null;
    error_message: string | null;
}

export interface ReservationRequestDetail extends ReservationRequestRow {
    message: string | null;
    raw_payload: Record<string, unknown> | null;
    raw_email_body: string | null;
    latest_reply: ReservationReplySummary | null;
}
