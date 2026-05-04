"use client";

import { Suspense, useEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import { AxiosError } from "axios";
import { toast } from "sonner";
import useSWR from "swr";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

type ConnectedAccount = {
    id: number;
    google_sub: string;
    email: string | null;
    display_name: string | null;
    purpose: "login" | "drive";
    status: "active" | "revoked" | "error";
    last_full_scan_at: string | null;
    last_incremental_sync_at: string | null;
    created_at: string | null;
};

const DRIVE_ERROR_COPY: Record<string, string> = {
    invalid_state: "The connect link expired. Please try again.",
    exchange_failed: "Google rejected the connect attempt. Please try again.",
    invalid_userinfo: "We couldn't read the Google profile. Please try again.",
    primary_account_cannot_be_drive: "That's already your sign-in account. Connect a different Google account for Drive.",
    access_denied: "Connect cancelled.",
};

const fetcher = async (url: string) => (await api.get<{ data: ConnectedAccount[] }>(url)).data.data;

function StatusBadge({ status }: { status: ConnectedAccount["status"] }) {
    const cls =
        status === "active"
            ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
            : status === "revoked"
                ? "bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300"
                : "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300";
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs ${cls}`}>{status}</span>
    );
}

function PurposeBadge({ purpose }: { purpose: ConnectedAccount["purpose"] }) {
    return (
        <span className="inline-flex items-center rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
            {purpose === "login" ? "primary login" : "drive source"}
        </span>
    );
}

function AccountsView() {
    const searchParams = useSearchParams();
    const handledRef = useRef(false);
    const [connecting, setConnecting] = useState(false);
    const { data, error, isLoading, mutate } = useSWR<ConnectedAccount[]>("/accounts", fetcher);

    useEffect(() => {
        if (handledRef.current) return;
        const connected = searchParams.get("drive_connected");
        const errorCode = searchParams.get("drive_error");
        const email = searchParams.get("email");

        if (connected) {
            handledRef.current = true;
            toast.success(email ? `Connected ${email}` : "Drive account connected.");
            mutate();
        } else if (errorCode) {
            handledRef.current = true;
            toast.error(DRIVE_ERROR_COPY[errorCode] ?? "Could not connect Google Drive. Please try again.");
        }
    }, [searchParams, mutate]);

    async function startConnect() {
        setConnecting(true);
        try {
            const { data } = await api.get<{ url: string }>("/drive/oauth/start", {
                params: { next: "/accounts" },
            });
            window.location.href = data.url;
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string }>;
            toast.error(axiosErr.response?.data?.message ?? "Could not start the Google connect flow.");
            setConnecting(false);
        }
    }

    async function disconnect(account: ConnectedAccount) {
        if (account.purpose === "login") {
            toast.error("You can't disconnect the primary login account.");
            return;
        }
        const confirmed = window.confirm(
            `Disconnect ${account.email ?? account.google_sub}? Your library entries from this account stay in the database but will stop syncing.`,
        );
        if (!confirmed) return;

        // Optimistic remove.
        const previous = data ?? [];
        mutate(previous.filter((a) => a.id !== account.id), { revalidate: false });
        try {
            await api.delete(`/accounts/${account.id}`);
            toast.success("Account disconnected.");
            mutate();
        } catch (err) {
            mutate(previous, { revalidate: false });
            const axiosErr = err as AxiosError<{ message?: string }>;
            toast.error(axiosErr.response?.data?.message ?? "Could not disconnect that account.");
        }
    }

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader>
                    <CardTitle>Connected Google accounts</CardTitle>
                    <CardDescription>
                        Connect additional Google accounts to scan their Drive for ebooks. Your sign-in
                        account is shown for reference and can&apos;t be disconnected here.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <Button onClick={startConnect} disabled={connecting} className="self-start">
                        {connecting ? "Redirecting…" : "Connect another Google account"}
                    </Button>

                    {isLoading ? (
                        <p className="text-sm text-muted-foreground">Loading…</p>
                    ) : error ? (
                        <p className="text-sm text-red-600">Could not load accounts. Try refreshing.</p>
                    ) : (data ?? []).length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No connected accounts yet. Connect one to start indexing your ebooks.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border rounded-lg border">
                            {(data ?? []).map((account) => (
                                <li
                                    key={account.id}
                                    className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="flex flex-col gap-1">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">{account.email ?? account.google_sub}</span>
                                            <PurposeBadge purpose={account.purpose} />
                                            <StatusBadge status={account.status} />
                                        </div>
                                        {account.display_name ? (
                                            <span className="text-xs text-muted-foreground">{account.display_name}</span>
                                        ) : null}
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => disconnect(account)}
                                        disabled={account.purpose === "login"}
                                    >
                                        Disconnect
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

export default function AccountsClient() {
    return (
        <Suspense fallback={null}>
            <AccountsView />
        </Suspense>
    );
}
