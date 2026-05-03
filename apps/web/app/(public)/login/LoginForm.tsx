"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { AxiosError } from "axios";
import { useAuth } from "@/components/auth-provider";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from "@/components/ui/form";
import { AUTH_MODE } from "@/lib/env";

const schema = z.object({
    email: z.string().email({ message: "Enter a valid email." }),
    password: z.string().min(1, { message: "Password is required." }),
});

type FormValues = z.infer<typeof schema>;

const GOOGLE_ERROR_COPY: Record<string, string> = {
    invalid_state: "Sign-in link expired. Please try again.",
    exchange_failed: "Google rejected the sign-in. Please try again.",
    invalid_userinfo: "We couldn't read your Google profile. Please try again.",
    access_denied: "Sign-in cancelled.",
};

export default function LoginForm() {
    const { login, startRedirect, completeRedirect } = useAuth();
    const searchParams = useSearchParams();
    const [submitting, setSubmitting] = useState(false);
    const exchangedRef = useRef(false);

    const googleMode = AUTH_MODE === "google";

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { email: "", password: "" },
    });

    // Handle redirect-back from Google: ?google_code=… is a single-use code
    // we exchange for a Sanctum token. Runs at most once per page load.
    useEffect(() => {
        if (exchangedRef.current) return;
        const code = searchParams.get("google_code");
        const next = searchParams.get("next") ?? undefined;
        const errorCode = searchParams.get("google_error");

        if (errorCode) {
            toast.error(GOOGLE_ERROR_COPY[errorCode] ?? "Google sign-in failed. Please try again.");
            return;
        }

        if (!code || !completeRedirect) return;
        exchangedRef.current = true;

        completeRedirect(code, next ?? undefined)
            .then(() => toast.success("Signed in."))
            .catch(() => toast.error("This sign-in link has expired. Please try again."));
    }, [searchParams, completeRedirect]);

    async function onSubmit(values: FormValues) {
        setSubmitting(true);
        try {
            await login(values);
            toast.success("Signed in.");
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string }>;
            const message =
                axiosErr.response?.data?.message ?? "Invalid credentials. Please try again.";
            toast.error(message);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="mx-auto flex w-full max-w-md flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader>
                    <CardTitle>Sign in</CardTitle>
                    <CardDescription>
                        {googleMode
                            ? "Use your Google account to continue."
                            : "Enter your credentials to continue."}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {startRedirect ? (
                        <Button
                            type="button"
                            variant={googleMode ? "default" : "outline"}
                            className="w-full"
                            onClick={() => startRedirect(searchParams.get("next") ?? undefined)}
                        >
                            Sign in with Google
                        </Button>
                    ) : null}

                    {!googleMode ? (
                        <>
                            {startRedirect ? (
                                <div className="my-4 flex items-center gap-3 text-xs text-muted-foreground">
                                    <span className="h-px flex-1 bg-border" />
                                    or
                                    <span className="h-px flex-1 bg-border" />
                                </div>
                            ) : null}
                            <Form {...form}>
                                <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4">
                                    <FormField
                                        control={form.control}
                                        name="email"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Email</FormLabel>
                                                <FormControl>
                                                    <Input type="email" autoComplete="email" placeholder="you@example.com" {...field} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="password"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Password</FormLabel>
                                                <FormControl>
                                                    <Input type="password" autoComplete="current-password" {...field} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <Button type="submit" disabled={submitting}>
                                        {submitting ? "Signing in…" : "Sign in"}
                                    </Button>
                                </form>
                            </Form>
                        </>
                    ) : null}
                </CardContent>
                {!googleMode ? (
                    <CardFooter className="flex flex-col items-stretch gap-3 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Don&apos;t have an account?</span>
                            <Link href="/register" className="font-medium hover:underline underline-offset-4">
                                Create one
                            </Link>
                        </div>
                        <Link
                            href="/forgot-password"
                            className="text-muted-foreground hover:text-foreground hover:underline underline-offset-4"
                        >
                            Forgot your password?
                        </Link>
                    </CardFooter>
                ) : null}
            </Card>
        </div>
    );
}
