import ApplicationLogo from '@/Components/ApplicationLogo';
import TenantBrandMark from '@/Components/TenantBrandMark';
import TenantFooter from '@/Components/TenantFooter';
import { Link, usePage } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    const { branding } = usePage().props;

    if (branding) {
        return (
            <div className="flex min-h-screen flex-col bg-gray-100">
                <div className="h-1" style={{ backgroundColor: branding.color }} />
                <div className="flex flex-1 flex-col items-center pt-6 sm:justify-center sm:pt-0">
                    <Link href={route('blog.home')}>
                        <TenantBrandMark
                            branding={branding}
                            style="logo_and_name"
                            logoClassName="h-16 w-16"
                            textClassName="text-2xl"
                        />
                    </Link>

                    {(branding.sidebar_links ?? []).length > 0 && (
                        <nav className="mt-4 flex max-w-md flex-wrap justify-center gap-3 px-6">
                            {(branding.sidebar_links ?? []).map((link) => (
                                <a
                                    key={`${link.order}-${link.url}`}
                                    href={link.url}
                                    target="_blank"
                                    rel="noopener noreferrer nofollow"
                                    className="text-sm font-medium text-gray-600 underline"
                                >
                                    {link.label}
                                </a>
                            ))}
                        </nav>
                    )}

                    <div
                        className="mt-6 w-full overflow-hidden border-t-4 bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg"
                        style={{ borderTopColor: branding.color }}
                    >
                        {children}
                    </div>
                </div>
                <TenantFooter branding={branding} />
            </div>
        );
    }

    return (
        <div className="flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0">
            <div>
                <Link href="/">
                    <ApplicationLogo className="h-20 w-20 fill-current text-gray-500" />
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg">
                {children}
            </div>
        </div>
    );
}
