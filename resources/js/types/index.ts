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
    onboarding_completed_at?: string | null;
}

export type OnboardingStep = 'restaurant' | 'hours' | 'tables' | 'tonality' | 'team';

export interface OnboardingProgress {
    coreComplete: boolean;
    nextCoreStep: 'restaurant' | 'hours' | 'tables' | null;
    steps: Record<OnboardingStep, boolean>;
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
    notification_settings: NotificationSettings;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export type ReservationStatus = 'new' | 'in_review' | 'replied' | 'confirmed' | 'declined' | 'cancelled' | 'waitlisted';
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

export interface TableModel {
    id: number;
    label: string;
    seats: number;
    room_tag: string | null;
    sort_order: number;
    active: boolean;
    combinable_with: number[];
    created_at: string | null;
}

export interface TableFormPayload {
    label: string;
    seats: number;
    room_tag: string | null;
    sort_order: number;
    active: boolean;
    combinable_with: number[];
}

export type SlotState = 'free' | 'tight' | 'full';

export interface AvailabilitySlot {
    time: string;
    state: SlotState;
    suggested_table_id: number | null;
}

export interface DayAvailability {
    date: string;
    slots: AvailabilitySlot[];
    total_capacity: number;
    reserved_seats: number;
}

export interface TableCombination {
    primary_table_id: number;
    table_ids: number[];
    total_seats: number;
}

export interface QuickAvailability {
    state: SlotState;
    suggested_table_id: number | null;
    combination: TableCombination | null;
    alternative_slots: Array<{ date: string; time: string }>;
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

export interface NotificationSettings {
    browser_notifications: boolean;
    sound_alerts: boolean;
    sound: string;
    volume: number;
    daily_digest: boolean;
    daily_digest_at: string;
}

export type ReservationReplyStatus = 'draft' | 'approved' | 'sent' | 'failed' | 'shadow' | 'scheduled_auto_send' | 'cancelled_auto';

export type SendMode = 'manual' | 'shadow' | 'auto';

export interface ReservationReplySummary {
    id: number;
    status: ReservationReplyStatus;
    body: string;
    approved_at: string | null;
    sent_at: string | null;
    error_message: string | null;
    /** PRD-007 detail-drawer fields (issue #221). */
    auto_send_scheduled_for: string | null;
    shadow_compared_at: string | null;
    send_mode_at_creation: SendMode | null;
}

export interface ReservationRequestDetail extends ReservationRequestRow {
    message: string | null;
    raw_payload: Record<string, unknown> | null;
    raw_email_body: string | null;
    latest_reply: ReservationReplySummary | null;
}

export type MessageDirection = 'in' | 'out';

export interface ThreadMessage {
    id: number;
    direction: MessageDirection;
    subject: string;
    from_address: string;
    to_address: string;
    body_plain: string;
    sent_at: string | null;
    received_at: string | null;
    approved_by: string | null;
}
