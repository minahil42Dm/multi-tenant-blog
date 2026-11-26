import ImpersonationBanner from '@/Components/ImpersonationBanner';
import TenantBrandMark from '@/Components/TenantBrandMark';
import TenantFooter from '@/Components/TenantFooter';
import { Link, usePage } from '@inertiajs/react';

export default function BlogLayout({ branding: pageBranding, children }) {
    const sharedBranding = usePage().props.branding;
    const branding = pageBranding ?? sharedBranding;
    const color = branding.color;

    return (
        <div className="flex min-h-screen flex-col bg-gray-100">
            <ImpersonationBanner />
            <div className="h-1" style={{ backgroundColor: color }} />
            <header className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-5 sm:px-6">
                    <Link href={route('blog.home')} className="min-w-0">
                        <TenantBrandMark
                            branding={branding}
                            style="logo_and_name"
                            logoClassName="h-10 w-10"
                        />
                    </Link>
                    <Link
                        href={route('writer.login')}
                        className="shrink-0 text-sm font-medium underline"
                        style={{ color: branding.accent_color ?? color }}
                    >
                        Writer sign in
                    </Link>
                </div>
            </header>

            <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-8 sm:px-6">{children}</main>

            <TenantFooter branding={branding} />
        </div>
    );
}
