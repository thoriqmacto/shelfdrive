"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { toast } from "sonner";
import useSWR from "swr";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

type ConnectedAccount = {
    id: number;
    email: string | null;
    display_name: string | null;
    purpose: "login" | "drive";
    status: "active" | "revoked" | "error";
    last_full_scan_at: string | null;
};

type SyncRun = {
    id: number;
    connected_account_id: number;
    kind: "full" | "incremental" | "manual";
    status: "running" | "success" | "error" | "partial";
    files_seen: number;
    files_added: number;
    files_updated: number;
    files_removed: number;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
};

const fetcher = async <T,>(url: string) => (await api.get<{ data: T }>(url)).data.data;

function StatusPill({ status }: { status: SyncRun["status"] }) {
    const cls =
        status === "success"
            ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
            : status === "error"
                ? "bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300"
                : status === "partial"
                    ? "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                    : "bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300";
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs ${cls}`}>{status}</span>
    );
}

function relTime(iso: string | null): string {
    if (!iso) return "—";
    const t = new Date(iso).getTime();
    if (Number.isNaN(t)) return "—";
    const diffMs = Date.now() - t;
    const mins = Math.floor(diffMs / 60_000);
    if (mins < 1) return "just now";
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return new Date(iso).toLocaleDateString();
}

export default function SyncClient() {
    const accounts = useSWR<ConnectedAccount[]>("/accounts", fetcher);
    const runs = useSWR<SyncRun[]>("/sync", fetcher, { refreshInterval: 5000 });
    const [runningIds, setRunningIds] = useState<Set<number>>(new Set());

    const driveAccounts = (accounts.data ?? []).filter((a) => a.purpose === "drive");

    async function scanNow(account: ConnectedAccount) {
        setRunningIds((s) => new Set(s).add(account.id));
        try {
            await api.post(`/sync/${account.id}/run`);
            toast.success(`Scan dispatched for ${account.email ?? account.id}.`);
            // Give the queue a tick, then refetch.
            setTimeout(() => runs.mutate(), 600);
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string }>;
            toast.error(axiosErr.response?.data?.message ?? "Could not start scan.");
        } finally {
            setRunningIds((s) => {
                const next = new Set(s);
                next.delete(account.id);
                return next;
            });
        }
    }

    const accountById = new Map(driveAccounts.map((a) => [a.id, a]));

    return (
        <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader>
                    <CardTitle>Drive sync</CardTitle>
                    <CardDescription>
                        Trigger manual scans of your connected Drive accounts and watch their history.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    {accounts.isLoading ? (
                        <p className="text-sm text-muted-foreground">Loading accounts…</p>
                    ) : driveAccounts.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No Drive accounts connected yet. Connect one from the Accounts page.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border rounded-lg border">
                            {driveAccounts.map((account) => (
                                <li
                                    key={account.id}
                                    className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <div className="font-medium">{account.email ?? `Account ${account.id}`}</div>
                                        <div className="text-xs text-muted-foreground">
                                            Last full scan: {relTime(account.last_full_scan_at)}
                                        </div>
                                    </div>
                                    <Button
                                        size="sm"
                                        onClick={() => scanNow(account)}
                                        disabled={account.status !== "active" || runningIds.has(account.id)}
                                    >
                                        {runningIds.has(account.id) ? "Dispatching…" : "Scan now"}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Recent runs</CardTitle>
                    <CardDescription>Latest 50 sync runs across all your accounts.</CardDescription>
                </CardHeader>
                <CardContent>
                    {runs.isLoading ? (
                        <p className="text-sm text-muted-foreground">Loading…</p>
                    ) : (runs.data ?? []).length === 0 ? (
                        <p className="text-sm text-muted-foreground">No scan history yet.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="py-2">Account</th>
                                        <th className="py-2">Kind</th>
                                        <th className="py-2">Status</th>
                                        <th className="py-2">Seen / + / ~ / -</th>
                                        <th className="py-2">Started</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {(runs.data ?? []).map((r) => {
                                        const account = accountById.get(r.connected_account_id);
                                        return (
                                            <tr key={r.id}>
                                                <td className="py-2 pr-4">{account?.email ?? `#${r.connected_account_id}`}</td>
                                                <td className="py-2 pr-4 text-muted-foreground">{r.kind}</td>
                                                <td className="py-2 pr-4">
                                                    <StatusPill status={r.status} />
                                                </td>
                                                <td className="py-2 pr-4 font-mono text-xs">
                                                    {r.files_seen} / {r.files_added} / {r.files_updated} / {r.files_removed}
                                                </td>
                                                <td className="py-2 pr-4 text-muted-foreground">{relTime(r.started_at)}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
