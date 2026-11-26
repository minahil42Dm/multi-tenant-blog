import DangerButton from '@/Components/DangerButton';
import FlashMessages from '@/Components/FlashMessages';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

function BrandingSummary({ tenant }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-4 bg-white p-6 shadow-sm sm:rounded-lg">
            <div className="flex items-center gap-3">
                {tenant.logo_url && (
                    <img src={tenant.logo_url} alt="" className="h-10 w-10 rounded object-contain" />
                )}
                <div>
                    <h3 className="text-lg font-medium text-gray-900">{tenant.name}</h3>
                    <p className="text-sm text-gray-600">
                        Name, logo, favicon, colors, navigation and footer are managed on the branding page.
                    </p>
                </div>
            </div>
            <Link
                href={route('admin.tenants.branding.edit', tenant.id)}
                className="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
            >
                Edit branding
            </Link>
        </div>
    );
}

function DomainsSection({ tenant }) {
    const [busyDomain, setBusyDomain] = useState(null);
    const [removing, setRemoving] = useState(null);
    const { data, setData, post, processing, errors, reset } = useForm({ domain: '' });
    const onlyDomain = tenant.domains.length <= 1;

    const addDomain = (event) => {
        event.preventDefault();
        post(route('admin.tenants.domains.store', tenant.id), {
            preserveScroll: true,
            onSuccess: () => reset('domain'),
        });
    };

    const makePrimary = (domain) => {
        router.post(
            route('admin.tenants.domains.primary', [tenant.id, domain.id]),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusyDomain(domain.id),
                onFinish: () => setBusyDomain(null),
            },
        );
    };

    const removeDomain = () => {
        const domain = removing;
        router.delete(route('admin.tenants.domains.destroy', [tenant.id, domain.id]), {
            preserveScroll: true,
            onStart: () => setBusyDomain(domain.id),
            onFinish: () => {
                setBusyDomain(null);
                setRemoving(null);
            },
        });
    };

    return (
        <div className="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg">
            <div>
                <h3 className="text-lg font-medium text-gray-900">Domains</h3>
                <p className="mt-1 text-sm text-gray-600">
                    The primary domain is used for links, the writer login and
                    impersonation. A tenant always keeps at least one domain.
                </p>
            </div>

            <ul className="divide-y divide-gray-200 rounded-md border border-gray-200">
                {tenant.domains.map((domain) => (
                    <li
                        key={domain.id}
                        className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    >
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-sm">{domain.domain}</span>
                            {domain.is_primary && (
                                <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800">
                                    Primary
                                </span>
                            )}
                        </div>
                        {!domain.is_primary && (
                            <div className="flex items-center gap-3">
                                <button
                                    type="button"
                                    disabled={busyDomain !== null}
                                    onClick={() => makePrimary(domain)}
                                    className="text-sm text-indigo-600 underline hover:text-indigo-800 disabled:opacity-50"
                                >
                                    Make primary
                                </button>
                                <button
                                    type="button"
                                    disabled={busyDomain !== null || onlyDomain}
                                    onClick={() => setRemoving(domain)}
                                    className="text-sm text-red-600 underline hover:text-red-800 disabled:opacity-50"
                                >
                                    Remove
                                </button>
                            </div>
                        )}
                    </li>
                ))}
            </ul>

            <form onSubmit={addDomain} className="flex flex-wrap items-start gap-3">
                <div className="min-w-64 flex-1">
                    <InputLabel htmlFor="domain" value="Add a domain" />
                    <TextInput
                        id="domain"
                        className="mt-1 block w-full"
                        value={data.domain}
                        placeholder="acme.platform.test or blog.acme.com"
                        maxLength={253}
                        required
                        onChange={(event) => setData('domain', event.target.value)}
                    />
                    <InputError message={errors.domain} className="mt-2" />
                </div>
                <PrimaryButton className="mt-6" disabled={processing}>
                    {processing ? 'Adding…' : 'Add domain'}
                </PrimaryButton>
            </form>
            <p className="text-xs text-gray-500">
                Custom domains need a DNS record pointing at the platform and a TLS
                certificate before they will load.
            </p>

            <Modal show={removing !== null} onClose={() => setRemoving(null)} maxWidth="md">
                <div className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">Remove domain?</h2>
                    <p className="mt-1 text-sm text-gray-600">
                        <span className="font-mono">{removing?.domain}</span> will stop
                        serving {tenant.name} immediately.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton
                            onClick={() => setRemoving(null)}
                            disabled={busyDomain !== null}
                        >
                            Cancel
                        </SecondaryButton>
                        <DangerButton
                            className="ms-3"
                            onClick={removeDomain}
                            disabled={busyDomain !== null}
                        >
                            {busyDomain !== null ? 'Removing…' : 'Remove domain'}
                        </DangerButton>
                    </div>
                </div>
            </Modal>
        </div>
    );
}

export default function Edit({ tenant }) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Edit {tenant.name}
                </h2>
            }
        >
            <Head title={`Edit ${tenant.name}`} />

            <div className="py-12">
                <div className="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                    <FlashMessages />
                    <BrandingSummary tenant={tenant} />
                    <DomainsSection tenant={tenant} />
                    <Link
                        href={route('admin.tenants.show', tenant.id)}
                        className="text-sm text-gray-600 underline hover:text-gray-900"
                    >
                        Back to tenant
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
