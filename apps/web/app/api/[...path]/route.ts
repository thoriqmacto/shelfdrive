import type { NextRequest } from "next/server";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

function resolveProxyTarget(): string {
    if (process.env.API_PROXY_TARGET) return process.env.API_PROXY_TARGET;
    if (process.env.NEXT_PUBLIC_API_BASE_URL) {
        try {
            const u = new URL(process.env.NEXT_PUBLIC_API_BASE_URL);
            return `${u.protocol}//${u.host}`;
        } catch {
            /* fall through */
        }
    }
    return "http://localhost:8000";
}

function buildTargetUrl(req: NextRequest, path: string[]) {
    const search = req.nextUrl.search;
    const joined = path.join("/");
    return `${resolveProxyTarget()}/api/${joined}${search}`;
}

function forwardHeaders(req: NextRequest) {
    const headers = new Headers(req.headers);
    ["host", "connection", "content-length", "accept-encoding", "x-forwarded-proto", "x-forwarded-host"].forEach(
        (h) => headers.delete(h),
    );
    return headers;
}

async function proxy(method: string, req: NextRequest, params: { path: string[] }) {
    const target = buildTargetUrl(req, params.path);
    const headers = forwardHeaders(req);
    const hasBody = method !== "GET" && method !== "HEAD";
    const body = hasBody ? await req.blob() : undefined;

    const resp = await fetch(target, {
        method,
        headers,
        body,
        redirect: "manual",
        cache: "no-store",
    });

    const outHeaders = new Headers(resp.headers);
    outHeaders.delete("content-encoding");
    outHeaders.delete("transfer-encoding");

    return new Response(resp.body, {
        status: resp.status,
        statusText: resp.statusText,
        headers: outHeaders,
    });
}

type RouteContext = { params: Promise<{ path: string[] }> };

async function handle(method: string, req: NextRequest, ctx: RouteContext) {
    const params = await ctx.params;
    return proxy(method, req, params);
}

export const GET = (req: NextRequest, ctx: RouteContext) => handle("GET", req, ctx);
export const POST = (req: NextRequest, ctx: RouteContext) => handle("POST", req, ctx);
export const PUT = (req: NextRequest, ctx: RouteContext) => handle("PUT", req, ctx);
export const PATCH = (req: NextRequest, ctx: RouteContext) => handle("PATCH", req, ctx);
export const DELETE = (req: NextRequest, ctx: RouteContext) => handle("DELETE", req, ctx);
export const OPTIONS = (req: NextRequest, ctx: RouteContext) => handle("OPTIONS", req, ctx);
