export default function TenantStatusBadge({ tenant }) {
    return (
        <>
            <span
                className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${
                    tenant.is_suspended
                        ? 'bg-red-100 text-red-800'
                        : 'bg-green-100 text-green-800'
                }`}
            >
                {tenant.status_label}
            </span>
            {tenant.suspended_at && (
                <p className="mt-1 text-xs text-gray-500">
                    Since {tenant.suspended_at}
                </p>
            )}
        </>
    );
}
