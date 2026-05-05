"use client";

import Link from "next/link";
import useSWR from "swr";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

type BookDetail = {
    id: number;
    name: string;
    mime_type: string;
    size_bytes: number | null;
    format: "pdf" | "epub" | "chm" | "djvu" | "other";
    connected_account_id: number;
    web_view_link: string | null;
    cover_thumb_url: string | null;
    parent_folder_path: string | null;
    md5_checksum: string | null;
    drive_modified_time: string | null;
    progress: {
        page: number | null;
        cfi: string | null;
        percent: number;
        last_read_at: string | null;
    } | null;
    bookmark_count: number;
    note_count: number;
};

const fetcher = async (url: string) => (await api.get<{ data: BookDetail }>(url)).data.data;

function formatSize(bytes: number | null): string {
    if (bytes == null) return "—";
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

export default function BookDetailClient({ id }: { id: number }) {
    const { data, error, isLoading } = useSWR<BookDetail>(`/library/${id}`, fetcher);

    if (isLoading) {
        return <div className="mx-auto max-w-3xl px-4 py-12 text-sm text-muted-foreground">Loading…</div>;
    }
    if (error || !data) {
        return (
            <div className="mx-auto flex max-w-3xl flex-col gap-3 px-4 py-12">
                <p className="text-sm text-red-600">Could not load this book.</p>
                <Link href="/library" className="text-sm hover:underline">← Back to library</Link>
            </div>
        );
    }

    const readableInBrowser = data.format === "pdf" || data.format === "epub" || data.format === "djvu";

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-12">
            <Link href="/library" className="text-xs text-muted-foreground hover:text-foreground">
                ← Library
            </Link>
            <Card>
                <CardHeader>
                    <CardTitle>{data.name}</CardTitle>
                    <CardDescription>
                        {data.format.toUpperCase()} · {formatSize(data.size_bytes)}
                        {data.parent_folder_path ? ` · ${data.parent_folder_path}` : null}
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center gap-2">
                        {readableInBrowser ? (
                            <Button asChild>
                                <Link href={`/read/${data.id}`}>
                                    {data.progress?.page
                                        ? `Resume reading (page ${data.progress.page})`
                                        : "Read now"}
                                </Link>
                            </Button>
                        ) : data.web_view_link ? (
                            <Button asChild>
                                <a href={data.web_view_link} target="_blank" rel="noreferrer">
                                    Open in Drive
                                </a>
                            </Button>
                        ) : null}
                        {readableInBrowser && data.web_view_link ? (
                            <Button variant="outline" asChild>
                                <a href={data.web_view_link} target="_blank" rel="noreferrer">
                                    Open in Drive
                                </a>
                            </Button>
                        ) : null}
                    </div>

                    <div className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                        <Stat label="Bookmarks" value={data.bookmark_count} />
                        <Stat label="Notes" value={data.note_count} />
                        <Stat
                            label="Progress"
                            value={data.progress ? `${Math.round(data.progress.percent)}%` : "—"}
                        />
                        <Stat
                            label="Last read"
                            value={
                                data.progress?.last_read_at
                                    ? new Date(data.progress.last_read_at).toLocaleDateString()
                                    : "—"
                            }
                        />
                    </div>

                    {data.md5_checksum ? (
                        <p className="text-xs text-muted-foreground">md5 {data.md5_checksum}</p>
                    ) : null}
                </CardContent>
            </Card>
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="rounded-lg border p-3">
            <div className="text-xs uppercase text-muted-foreground">{label}</div>
            <div className="text-lg font-medium">{value}</div>
        </div>
    );
}
