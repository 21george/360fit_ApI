# Senior Audit — 360Fit Backend

> This document captures high-level architectural decisions, security considerations, and recently implemented features from a senior engineering perspective. It complements the PRD and README by focusing on *why* things were built a certain way and what to watch for during ongoing development.

---

## 1. Authentication & Authorization Architecture

### JWT + Cookie Hybrid
- Access tokens are short-lived JWTs passed via `Authorization: Bearer` header.
- Refresh tokens are long-lived, stored in httpOnly `SameSite=Lax` cookies (development) or `SameSite=Strict` (production).
- `StaffMiddleware` resolves both legacy coach JWTs (`role=coach`) and staff JWTs (`role=staff`) into a unified org context (`_org_id`, `_role`). This lets existing solo-coach accounts work unchanged while staff members are scoped to the same organization.

### Role Hierarchy
| Role | Scope |
|------|-------|
| `owner` (implicit via coach JWT) | Full access including billing/subscription management. |
| `admin` | Full client/plan access + team management (invite, deactivate, edit staff). Can view all staff activity. |
| `instructor_coach` | Full client/plan access. Can self-assign clients via "Take On". No billing access. |
| `manager` | Staff scheduling and client roster oversight. |
| `front_desk` | Check-ins, bookings, membership status only. Activity log access is forbidden (403). |

### Key Security Pattern
All controllers use `$params['_org_id'] ?? $params['_auth']['sub']` for client lookups. This means a staff member's queries are automatically scoped to their organization, while a solo coach still queries by their own ID.

---

## 2. Staff Features — Recent Implementation

### 2.1 Staff Profile Photos
- Staff can upload/delete profile photos via `POST /staff/profile/photo` and `DELETE /staff/profile/photo`.
- Uses the same `ProfilePhotoService` as coaches and clients (S3/R2 presigned URLs or local filesystem).
- Photos are stored in `staff.profile_photo` / `staff.profile_photo_key`.
- Frontend header (`DashboardHeader`) and sidebar (`Sidebar`) now display staff photos instead of default initials.

### 2.2 Admin Team Activity Center
- Added "Activity Log" tab to `/settings/team` with category filters: All, Schedule, Workout Plans, 1-on-1 Sessions, Nutrition Plans.
- Activities are already logged by `ActivityLogService::log()` whenever staff create/update clients, schedule check-ins, create plans, etc.
- Pagination supported via `useStaffActivities(page)`.
- Activity rows resolve staff photos from the staff list for visual consistency.

### 2.3 Client Assignment Visibility
- Client list "Assigned To" column now resolves `primary_staff_id` to actual staff names via `useStaffList()`.
- Priority: (1) Primary coach name + "Primary Coach" label, (2) `last_staff_name` + activity, (3) "Assigned" fallback, (4) "—".
- Backend already stores `primary_staff_id` on client records via `PATCH /org/clients/:id/assignment` and `POST /org/clients/:id/take-on`.

### 2.4 Staff Can Add/Import Clients
- Instructors (`instructor_coach`) can create clients via `/clients/new` and bulk-import via `/import-excel`.
- Backend routes `/coach/clients` and `/coach/clients/import` already use `StaffMiddleware`, so no permission changes were needed — only frontend UI visibility.

---

## 3. Notifications — Do Not Disturb (DND)

### Implementation
- `NotificationTriggerService::sendPushToCoach` now checks `coach.notification_settings.dnd_enabled`.
- DND window supports overnight ranges (e.g., 22:00 → 08:00) via minute-of-day comparison.
- When DND is active, **push notifications are suppressed** but **in-app notifications are still stored** in the `notifications` collection.
- This ensures coaches see everything when they return without being disturbed during off-hours.

### Why Not Block In-App Too?
In-app notifications are the persistent record of what happened. Blocking them would mean events silently disappear. Push suppression alone achieves the UX goal.

---

## 4. Frontend Patterns Worth Preserving

### Hydration-Safe Auth State
- Zustand auth store uses `persist` middleware with `partialize` for JWT fields.
- `hasHydrated` flag prevents premature UI decisions (e.g., redirecting before rehydration completes).
- `useSyncExternalStore` used for client-only detection in components that can't afford hydration mismatches.

### Staff-Aware Conditional UI
- Pattern: `const { isStaff, staffRole, staff } = useAuthStore();`
- Cards, buttons, and nav items conditionally render based on `isStaff` and `staffRole`.
- Example: "Your Clients" card only appears when `isStaff === true`. "Add Client" button is visible to all staff roles.

### Toast Mutation Pattern
- `useToastMutation` wraps React Query mutations with success/error toasts and automatic cache invalidation.
- Used for `useUpdateStaff`, `useTakeOnClient`, etc.

---

## 5. Database Schema Additions

### `staff` collection
```json
{
  "_id": "ObjectId",
  "org_id": "ObjectId",
  "email": "string",
  "name": "string",
  "role": "admin | manager | front_desk | instructor_coach",
  "status": "active | invited | deactivated",
  "profile_photo": "string | null",
  "profile_photo_key": "string | null",
  "invited_at": "UTCDateTime | null",
  "activated_at": "UTCDateTime | null",
  "created_at": "UTCDateTime",
  "updated_at": "UTCDateTime"
}
```

### `clients` collection (staff-related fields)
```json
{
  "primary_staff_id": "ObjectId | null",
  "last_staff_id": "ObjectId | null",
  "last_staff_name": "string | null",
  "last_staff_role": "string | null",
  "last_staff_activity": "string | null",
  "last_staff_activity_at": "UTCDateTime | null"
}
```

### `coaches.notification_settings`
```json
{
  "dnd_enabled": "boolean",
  "dnd_from": "string (HH:MM)",
  "dnd_to": "string (HH:MM)"
}
```

---

## 6. Endpoints Added / Modified

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/staff/profile` | PUT | Staff | Update own name |
| `/staff/profile/photo` | POST | Staff | Upload profile photo |
| `/staff/profile/photo` | DELETE | Staff | Remove profile photo |
| `/org/staff/:id` | PUT | Staff | Admin/owner updates staff name/role |
| `/org/clients/:id/take-on` | POST | Staff | Instructor self-assigns client |

---

## 7. Remaining Security / UX Gaps

1. **Coach push notifications still require `fcm_token` on coach document.** DND suppression was added, but coaches still need a way to register their FCM token. Consider adding `PUT /coach/fcm-token`.
2. **DND only applies to push, not SMS/email.** If future channels are added, they should respect the same DND window.
3. **Staff profile photos are not resolved to presigned URLs on every request.** Unlike coach photos, staff photos use the raw stored URL. If using S3/R2, these may expire.
4. **Activity Log pagination resets to page 1 when switching categories.** A minor UX quirk — the page state is not per-category.

---

*Last updated: 2026-10-01*
