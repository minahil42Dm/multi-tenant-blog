import { router } from '@inertiajs/react';
import { useState } from 'react';

export default function TenantStatusButton({ tenant, redirectTo = null }) {
    const [processing, setProcessing] = useState(false);
    const action = tenant.is_suspended ? 'reactivate' : 'suspend';

    const submit = () => {
        if (
            action === 'suspend' &&
            !window.confirm(
                `Suspend ${tenant.name}? Its site will be unavailable and its writers will be signed out.`,
            )
        ) {
            return;
        }

        router.post(
            route(`admin.tenants.${action}`, tenant.id),
            redirectTo ? { redirect_to: redirectTo } : {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <button
            type="button"
            onClick={submit}
            disabled={processing}
            className={`rounded-md px-3 py-1 text-xs font-semibold uppercase tracking-widest text-white disabled:opacity-50 ${
                tenant.is_suspended
                    ? 'bg-green-600 hover:bg-green-500'
                    : 'bg-red-600 hover:bg-red-500'
            }`}
        >
            {tenant.is_suspended ? 'Reactivate' : 'Suspend'}
        </button>
    );
}
