import FlashMessages from '@/Components/FlashMessages';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

function ImpersonateButton({ tenant, writer, csrfToken }) {
    const [submitting, setSubmitting] = useState(false);

    const submit = (event) => {
        if (
            !window.confirm(
                `Sign in to ${tenant.name} as ${writer.email}? The session is recorded in the audit log.`,
            )
        ) {
            event.preventDefault();

            return;
        }

        setSubmitting(true);
    };

    return (
        <form
            method="post"
            action={route('admin.tenants.writers.impersonate', [tenant.id, writer.id])}
            onSubmit={submit}
        >
            <input type="hidden" name="_token" value={csrfToken} />
            <button
                type="submit"
                disabled={submitting}
                className="rounded-md border border-gray-300 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50 disabled:opacity-50"
            >
                {submitting ? 'Opening…' : 'Impersonate'}
            </button>
        </form>
    );
}

export default function Index({ tenant, writers, csrfToken }) {
    const can = usePage().props.auth.can ?? {};
    const showActions = writers.some((writer) => writer.can_impersonate);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Users for {tenant.name}
                    </h2>
                    {can.manageTenantUsers && !tenant.is_suspended && (
                        <Link
                            href={route('admin.tenants.writers.create', tenant.id)}
                            className="inline-flex rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
                        >
                            Add writer
                        </Link>
                    )}
                </div>
            }
        >
            <Head title={`Users for ${tenant.name}`} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <FlashMessages />

                    <p className="mb-4 text-sm text-gray-600">
                        {tenant.domain ? (
                            <>
                                Users sign in at{' '}
                                <span className="font-medium text-gray-900">
                                    {tenant.domain}/login
                                </span>
                                .
                            </>
                        ) : (
                            'This tenant has no domain, so its users cannot sign in.'
                        )}
                    </p>

                    {tenant.is_suspended && can.impersonateTenantUsers && (
                        <p className="mb-4 text-sm text-amber-700">
                            Impersonation is unavailable while the tenant is suspended.
                        </p>
                    )}

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    {['Name', 'Email', 'Role', 'Added'].map((heading) => (
                                        <th
                                            key={heading}
                                            className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"
                                        >
                                            {heading}
                                        </th>
                                    ))}
                                    {showActions && (
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Actions
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {writers.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={showActions ? 5 : 4}
                                            className="px-6 py-4 text-sm text-gray-500"
                                        >
                                            No writers yet.
                                        </td>
                                    </tr>
                                )}
                                {writers.map((writer) => (
                                    <tr key={writer.id}>
                                        <td className="px-6 py-4 text-sm text-gray-900">
                                            {writer.name}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-900">
                                            {writer.email}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-700">
                                            {writer.role_label}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-500">
                                            {writer.created_at}
                                        </td>
                                        {showActions && (
                                            <td className="px-6 py-4 text-sm">
                                                {writer.can_impersonate && (
                                                    <ImpersonateButton
                                                        tenant={tenant}
                                                        writer={writer}
                                                        csrfToken={csrfToken}
                                                    />
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-6">
                        <Link
                            href={route('admin.tenants.index')}
                            className="text-sm text-gray-600 underline hover:text-gray-900"
                        >
                            Back to tenants
                        </Link>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
