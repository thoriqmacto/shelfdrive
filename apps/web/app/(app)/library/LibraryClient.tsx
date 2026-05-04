"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";

type LibraryFile = {
    id: number;
    name: string;
    mime_type: string;
    size_bytes: number | null;
    format: "pdf" | "epub" | "chm" | "djvu" | "other";
    connected_account_id: number;
    web_view_link: string | null;
    cover_thumb_url: string | null;
    drive_modified_time: string | null;
};

type LibraryResponse = {
    data: LibraryFile[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
};

const FORMATS: Array<LibraryFile["format"] | "all"> = ["all", "pdf", "epub", "chm", "djvu"];

const fetcher = async (url: string) => (await api.get<LibraryResponse>(url)).data;

function formatSize(bytes: number | null): string {
    if (bytes == null) return "—";
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

export default function LibraryClient() {
    const [view, setView] = useState<"grid" | "list">("grid");
    const [q, setQ] = useState("");
    const [format, setFormat] = useState<(typeof FORMATS)[number]>("all");

    const params = new URLSearchParams();
    if (q.trim()) params.set("q", q.trim());
    if (format !== "all") params.set("format", format);
    params.set("per_page", "100");
    const url = `/library?${params.toString()}`;

    const { data, isLoading, error } = useSWR<LibraryResponse>(url, fetcher, {
        keepPreviousData: true,
    });

    const files = data?.data ?? [];

    return (
        <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader className="flex flex-col gap-3">
                    <div className="flex items-center justify-between gap-3">
                        <CardTitle>Library</CardTitle>
                        <div className="flex items-center gap-1 rounded-md border p-0.5">
                            <Button
                                size="sm"
                                variant={view === "grid" ? "default" : "ghost"}
                                onClick={() => setView("grid")}
                            >
                                Grid
                            </Button>
                            <Button
                                size="sm"
                                variant={view === "list" ? "default" : "ghost"}
                                onClick={() => setView("list")}
                            >
                                List
                            </Button>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            placeholder="Search by name…"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            className="max-w-xs"
                        />
                        <div className="flex items-center gap-1">
                            {FORMATS.map((f) => (
                                <Button
                                    key={f}
                                    size="sm"
                                    variant={format === f ? "default" : "outline"}
                                    onClick={() => setFormat(f)}
                                    className="capitalize"
                                >
                                    {f}
                                </Button>
                            ))}
                        </div>
                        {data ? (
                            <span className="ml-auto text-xs text-muted-foreground">
                                {data.meta.total} {data.meta.total === 1 ? "ebook" : "ebooks"}
                            </span>
                        ) : null}
                    </div>
                </CardHeader>
                <CardContent>
                    {isLoading && !data ? (
                        <p className="text-sm text-muted-foreground">Loading…</p>
                    ) : error ? (
                        <p className="text-sm text-red-600">Could not load the library.</p>
                    ) : files.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No ebooks match. Connect a Drive account on the Accounts page and run a scan.
                        </p>
                    ) : view === "grid" ? (
                        <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                            {files.map((f) => (
                                <li key={f.id}>
                                    <Link
                                        href={`/library/${f.id}`}
                                        className="block rounded-lg border p-3 hover:bg-muted/50"
                                    >
                                        <div className="aspect-[3/4] overflow-hidden rounded bg-muted">
                                            {f.cover_thumb_url ? (
                                                // eslint-disable-next-line @next/next/no-img-element
                                                <img
                                                    src={f.cover_thumb_url}
                                                    alt=""
                                                    className="h-full w-full object-cover"
                                                />
                                            ) : (
                                                <div className="flex h-full w-full items-center justify-center text-3xl font-light text-muted-foreground">
                                                    {f.format.toUpperCase()}
                                                </div>
                                            )}
                                        </div>
                                        <div className="mt-2 line-clamp-2 text-sm font-medium">{f.name}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {formatSize(f.size_bytes)} · {f.format}
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="py-2">Name</th>
                                        <th className="py-2">Format</th>
                                        <th className="py-2">Size</th>
                                        <th className="py-2">Modified</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {files.map((f) => (
                                        <tr key={f.id}>
                                            <td className="py-2">
                                                <Link href={`/library/${f.id}`} className="hover:underline">
                                                    {f.name}
                                                </Link>
                                            </td>
                                            <td className="py-2 text-muted-foreground">{f.format}</td>
                                            <td className="py-2 text-muted-foreground">{formatSize(f.size_bytes)}</td>
                                            <td className="py-2 text-muted-foreground">
                                                {f.drive_modified_time
                                                    ? new Date(f.drive_modified_time).toLocaleDateString()
                                                    : "—"}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
