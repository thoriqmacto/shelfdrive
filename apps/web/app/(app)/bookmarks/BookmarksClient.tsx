"use client";

import Link from "next/link";
import { AxiosError } from "axios";
import { toast } from "sonner";
import useSWR from "swr";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

type Bookmark = {
    id: number;
    drive_file_id: number;
    format: "pdf" | "epub" | "chm" | "djvu" | "other";
    page: number | null;
    cfi: string | null;
    chm_topic: string | null;
    label: string | null;
    created_at: string | null;
    drive_file: { id: number; name: string; format: string };
};

type BookmarksResponse = {
    data: Bookmark[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
};

const fetcher = async (url: string) => (await api.get<BookmarksResponse>(url)).data;

function locator(b: Bookmark): string {
    if (b.page != null) return `Page ${b.page}`;
    if (b.cfi) return `CFI`;
    if (b.chm_topic) return b.chm_topic;
    return "—";
}

export default function BookmarksClient() {
    const { data, isLoading, mutate } = useSWR<BookmarksResponse>("/bookmarks", fetcher);
    const items = data?.data ?? [];

    async function remove(b: Bookmark) {
        const previous = items;
        mutate(
            data ? { ...data, data: items.filter((x) => x.id !== b.id) } : undefined,
            { revalidate: false },
        );
        try {
            await api.delete(`/bookmarks/${b.id}`);
            mutate();
            toast.success("Bookmark removed.");
        } catch (err) {
            mutate(data ? { ...data, data: previous } : undefined, { revalidate: false });
            const axiosErr = err as AxiosError<{ message?: string }>;
            toast.error(axiosErr.response?.data?.message ?? "Could not remove the bookmark.");
        }
    }

    // Group by drive_file_id for the by-book view.
    const byFile = new Map<number, { name: string; entries: Bookmark[] }>();
    for (const b of items) {
        const cur = byFile.get(b.drive_file_id);
        if (cur) cur.entries.push(b);
        else byFile.set(b.drive_file_id, { name: b.drive_file.name, entries: [b] });
    }

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader>
                    <CardTitle>Bookmarks</CardTitle>
                    <CardDescription>
                        All your saved positions across every ebook. Bookmarks are private to your account.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {isLoading ? (
                        <p className="text-sm text-muted-foreground">Loading…</p>
                    ) : items.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No bookmarks yet. Add some from the reader once it&apos;s ready.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-4">
                            {Array.from(byFile.entries()).map(([fileId, group]) => (
                                <li key={fileId} className="rounded-lg border">
                                    <div className="border-b bg-muted/30 px-4 py-2 text-sm font-medium">
                                        <Link href={`/library/${fileId}`} className="hover:underline">
                                            {group.name}
                                        </Link>
                                    </div>
                                    <ul className="divide-y divide-border">
                                        {group.entries.map((b) => (
                                            <li key={b.id} className="flex items-center justify-between gap-4 px-4 py-2 text-sm">
                                                <div className="flex flex-col">
                                                    <span>{b.label ?? locator(b)}</span>
                                                    {b.label ? (
                                                        <span className="text-xs text-muted-foreground">{locator(b)}</span>
                                                    ) : null}
                                                </div>
                                                <Button size="sm" variant="outline" onClick={() => remove(b)}>
                                                    Remove
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
