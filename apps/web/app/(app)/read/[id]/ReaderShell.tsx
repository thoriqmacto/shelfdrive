"use client";

import Link from "next/link";
import useSWR from "swr";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import PdfViewer from "./PdfViewer";

type BookDetail = {
    id: number;
    name: string;
    format: "pdf" | "epub" | "chm" | "djvu" | "other";
    web_view_link: string | null;
    progress: { page: number | null; percent: number; last_read_at: string | null } | null;
};

const fetcher = async (url: string) => (await api.get<{ data: BookDetail }>(url)).data.data;

/**
 * Top-level reader shell. Keeps the format dispatch in one place: PDF
 * is the only first-class viewer in this PR; EPUB / DJVU / CHM fall
 * back to "Open in Drive". The shell also fetches the resume page so
 * the viewer can mount on the right page directly.
 */
export default function ReaderShell({ id }: { id: number }) {
    const { data, error, isLoading } = useSWR<BookDetail>(`/library/${id}`, fetcher);

    if (isLoading) {
        return <div className="mx-auto max-w-4xl px-4 py-12 text-sm text-muted-foreground">Loading…</div>;
    }
    if (error || !data) {
        return (
            <div className="mx-auto flex max-w-3xl flex-col gap-3 px-4 py-12">
                <p className="text-sm text-red-600">Could not load this book.</p>
                <Link href="/library" className="text-sm hover:underline">← Back to library</Link>
            </div>
        );
    }

    if (data.format !== "pdf") {
        return (
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 px-4 py-12">
                <Link href={`/library/${id}`} className="text-xs text-muted-foreground hover:text-foreground">
                    ← Back
                </Link>
                <h1 className="text-xl font-semibold">{data.name}</h1>
                <p className="text-sm text-muted-foreground">
                    The in-app viewer currently supports PDF only. EPUB and DJVU support is coming;
                    CHM stays as &ldquo;Open in Drive&rdquo;.
                </p>
                {data.web_view_link ? (
                    <Button asChild>
                        <a href={data.web_view_link} target="_blank" rel="noreferrer">Open in Drive</a>
                    </Button>
                ) : null}
            </div>
        );
    }

    return (
        <PdfViewer
            fileId={id}
            title={data.name}
            initialPage={data.progress?.page ?? 1}
            webViewLink={data.web_view_link}
        />
    );
}
