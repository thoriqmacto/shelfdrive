"use client";

import { useState } from "react";
import Link from "next/link";
import { AxiosError } from "axios";
import { toast } from "sonner";
import useSWR from "swr";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";

type ListSummary = {
    id: number;
    name: string;
    description: string | null;
    item_count: number;
    updated_at: string | null;
};

const fetcher = async (url: string) => (await api.get<{ data: ListSummary[] }>(url)).data.data;

export default function ListsClient() {
    const { data, isLoading, mutate } = useSWR<ListSummary[]>("/lists", fetcher);
    const lists = data ?? [];
    const [name, setName] = useState("");
    const [creating, setCreating] = useState(false);

    async function create(e: React.FormEvent) {
        e.preventDefault();
        const trimmed = name.trim();
        if (!trimmed) return;
        setCreating(true);
        try {
            await api.post("/lists", { name: trimmed });
            setName("");
            mutate();
            toast.success(`Created “${trimmed}”.`);
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
            const msg = axiosErr.response?.data?.errors?.name?.[0]
                ?? axiosErr.response?.data?.message
                ?? "Could not create the list.";
            toast.error(msg);
        } finally {
            setCreating(false);
        }
    }

    async function remove(list: ListSummary) {
        if (!window.confirm(`Delete “${list.name}”? Items on this list won't be deleted from your library.`)) return;

        const previous = lists;
        mutate(previous.filter((l) => l.id !== list.id), { revalidate: false });
        try {
            await api.delete(`/lists/${list.id}`);
            mutate();
            toast.success("List deleted.");
        } catch (err) {
            mutate(previous, { revalidate: false });
            const axiosErr = err as AxiosError<{ message?: string }>;
            toast.error(axiosErr.response?.data?.message ?? "Could not delete the list.");
        }
    }

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader>
                    <CardTitle>Lists</CardTitle>
                    <CardDescription>
                        Curate your own playlists of ebooks — &ldquo;To Read&rdquo;, &ldquo;Cardiology
                        References&rdquo;, etc. Lists live in the app database; your Drive isn&apos;t modified.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <form onSubmit={create} className="flex gap-2">
                        <Input
                            placeholder="New list name…"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            maxLength={120}
                        />
                        <Button type="submit" disabled={creating || !name.trim()}>
                            {creating ? "Creating…" : "Create"}
                        </Button>
                    </form>

                    {isLoading ? (
                        <p className="text-sm text-muted-foreground">Loading…</p>
                    ) : lists.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No lists yet. Create your first one above.</p>
                    ) : (
                        <ul className="divide-y divide-border rounded-lg border">
                            {lists.map((list) => (
                                <li key={list.id} className="flex items-center justify-between gap-4 p-4">
                                    <Link href={`/lists/${list.id}`} className="flex flex-1 flex-col">
                                        <span className="font-medium hover:underline">{list.name}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {list.item_count} {list.item_count === 1 ? "item" : "items"}
                                            {list.description ? ` · ${list.description.slice(0, 80)}` : ""}
                                        </span>
                                    </Link>
                                    <Button size="sm" variant="outline" onClick={() => remove(list)}>
                                        Delete
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
