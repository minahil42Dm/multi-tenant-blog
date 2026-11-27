import DangerButton from '@/Components/DangerButton';
import FlashMessages from '@/Components/FlashMessages';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import TenantDomainList from '@/Components/TenantDomainList';
import TenantStatusBadge from '@/Components/TenantStatusBadge';
import TenantStatusButton from '@/Components/TenantStatusButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

function Detail({ label, children }) {
    return (
        <div className="py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt className="text-sm font-medium text-gray-500">{label}</dt>
            <dd className="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">
                {children}
            </dd>
        </div>
    );
}

function DeleteTenant({ tenant, confirmation }) {
    const [confirming, setConfirming] = useState(false);
    const { data, setData, delete: destroy, processing, errors, reset, clearErrors } =
        useForm({ confirm_domain: '' });

    const close = () => {
        setConfirming(false);
        reset();
        clearErrors();
    };

    const submit = (event) => {
        event.preventDefault();
        destroy(route('admin.tenants.destroy', tenant.id), { preserveScroll: true });
    };

    return (
        <div className="mt-6 rounded-lg border border-red-200 bg-white p-6 shadow-sm">
            <h3 className="text-lg font-medium text-red-700">Delete tenant</h3>
            <p className="mt-1 text-sm text-gray-600">
                Permanently deletes {tenant.name}, its database, users, posts,
                domains and uploaded files. This cannot be undone.
            </p>
            {!tenant.is_suspended && (
                <p className="mt-2 text-sm text-amber-700">
                    Suspend the tenant before deleting it.
                </p>
            )}
            <DangerButton
                className="mt-4"
                disabled={!tenant.is_suspended}
                onClick={() => setConfirming(true)}
            >
                Delete tenant
            </DangerButton>

            <Modal show={confirming} onClose={close}>
                <form onSubmit={submit} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Delete {tenant.name}?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        Tenant ID <span className="font-mono">{tenant.id}</span>.
                        Its database and all of its content will be permanently
                        deleted. Type{' '}
                        <span className="font-mono font-semibold">{confirmation}</span>{' '}
                        to confirm.
                    </p>

                    <div className="mt-6">
                        <InputLabel htmlFor="confirm_domain" value="Confirmation" />
                        <TextInput
                            id="confirm_domain"
                            className="mt-1 block w-full"
                            value={data.confirm_domain}
                            autoComplete="off"
                            isFocused
                            onChange={(event) =>
                                setData('confirm_domain', event.target.value)
                            }
                        />
                        <InputError message={errors.confirm_domain} className="mt-2" />
                    </div>

                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={close} disabled={processing}>
                            Cancel
                        </SecondaryButton>
                        <DangerButton
                            className="ms-3"
                            disabled={
                                processing ||
                                data.confirm_domain.trim().toLowerCase() !== confirmation
                            }
                        >
                            {processing ? 'Deleting…' : 'Permanently delete'}
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </div>
    );
}

export default function Show({ tenant, deleteConfirmation }) {
    const can = usePage().props.auth.can ?? {};

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        {tenant.name}
                    </h2>
                    <div className="flex items-center gap-3">
                        <Link
                            href={route('admin.tenants.branding.edit', tenant.id)}
                            className="rounded-md border border-gray-300 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50"
                        >
                            Branding
                        </Link>
                        {can.manageTenants && (
                            <>
                                <Link
                                    href={route('admin.tenants.edit', tenant.id)}
                                    className="rounded-md border border-gray-300 bg-white px-3 py-1 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50"
                                >
                                    Edit domains
                                </Link>
                                <TenantStatusButton tenant={tenant} redirectTo="show" />
                            </>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={tenant.name} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <FlashMessages />

                    <div className="overflow-hidden bg-white px-6 shadow-sm sm:rounded-lg">
                        <dl className="divide-y divide-gray-200">
                            <Detail label="Name">{tenant.name}</Detail>
                            <Detail label="Tenant ID">
                                <span className="font-mono">{tenant.id}</span>
                            </Detail>
                            <Detail label="Status">
                                <TenantStatusBadge tenant={tenant} />
                            </Detail>
                            <Detail label="Domains">
                                <TenantDomainList domains={tenant.domains} />
                            </Detail>
                            <Detail label="Created">{tenant.created_at}</Detail>
                            <Detail label="Brand colors">
                                <span className="inline-flex flex-wrap items-center gap-4">
                                    {[
                                        ['Primary', tenant.brand_color],
                                        ['Accent', tenant.accent_color],
                                    ].map(([label, color]) =>
                                        color ? (
                                            <span key={label} className="inline-flex items-center gap-2">
                                                <span
                                                    className="inline-block h-5 w-5 rounded border border-gray-300"
                                                    style={{ backgroundColor: color }}
                                                />
                                                <span className="font-mono">{color}</span>
                                                <span className="text-gray-500">{label}</span>
                                            </span>
                                        ) : (
                                            <span key={label}>{label}: not set</span>
                                        ),
                                    )}
                                </span>
                            </Detail>
                            <Detail label="Logo">
                                {tenant.logo_url ? (
                                    <img
                                        src={tenant.logo_url}
                                        alt={`${tenant.name} logo`}
                                        className="h-16 w-auto rounded"
                                    />
                                ) : (
                                    'No logo'
                                )}
                            </Detail>
                            <Detail label="Posts">{tenant.post_count}</Detail>
                            <Detail label="Users">
                                <div className="flex items-center gap-4">
                                    <span>{tenant.writer_count}</span>
                                    {can.viewTenantUsers && (
                                        <Link
                                            href={route(
                                                'admin.tenants.writers.index',
                                                tenant.id,
                                            )}
                                            className="text-indigo-600 underline hover:text-indigo-800"
                                        >
                                            View users
                                        </Link>
                                    )}
                                    {can.manageTenantUsers &&
                                        !tenant.is_suspended && (
                                            <Link
                                                href={route(
                                                    'admin.tenants.writers.create',
                                                    tenant.id,
                                                )}
                                                className="text-indigo-600 underline hover:text-indigo-800"
                                            >
                                                Add user
                                            </Link>
                                        )}
                                </div>
                            </Detail>
                        </dl>
                    </div>

                    {can.manageTenants && deleteConfirmation && (
                        <DeleteTenant tenant={tenant} confirmation={deleteConfirmation} />
                    )}

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
