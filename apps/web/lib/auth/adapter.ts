import type { StoredAuth } from "./storage";

export type LoginPayload = { email: string; password: string };

export type RegisterPayload = {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
};

export type AuthMode = "bearer" | "cookie" | "mock" | "google";

export type AuthAdapter = {
    mode: AuthMode;
    login: (payload: LoginPayload) => Promise<StoredAuth>;
    register: (payload: RegisterPayload) => Promise<StoredAuth>;
    me: () => Promise<StoredAuth["user"]>;
    logout: () => Promise<void>;
    // Optional redirect entry point for OAuth-style adapters (Google).
    // Bearer/cookie/mock leave this undefined — credential flows do not
    // redirect.
    redirectStart?: (next?: string) => void;
    // Optional one-time code → token exchange. Called on /login when a
    // redirect-based adapter has handed off via ?google_code=… in the URL.
    completeRedirect?: (code: string) => Promise<StoredAuth>;
};
