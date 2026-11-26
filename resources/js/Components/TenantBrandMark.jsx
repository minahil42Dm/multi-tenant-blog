export default function TenantBrandMark({
    branding,
    style = branding.sidebar_style,
    logoClassName = 'h-9 w-9',
    textClassName = 'text-lg',
    showTagline = false,
}) {
    const showLogo = branding.logo_url && style !== 'name';
    const showName = !branding.logo_url || style !== 'logo';

    return (
        <span className="flex min-w-0 items-center gap-3">
            {showLogo && (
                <img
                    src={branding.logo_url}
                    alt={showName ? '' : branding.name}
                    className={`${logoClassName} shrink-0 rounded object-contain`}
                />
            )}
            {showName && (
                <span className="min-w-0">
                    <span
                        className={`block truncate font-semibold ${textClassName}`}
                        style={{ color: branding.color }}
                    >
                        {branding.name}
                    </span>
                    {showTagline && branding.sidebar_tagline && (
                        <span className="block truncate text-xs text-gray-500">
                            {branding.sidebar_tagline}
                        </span>
                    )}
                </span>
            )}
        </span>
    );
}
