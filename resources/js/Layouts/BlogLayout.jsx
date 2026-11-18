import { Link } from '@inertiajs/react';

export default function BlogLayout({ branding, children }) {
    const color = branding.color;

    return (
        <div className="min-h-screen bg-gray-100">
            <div className="h-1" style={{ backgroundColor: color }} />
            <header className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-5 sm:px-6">
                    <Link
                        href={route('blog.home')}
                        className="flex min-w-0 items-center gap-3 text-lg font-semibold"
                        style={{ color }}
                    >
                        {branding.logo_url && (
                            <img
                                src={branding.logo_url}
                                alt=""
                                className="h-10 w-10 shrink-0 rounded object-contain"
                            />
                        )}
                        <span className="truncate">{branding.name}</span>
                    </Link>
                    <Link
                        href={route('writer.login')}
                        className="shrink-0 text-sm font-medium underline"
                        style={{ color }}
                    >
                        Writer sign in
                    </Link>
                </div>
            </header>

            <main className="mx-auto max-w-3xl px-4 py-8 sm:px-6">{children}</main>
        </div>
    );
}
