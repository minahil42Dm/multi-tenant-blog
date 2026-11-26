import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function ImpersonationBanner() {
    const { impersonation } = usePage().props;
    const [stopping, setStopping] = useState(false);

    if (!impersonation) {
        return null;
    }

    const stop = () =>
        router.post(
            route('writer.impersonation.stop'),
            {},
            { onStart: () => setStopping(true), onFinish: () => setStopping(false) },
        );

    return (
        <div role="status" className="bg-amber-500 text-amber-950">
            <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-2 text-sm sm:px-6 lg:px-8">
                <p>
                    You are signed in as{' '}
                    <strong>
                        {impersonation.writer_name} ({impersonation.writer_email})
                    </strong>
                    . Impersonated by {impersonation.impersonator_email}. Email and
                    password changes are disabled.
                </p>
                <button
                    type="button"
                    onClick={stop}
                    disabled={stopping}
                    className="rounded-md bg-amber-950 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-white hover:bg-amber-900 disabled:opacity-50"
                >
                    {stopping ? 'Stopping…' : 'Stop impersonating'}
                </button>
            </div>
        </div>
    );
}
