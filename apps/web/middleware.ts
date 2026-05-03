import { NextRequest, NextResponse } from "next/server";

const PROTECTED_PREFIXES = [
    "/dashboard",
    "/settings",
    "/library",
    "/read",
    "/accounts",
    "/sync",
    "/duplicates",
    "/lists",
    "/bookmarks",
    "/notes",
];

export function middleware(req: NextRequest) {
    const { pathname } = req.nextUrl;
    const isProtected = PROTECTED_PREFIXES.some(
        (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
    );

    if (!isProtected) return NextResponse.next();

    const hasHint = req.cookies.get("auth_hint")?.value === "1";
    if (hasHint) return NextResponse.next();

    const url = req.nextUrl.clone();
    url.pathname = "/login";
    url.searchParams.set("next", pathname);
    return NextResponse.redirect(url);
}

export const config = {
    matcher: [
        "/dashboard/:path*",
        "/settings/:path*",
        "/library/:path*",
        "/read/:path*",
        "/accounts/:path*",
        "/sync/:path*",
        "/duplicates/:path*",
        "/lists/:path*",
        "/bookmarks/:path*",
        "/notes/:path*",
    ],
};
