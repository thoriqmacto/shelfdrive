export type StoredAuthUser = {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string | null;
};

export type StoredAuth = {
    token: string;
    user: StoredAuthUser;
    expiresAt: string | null;
};

const STORAGE_KEY = "auth";
const HINT_COOKIE = "auth_hint";

export function readAuth(): StoredAuth | null {
    if (typeof window === "undefined") return null;
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw) as StoredAuth;
        if (!parsed?.token && parsed?.expiresAt !== null) return null;
        if (parsed.expiresAt && new Date(parsed.expiresAt).getTime() < Date.now()) {
            clearAuth();
            return null;
        }
        return parsed;
    } catch {
        clearAuth();
        return null;
    }
}

export function writeAuth(auth: StoredAuth): void {
    if (typeof window === "undefined") return;
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(auth));
    const maxAge = auth.expiresAt
        ? Math.max(0, Math.floor((new Date(auth.expiresAt).getTime() - Date.now()) / 1000))
        : 60 * 60 * 8;
    document.cookie = `${HINT_COOKIE}=1; Path=/; Max-Age=${maxAge}; SameSite=Lax`;
}

export function clearAuth(): void {
    if (typeof window === "undefined") return;
    window.localStorage.removeItem(STORAGE_KEY);
    document.cookie = `${HINT_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax`;
}

export function getToken(): string | null {
    return readAuth()?.token ?? null;
}
