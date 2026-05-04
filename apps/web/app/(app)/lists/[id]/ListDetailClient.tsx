"use client";

import Link from "next/link";
import { AxiosError } from "axios";
import { toast } from "sonner";
import useSWR from "swr";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

type DriveFile = {
    id: number;
    name: string;
    format: "pdf" | "epub" | "chm" | "djvu" | "other";
    size_bytes: number | null;
    web_view_link: string | null;
};

type Item = {
    id: number;
    position: number;
    added_at: string | null;
    drive_file: DriveFile;
};

type ListDetail = {
    id: number;
    name: string;
    description: string | null;
    cover_drive_file_id: number | null;
    items: Item[];
};

const fetcher = async (url: string) => (await api.get<{ data: ListDetail }>(url)).data.data;

function formatSize(bytes: number | null): string {
    if (bytes == null) return "";
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

export default function ListDetailClient({ id }: { id: number }) {
    const { data, error, isLoading, mutate } = useSWR<ListDetail>(`/lists/${id}`, fetcher);

    async function removeItem(item: Item) {
        if (!data) return;
        const previous = data;
        mutate(
            { ...data, items: data.items.filter((i) => i.id !== item.id) },
            { revalidate: false },
        );
        try {
            await api.delete(`/lists/${id}/items/${item.id}`);
            mutate();
            toast.success("Removed from list.");
        } catch (err) {
            mutate(previous, { revalidate: false });
            const axiosErr = err as AxiosError<{ message?: string }>;
            toast.error(axiosErr.response?.data?.message ?? "Could not remove the item.");
        }
    }

    if (isLoading) {
        return <div className="mx-auto max-w-3xl px-4 py-12 text-sm text-muted-foreground">Loading…</div>;
    }
    if (error || !data) {
        return (
            <div className="mx-auto flex max-w-3xl flex-col gap-3 px-4 py-12">
                <p className="text-sm text-red-600">Could not load this list.</p>
                <Link href="/lists" className="text-sm hover:underline">← Back to all lists</Link>
            </div>
        );
    }

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-12">
            <Link href="/lists" className="text-xs text-muted-foreground hover:text-foreground">
                ← All lists
            </Link>
            <Card>
                <CardHeader>
                    <CardTitle>{data.name}</CardTitle>
                    {data.description ? <CardDescription>{data.description}</CardDescription> : null}
                </CardHeader>
                <CardContent>
                    {data.items.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No items yet. Add ebooks from the Library page once it&apos;s ready.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border rounded-lg border">
                            {data.items.map((item) => (
                                <li key={item.id} className="flex items-center gap-4 p-3">
                                    <span className="w-6 text-xs text-muted-foreground">{item.position}.</span>
                                    <div className="flex flex-1 flex-col">
                                        <span className="text-sm font-medium">{item.drive_file.name}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {formatSize(item.drive_file.size_bytes)} · {item.drive_file.format}
                                        </span>
                                    </div>
                                    {item.drive_file.web_view_link ? (
                                        <a
                                            href={item.drive_file.web_view_link}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-xs text-blue-600 hover:underline"
                                        >
                                            Open in Drive
                                        </a>
                                    ) : null}
                                    <Button size="sm" variant="outline" onClick={() => removeItem(item)}>
                                        Remove
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
