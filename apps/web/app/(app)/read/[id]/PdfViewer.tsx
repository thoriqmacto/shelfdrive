"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { Document, Page, pdfjs } from "react-pdf";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { API_BASE_URL } from "@/lib/env";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import "react-pdf/dist/Page/AnnotationLayer.css";
import "react-pdf/dist/Page/TextLayer.css";

type Bookmark = {
    id: number;
    page: number | null;
    label: string | null;
};

const PROGRESS_DEBOUNCE_MS = 1500;

/**
 * Strip `/api/v1` (or whatever path) off NEXT_PUBLIC_API_BASE_URL so we
 * can construct the absolute /api/v1/library/{id}/stream?token=… URL
 * pdf.js will fetch directly. The token comes from /stream/access.
 */
function streamBaseUrl(): string {
    return API_BASE_URL.replace(/\/+$/, "");
}

export default function PdfViewer({
    fileId,
    title,
    initialPage,
    webViewLink,
}: {
    fileId: number;
    title: string;
    initialPage: number;
    webViewLink: string | null;
}) {
    const [streamUrl, setStreamUrl] = useState<string | null>(null);
    const [streamError, setStreamError] = useState<string | null>(null);
    const [numPages, setNumPages] = useState<number | null>(null);
    const [page, setPage] = useState<number>(initialPage > 0 ? initialPage : 1);
    const [pageInput, setPageInput] = useState<string>(String(initialPage > 0 ? initialPage : 1));
    const [pageWidth, setPageWidth] = useState<number>(800);
    const [bookmarks, setBookmarks] = useState<Bookmark[]>([]);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const lastSavedPageRef = useRef<number>(0);

    // pdf.js worker is configured the first time the viewer mounts on
    // the client. Doing it inside an effect keeps server-side bundling
    // free of the pdfjs side effect.
    useEffect(() => {
        if (!pdfjs.GlobalWorkerOptions.workerSrc) {
            pdfjs.GlobalWorkerOptions.workerSrc = `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/${pdfjs.version}/pdf.worker.min.mjs`;
        }
    }, []);

    // Mint a single-use stream token, then keep the URL until unmount.
    // The actual pdf.js Range fetches go through the same URL; pdf.js
    // does its own conditional Range requests so the file doesn't
    // need to be fully downloaded.
    useEffect(() => {
        let cancelled = false;
        api.post<{ token: string }>(`/library/${fileId}/stream/access`)
            .then(({ data }) => {
                if (cancelled) return;
                setStreamUrl(`${streamBaseUrl()}/library/${fileId}/stream?token=${encodeURIComponent(data.token)}`);
            })
            .catch(() => {
                if (cancelled) return;
                setStreamError("Could not start a streaming session for this file.");
            });
        return () => {
            cancelled = true;
        };
    }, [fileId]);

    // Initial bookmark load.
    useEffect(() => {
        api.get<{ data: Bookmark[] }>(`/library/${fileId}/bookmarks`)
            .then(({ data }) => setBookmarks(data.data))
            .catch(() => {
                /* non-fatal */
            });
    }, [fileId]);

    // Responsive page width: track container width.
    useEffect(() => {
        if (!containerRef.current) return;
        const obs = new ResizeObserver((entries) => {
            const w = entries[0]?.contentRect.width;
            if (w) setPageWidth(Math.min(1100, Math.max(280, Math.floor(w - 16))));
        });
        obs.observe(containerRef.current);
        return () => obs.disconnect();
    }, []);

    // Debounced progress save when page changes.
    useEffect(() => {
        if (page === lastSavedPageRef.current) return;
        const t = window.setTimeout(() => {
            lastSavedPageRef.current = page;
            const percent = numPages ? Math.min(100, Math.max(0, (page / numPages) * 100)) : 0;
            api.patch(`/library/${fileId}/progress`, { page, percent }).catch(() => {
                /* swallow — non-fatal */
            });
        }, PROGRESS_DEBOUNCE_MS);
        return () => window.clearTimeout(t);
    }, [page, numPages, fileId]);

    const onLoadSuccess = useCallback(({ numPages: n }: { numPages: number }) => {
        setNumPages(n);
        if (initialPage > n) setPage(n);
    }, [initialPage]);

    const goto = useCallback(
        (target: number) => {
            const clamped = Math.max(1, Math.min(numPages ?? target, target));
            setPage(clamped);
            setPageInput(String(clamped));
        },
        [numPages],
    );

    const next = useCallback(() => goto(page + 1), [page, goto]);
    const prev = useCallback(() => goto(page - 1), [page, goto]);

    // Keyboard nav.
    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            const target = e.target as HTMLElement | null;
            if (target && (target.tagName === "INPUT" || target.tagName === "TEXTAREA")) return;
            if (e.key === "ArrowRight" || e.key === " " || e.key === "PageDown") {
                e.preventDefault();
                next();
            } else if (e.key === "ArrowLeft" || e.key === "PageUp") {
                e.preventDefault();
                prev();
            }
        }
        window.addEventListener("keydown", onKey);
        return () => window.removeEventListener("keydown", onKey);
    }, [next, prev]);

    async function addBookmark() {
        try {
            const { data } = await api.post<{ data: Bookmark }>(
                `/library/${fileId}/bookmarks`,
                { page },
            );
            setBookmarks((prev) => [...prev, data.data].sort((a, b) => (a.page ?? 0) - (b.page ?? 0)));
            toast.success(`Bookmarked page ${page}.`);
        } catch {
            toast.error("Could not save the bookmark.");
        }
    }

    async function removeBookmark(b: Bookmark) {
        try {
            await api.delete(`/bookmarks/${b.id}`);
            setBookmarks((prev) => prev.filter((x) => x.id !== b.id));
            toast.success("Bookmark removed.");
        } catch {
            toast.error("Could not remove the bookmark.");
        }
    }

    async function addNote() {
        const body = window.prompt("Note for this page (optional):", "");
        if (body === null) return;
        try {
            await api.post(`/library/${fileId}/notes`, { page, body });
            toast.success(`Note saved on page ${page}.`);
        } catch {
            toast.error("Could not save the note.");
        }
    }

    const pageHeader = useMemo(() => `${page}${numPages ? ` / ${numPages}` : ""}`, [page, numPages]);

    return (
        <div className="flex min-h-[calc(100vh-3.5rem)] flex-col">
            <div className="sticky top-0 z-10 flex flex-wrap items-center gap-2 border-b bg-background px-3 py-2 text-sm">
                <Link href={`/library/${fileId}`} className="text-xs text-muted-foreground hover:text-foreground">
                    ← Back
                </Link>
                <span className="line-clamp-1 max-w-xs font-medium">{title}</span>
                <div className="ml-auto flex items-center gap-2">
                    <Button size="sm" variant="ghost" onClick={prev} disabled={page <= 1}>
                        ←
                    </Button>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            const n = Number(pageInput);
                            if (Number.isFinite(n) && n > 0) goto(Math.floor(n));
                        }}
                        className="flex items-center gap-1"
                    >
                        <Input
                            value={pageInput}
                            onChange={(e) => setPageInput(e.target.value)}
                            inputMode="numeric"
                            className="h-8 w-14 text-center"
                            aria-label="Page number"
                        />
                        <span className="text-xs text-muted-foreground">{numPages ? `/ ${numPages}` : ""}</span>
                    </form>
                    <Button size="sm" variant="ghost" onClick={next} disabled={!!numPages && page >= numPages}>
                        →
                    </Button>
                    <Button size="sm" variant="outline" onClick={addBookmark} disabled={!numPages}>
                        Bookmark
                    </Button>
                    <Button size="sm" variant="outline" onClick={addNote} disabled={!numPages}>
                        Note
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setDrawerOpen((v) => !v)}>
                        {drawerOpen ? "Hide" : "Bookmarks"}
                    </Button>
                    {webViewLink ? (
                        <a
                            href={webViewLink}
                            target="_blank"
                            rel="noreferrer"
                            className="text-xs text-blue-600 hover:underline"
                        >
                            Drive
                        </a>
                    ) : null}
                </div>
            </div>

            <div className="flex flex-1 overflow-hidden">
                <div ref={containerRef} className="flex flex-1 justify-center overflow-auto bg-muted/30 px-2 py-4">
                    {streamError ? (
                        <p className="self-start text-sm text-red-600">{streamError}</p>
                    ) : !streamUrl ? (
                        <p className="self-start text-sm text-muted-foreground">Preparing stream…</p>
                    ) : (
                        <Document
                            file={streamUrl}
                            onLoadSuccess={onLoadSuccess}
                            onLoadError={(e) => {
                                setStreamError(e?.message ?? "Failed to load PDF.");
                            }}
                            loading={<p className="text-sm text-muted-foreground">Loading PDF…</p>}
                            error={<p className="text-sm text-red-600">Could not render this PDF.</p>}
                        >
                            <Page pageNumber={page} width={pageWidth} renderTextLayer renderAnnotationLayer />
                        </Document>
                    )}
                </div>
                {drawerOpen ? (
                    <aside className="hidden w-72 shrink-0 border-l bg-background p-4 md:block">
                        <h2 className="mb-2 text-sm font-medium">Bookmarks</h2>
                        {bookmarks.length === 0 ? (
                            <p className="text-xs text-muted-foreground">None yet. Click Bookmark on the toolbar.</p>
                        ) : (
                            <ul className="flex flex-col gap-1">
                                {bookmarks.map((b) => (
                                    <li key={b.id} className="flex items-center justify-between gap-2 text-sm">
                                        <button
                                            type="button"
                                            onClick={() => goto(b.page ?? 1)}
                                            className="flex-1 text-left hover:underline"
                                        >
                                            {b.label ?? `Page ${b.page ?? "?"}`}
                                        </button>
                                        <Button size="sm" variant="ghost" onClick={() => removeBookmark(b)}>
                                            ✕
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="mt-4 text-xs text-muted-foreground">Page {pageHeader}</p>
                    </aside>
                ) : null}
            </div>
        </div>
    );
}
