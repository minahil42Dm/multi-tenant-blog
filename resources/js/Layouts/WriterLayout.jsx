import Dropdown from '@/Components/Dropdown';
import ImpersonationBanner from '@/Components/ImpersonationBanner';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import TenantBrandMark from '@/Components/TenantBrandMark';
import TenantFooter from '@/Components/TenantFooter';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function WriterLayout({ header, children }) {
    const { auth, impersonation, branding } = usePage().props;
    const user = auth.user;
    const can = auth.can ?? {};
    const [showingNavigationDropdown, setShowingNavigationDropdown] = useState(false);

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
                                {can.manageContacts && (
                                    <NavLink
                                        href={route('writer.contacts.index')}
                                        active={route().current('writer.contacts.*')}
                                    >
                                        Contacts
                                    </NavLink>
                                )}
                                {can.manageUsers && (
                                    <NavLink
                                        href={route('writer.users.index')}
                                        active={route().current('writer.users.*')}
                                    >
                                        Users
                                    </NavLink>
                                )}
                                {(branding.sidebar_links ?? []).map((link) => (
                                    <a
                                        key={`${link.order}-${link.url}`}
                                        href={link.url}
                                        target="_blank"
                                        rel="noopener noreferrer nofollow"
                                        className="inline-flex items-center gap-1 border-b-2 border-transparent px-1 pt-1 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700"
                                    >
                                        <span className="text-xs uppercase">{link.icon}</span>
                                        {link.label}
                                    </a>
                                ))}
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

                        <div className="-me-2 flex items-center sm:hidden">
                            <button
                                type="button"
                                aria-label="Open menu"
                                onClick={() => setShowingNavigationDropdown((open) => !open)}
                                className="inline-flex items-center justify-center rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-500 focus:outline-none"
                            >
                                <svg className="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path
                                        className={showingNavigationDropdown ? 'hidden' : 'inline-flex'}
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        className={showingNavigationDropdown ? 'inline-flex' : 'hidden'}
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div className={(showingNavigationDropdown ? 'block' : 'hidden') + ' border-t border-gray-200 sm:hidden'}>
                    <div className="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink href={route('writer.dashboard')} active={route().current('writer.dashboard')}>
                            Dashboard
                        </ResponsiveNavLink>
                        <ResponsiveNavLink href={route('writer.posts.index')} active={route().current('writer.posts.*')}>
                            Posts
                        </ResponsiveNavLink>
                        {can.manageContacts && (
                            <ResponsiveNavLink href={route('writer.contacts.index')} active={route().current('writer.contacts.*')}>
                                Contacts
                            </ResponsiveNavLink>
                        )}
                        {can.manageUsers && (
                            <ResponsiveNavLink href={route('writer.users.index')} active={route().current('writer.users.*')}>
                                Users
                            </ResponsiveNavLink>
                        )}
                        {(branding.sidebar_links ?? []).map((link) => (
                            <a
                                key={`${link.order}-${link.url}`}
                                href={link.url}
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                className="block border-l-4 border-transparent py-2 pe-4 ps-3 text-base font-medium text-gray-600"
                            >
                                {link.label}
                            </a>
                        ))}
                    </div>
                    <div className="border-t border-gray-200 pb-3 pt-4">
                        <div className="px-4">
                            <div className="text-base font-medium text-gray-800">{user.name}</div>
                            <div className="text-sm text-gray-500">{user.email}</div>
                        </div>
                        <div className="mt-3 space-y-1">
                            <ResponsiveNavLink
                                href={route(impersonation ? 'writer.impersonation.stop' : 'writer.logout')}
                                method="post"
                                as="button"
                            >
                                {impersonation ? 'Stop impersonating' : 'Log Out'}
                            </ResponsiveNavLink>
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
