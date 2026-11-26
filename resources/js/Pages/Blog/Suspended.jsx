import { Head } from '@inertiajs/react';

export default function Suspended({ tenantName }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 px-6">
            <Head title="Site suspended" />

            <div className="w-full max-w-md rounded-lg bg-white p-8 text-center shadow-sm">
                <h1 className="text-2xl font-semibold text-gray-900">
                    This site is suspended
                </h1>
                <p className="mt-4 text-sm text-gray-600">
                    {tenantName
                        ? `${tenantName} is temporarily unavailable.`
                        : 'This blog is temporarily unavailable.'}{' '}
                    Please check back later.
                </p>
            </div>
        </div>
    );
}
