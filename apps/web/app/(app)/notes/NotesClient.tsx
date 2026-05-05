"use client";

import Link from "next/link";
import { AxiosError } from "axios";
import { toast } from "sonner";
import useSWR from "swr";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

type Note = {
    id: number;
    drive_file_id: number;
    format: "pdf" | "epub" | "chm" | "djvu" | "other";
    page: number | null;
    cfi: string | null;
    chm_topic: string | null;
    selection_text: string | null;
    body: string | null;
    color: string | null;
    created_at: string | null;
    updated_at: string | null;
    drive_file: { id: number; name: string; format: string };
};

type NotesResponse = {
    data: Note[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
};

const fetcher = async (url: string) => (await api.get<NotesResponse>(url)).data;

function locator(n: Note): string {
    if (n.page != null) return `Page ${n.page}`;
    if (n.cfi) return `CFI`;
    if (n.chm_topic) return n.chm_topic;
    return "—";
}

export default function NotesClient() {
    const { data, isLoading, mutate } = useSWR<NotesResponse>("/notes", fetcher);
    const items = data?.data ?? [];

    async function remove(n: Note) {
        const previous = items;
        mutate(
            data ? { ...data, data: items.filter((x) => x.id !== n.id) } : undefined,
            { revalidate: false },
        );
        try {
            await api.delete(`/notes/${n.id}`);
            mutate();
            toast.success("Note removed.");
        } catch (err) {
            mutate(data ? { ...data, data: previous } : undefined, { revalidate: false });
            const axiosErr = err as AxiosError<{ message?: string }>;
            toast.error(axiosErr.response?.data?.message ?? "Could not remove the note.");
        }
    }

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader>
                    <CardTitle>Notes</CardTitle>
                    <CardDescription>
                        All your annotations across every ebook. Notes are private per user.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {isLoading ? (
                        <p className="text-sm text-muted-foreground">Loading…</p>
                    ) : items.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No notes yet. Highlight text in the reader once it&apos;s ready.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {items.map((n) => (
                                <li key={n.id} className="rounded-lg border p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex flex-col gap-1">
                                            <Link
                                                href={`/library/${n.drive_file_id}`}
                                                className="text-sm font-medium hover:underline"
                                            >
                                                {n.drive_file.name}
                                            </Link>
                                            <span className="text-xs text-muted-foreground">{locator(n)}</span>
                                        </div>
                                        <Button size="sm" variant="outline" onClick={() => remove(n)}>
                                            Remove
                                        </Button>
                                    </div>
                                    {n.selection_text ? (
                                        <blockquote className="mt-3 border-l-2 pl-3 text-sm italic text-muted-foreground">
                                            “{n.selection_text}”
                                        </blockquote>
                                    ) : null}
                                    {n.body ? <p className="mt-2 text-sm whitespace-pre-wrap">{n.body}</p> : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
