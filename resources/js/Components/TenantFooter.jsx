export default function TenantFooter({ branding, className = '' }) {
    const links = branding.footer_links ?? [];

    return (
        <footer
            className={`border-t-4 bg-white ${className}`}
            style={{ borderTopColor: branding.accent_color }}
        >
            <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-6 text-sm text-gray-600 sm:px-6 lg:px-8">
                <p>
                    © {new Date().getFullYear()} {branding.name}
                </p>
                {(links.length > 0 || branding.support_email) && (
                    <nav className="flex flex-wrap items-center gap-x-5 gap-y-2" aria-label="Footer">
                        {links.map((link) => (
                            <a
                                key={`${link.label}-${link.url}`}
                                href={link.url}
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                className="underline-offset-2 hover:underline"
                                style={{ color: branding.color }}
                            >
                                {link.label}
                            </a>
                        ))}
                        {branding.support_email && (
                            <a
                                href={`mailto:${branding.support_email}`}
                                className="underline-offset-2 hover:underline"
                                style={{ color: branding.color }}
                            >
                                {branding.support_email}
                            </a>
                        )}
                    </nav>
                )}
            </div>
        </footer>
    );
}
