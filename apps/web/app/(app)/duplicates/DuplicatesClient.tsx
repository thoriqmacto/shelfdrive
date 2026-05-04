"use client";

import { useState } from "react";
import { AxiosError } from "axios";
import { toast } from "sonner";
import useSWR from "swr";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

type DriveFile = {
    id: number;
    connected_account_id: number;
    name: string;
    mime_type: string;
    size_bytes: number | null;
    md5_checksum: string | null;
    parent_folder_path: string | null;
    web_view_link: string | null;
    format: "pdf" | "epub" | "chm" | "djvu" | "other";
    drive_modified_time: string | null;
};

type Member = { id: number; drive_file: DriveFile };

type Group = {
    id: number;
    match_strategy: "md5" | "name_size_mime" | "name_only";
    confidence: "exact" | "likely" | "possible";
    scope: "account" | "cross_account";
    members: Member[];
};

const fetcher = async (url: string) => (await api.get<{ data: Group[] }>(url)).data.data;

function ConfidenceBadge({ value }: { value: Group["confidence"] }) {
    const cls =
        value === "exact"
            ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
            : value === "likely"
                ? "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                : "bg-zinc-100 text-zinc-700 dark:bg-zinc-900 dark:text-zinc-300";
    return <span className={`rounded-full px-2 py-0.5 text-xs ${cls}`}>{value}</span>;
}

function ScopeBadge({ value }: { value: Group["scope"] }) {
    const cls =
        value === "account"
            ? "border-border text-muted-foreground"
            : "border-blue-300 text-blue-700 dark:border-blue-700 dark:text-blue-300";
    return (
        <span className={`rounded-full border px-2 py-0.5 text-xs ${cls}`}>
            {value === "account" ? "same account" : "across accounts"}
        </span>
    );
}

function formatSize(bytes: number | null): string {
    if (bytes == null) return "";
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

function GroupRow({ group, onResolved }: { group: Group; onResolved: () => void }) {
    const [keeperId, setKeeperId] = useState<number | null>(group.members[0]?.drive_file.id ?? null);
    const [removeOthers, setRemoveOthers] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    async function resolve() {
        if (!keeperId) return;
        setSubmitting(true);
        try {
            await api.post(`/duplicates/${group.id}/resolve`, {
                canonical_drive_file_id: keeperId,
                remove_others_from_library: removeOthers,
            });
            toast.success(removeOthers ? "Resolved; non-keepers removed from library." : "Resolved.");
            onResolved();
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string }>;
            toast.error(axiosErr.response?.data?.message ?? "Could not resolve.");
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <li className="flex flex-col gap-3 p-4">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <ConfidenceBadge value={group.confidence} />
                <ScopeBadge value={group.scope} />
                <span className="text-xs text-muted-foreground">strategy: {group.match_strategy}</span>
            </div>
            <ul className="divide-y divide-border rounded-md border">
                {group.members.map((m) => (
                    <li key={m.id} className="flex items-center gap-3 p-3 text-sm">
                        <input
                            type="radio"
                            name={`keeper-${group.id}`}
                            checked={keeperId === m.drive_file.id}
                            onChange={() => setKeeperId(m.drive_file.id)}
                            className="h-4 w-4"
                        />
                        <div className="flex flex-1 flex-col">
                            <div className="flex items-center gap-2">
                                <span className="font-medium">{m.drive_file.name}</span>
                                <span className="text-xs text-muted-foreground">
                                    {formatSize(m.drive_file.size_bytes)} · {m.drive_file.format}
                                </span>
                            </div>
                            <div className="text-xs text-muted-foreground">
                                Account #{m.drive_file.connected_account_id}
                                {m.drive_file.parent_folder_path ? ` · ${m.drive_file.parent_folder_path}` : null}
                                {m.drive_file.md5_checksum ? ` · md5:${m.drive_file.md5_checksum.slice(0, 10)}…` : null}
                            </div>
                        </div>
                        {m.drive_file.web_view_link ? (
                            <a
                                href={m.drive_file.web_view_link}
                                target="_blank"
                                rel="noreferrer"
                                className="text-xs text-blue-600 hover:underline"
                            >
                                Open in Drive
                            </a>
                        ) : null}
                    </li>
                ))}
            </ul>
            <div className="flex flex-wrap items-center gap-3">
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={removeOthers}
                        onChange={(e) => setRemoveOthers(e.target.checked)}
                        className="h-4 w-4"
                    />
                    Remove non-keepers from the library (keeps files in Drive)
                </label>
                <Button size="sm" onClick={resolve} disabled={!keeperId || submitting}>
                    {submitting ? "Resolving…" : "Resolve"}
                </Button>
            </div>
        </li>
    );
}

export default function DuplicatesClient() {
    const { data, isLoading, error, mutate } = useSWR<Group[]>("/duplicates", fetcher);
    const groups = data ?? [];

    return (
        <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader>
                    <CardTitle>Duplicates</CardTitle>
                    <CardDescription>
                        Detected after every successful scan. Picking a keeper records your choice;
                        the &ldquo;remove from library&rdquo; option only hides files from the app — it
                        never touches Google Drive.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {isLoading ? (
                        <p className="text-sm text-muted-foreground">Loading…</p>
                    ) : error ? (
                        <p className="text-sm text-red-600">Could not load duplicates. Try refreshing.</p>
                    ) : groups.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No unresolved duplicate groups. Run a scan from the Sync page to refresh.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border rounded-lg border">
                            {groups.map((g) => (
                                <GroupRow key={g.id} group={g} onResolved={() => mutate()} />
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
