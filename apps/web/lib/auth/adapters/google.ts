import { api } from "@/lib/api";
import { API_BASE_URL } from "@/lib/env";
import type { AuthAdapter } from "../adapter";
import type { StoredAuth } from "../storage";

type AuthResponse = {
    user: StoredAuth["user"];
    token: string;
    expires_at: string | null;
};

/**
 * Strip the `/api/v1` (or any path) suffix from `NEXT_PUBLIC_API_BASE_URL`
 * to get the API origin we should redirect to for the OAuth start route.
 *
 * Falls back to a sensible localhost default if the URL can't be parsed.
 */
function apiOrigin(): string {
    try {
        return new URL(API_BASE_URL).origin;
    } catch {
        return "http://localhost:8000";
    }
}

export const googleAdapter: AuthAdapter = {
    mode: "google",

    // Google login is redirect-based; the credential `login` shape doesn't
    // apply. We expose the same method only to satisfy the interface, and
    // surface a clear error if a credential form is wired against this
    // adapter by mistake.
    async login() {
        throw new Error(
            "googleAdapter.login is not callable. Use redirectStart() to begin the OAuth flow.",
        );
    },

    async register() {
        throw new Error(
            "googleAdapter.register is not callable. Sign up happens automatically on first Google login.",
        );
    },

    async me() {
        const { data } = await api.get<{ user: StoredAuth["user"] }>("/me");
        return data.user;
    },

    async logout() {
        await api.post("/logout");
    },

    redirectStart(next?: string) {
        if (typeof window === "undefined") return;
        const origin = apiOrigin();
        const url = new URL(`${origin}/api/v1/auth/google/start`);
        if (next) url.searchParams.set("next", next);
        window.location.href = url.toString();
    },

    async completeRedirect(code: string) {
        const { data } = await api.post<AuthResponse>("/auth/google/exchange", { code });
        return { token: data.token, user: data.user, expiresAt: data.expires_at };
    },
};
