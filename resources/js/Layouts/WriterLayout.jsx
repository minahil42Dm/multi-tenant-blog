import Dropdown from '@/Components/Dropdown';
import ImpersonationBanner from '@/Components/ImpersonationBanner';
import NavLink from '@/Components/NavLink';
import TenantBrandMark from '@/Components/TenantBrandMark';
import TenantFooter from '@/Components/TenantFooter';
import { Link, usePage } from '@inertiajs/react';

export default function WriterLayout({ header, children }) {
    const { auth, impersonation, branding } = usePage().props;
    const user = auth.user;

    return (
        <div className="flex min-h-screen flex-col bg-gray-100">
            <ImpersonationBanner />
            <nav
                className="border-b border-t-4 border-gray-100 bg-white"
                style={{ borderTopColor: branding.color }}
            >
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex min-w-0">
                            <div className="flex min-w-0 shrink items-center">
                                <Link href={route('writer.dashboard')} className="min-w-0">
                                    <TenantBrandMark branding={branding} showTagline />
                                </Link>
                            </div>

                            <div className="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                                <NavLink
                                    href={route('writer.dashboard')}
                                    active={route().current('writer.dashboard')}
                                >
                                    Dashboard
                                </NavLink>
                                <NavLink
                                    href={route('writer.posts.index')}
                                    active={route().current('writer.posts.*')}
                                >
                                    Posts
                                </NavLink>
                            </div>
                        </div>

                        <div className="hidden sm:ms-6 sm:flex sm:items-center">
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <span className="inline-flex rounded-md">
                                        <button
                                            type="button"
                                            className="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                        >
                                            {user.name}
                                        </button>
                                    </span>
                                </Dropdown.Trigger>

                                <Dropdown.Content>
                                    <Dropdown.Link
                                        href={route(
                                            impersonation
                                                ? 'writer.impersonation.stop'
                                                : 'writer.logout',
                                        )}
                                        method="post"
                                        as="button"
                                    >
                                        {impersonation ? 'Stop impersonating' : 'Log Out'}
                                    </Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>
                    </div>
                </div>
            </nav>

            {header && (
                <header className="bg-white shadow">
                    <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        {header}
                    </div>
                </header>
            )}

            <main className="flex-1">{children}</main>

            <TenantFooter branding={branding} />
        </div>
    );
}
