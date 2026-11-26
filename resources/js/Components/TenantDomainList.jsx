export default function TenantDomainList({ domains }) {
    if (!domains || domains.length === 0) {
        return <span className="text-gray-500">No domains</span>;
    }

    return (
        <ul className="space-y-1">
            {domains.map((domain) => (
                <li key={domain.id} className="flex items-center gap-2">
                    <span className="font-mono text-sm">{domain.domain}</span>
                    {domain.is_primary && (
                        <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800">
                            Primary
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}
