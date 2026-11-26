import FlashMessages from '@/Components/FlashMessages';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TenantDomainList from '@/Components/TenantDomainList';
import TenantStatusBadge from '@/Components/TenantStatusBadge';
import TenantStatusButton from '@/Components/TenantStatusButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const paginationLabel = (label) =>
    label.replace('&laquo;', '«').replace('&raquo;', '»');

export default function Index({
    tenants,
    pagination,
    filters,
    statuses,
    totalTenants,
}) {
    const can = usePage().props.auth.can ?? {};
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);
    const [loading, setLoading] = useState(false);
    const hasFilters = filters.search !== '' || filters.status !== '';

    const applyFilters = (values) => {
        const query = Object.fromEntries(
            Object.entries(values).filter(([, value]) => value !== ''),
        );

        router.get(route('admin.tenants.index'), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    };

    const submit = (event) => {
        event.preventDefault();
        applyFilters({ search, status });
    };

    const clearFilters = () => {
        setSearch('');
        setStatus('');
        applyFilters({});
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Tenants
                    </h2>
                    {can.manageTenants && (
                        <Link
                            href={route('admin.tenants.create')}
                            className="inline-flex rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
                        >
                            Create tenant
                        </Link>
                    )}
                </div>
            }
        >
            <Head title="Tenants" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <FlashMessages />

                    <form
                        onSubmit={submit}
                        className="mb-4 flex flex-wrap items-end gap-3 bg-white p-4 shadow-sm sm:rounded-lg"
                    >
                        <div className="min-w-64 flex-1">
                            <label
                                htmlFor="search"
                                className="block text-sm font-medium text-gray-700"
                            >
                                Search
                            </label>
                            <TextInput
                                id="search"
                                type="search"
                                className="mt-1 block w-full"
                                value={search}
                                maxLength={100}
                                placeholder="Tenant name or domain"
                                onChange={(event) => setSearch(event.target.value)}
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="status"
                                className="block text-sm font-medium text-gray-700"
                            >
                                Status
                            </label>
                            <select
                                id="status"
                                className="mt-1 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                value={status}
                                onChange={(event) => {
                                    setStatus(event.target.value);
                                    applyFilters({ search, status: event.target.value });
                                }}
                            >
                                <option value="">All statuses</option>
                                {statuses.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <PrimaryButton disabled={loading}>
                            {loading ? 'Searching…' : 'Search'}
                        </PrimaryButton>
                        {hasFilters && (
                            <SecondaryButton onClick={clearFilters} disabled={loading}>
                                Clear
                            </SecondaryButton>
                        )}
                    </form>

                    <div
                        className={`overflow-x-auto bg-white shadow-sm sm:rounded-lg ${loading ? 'opacity-60' : ''}`}
                    >
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    {['Name', 'Domains', 'Status', 'Users', 'Posts', 'Created'].map(
                                        (heading) => (
                                            <th
                                                key={heading}
                                                className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"
                                            >
                                                {heading}
                                            </th>
                                        ),
                                    )}
                                    <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {tenants.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-6 py-10 text-center text-sm text-gray-500">
                                            {totalTenants === 0 ? (
                                                <>
                                                    No tenants yet.
                                                    {can.manageTenants && (
                                                        <>
                                                            {' '}
                                                            <Link
                                                                href={route('admin.tenants.create')}
                                                                className="text-indigo-600 underline"
                                                            >
                                                                Create the first tenant
                                                            </Link>
                                                            .
                                                        </>
                                                    )}
                                                </>
                                            ) : (
                                                <>
                                                    No tenants match your search or filter.{' '}
                                                    <button
                                                        type="button"
                                                        onClick={clearFilters}
                                                        className="text-indigo-600 underline"
                                                    >
                                                        Clear filters
                                                    </button>
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                )}
                                {tenants.map((tenant) => (
                                    <tr key={tenant.id}>
                                        <td className="px-6 py-4 text-sm">
                                            <Link
                                                href={route('admin.tenants.show', tenant.id)}
                                                className="font-medium text-indigo-600 underline hover:text-indigo-800"
                                            >
                                                {tenant.name}
                                            </Link>
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-900">
                                            <TenantDomainList domains={tenant.domains} />
                                        </td>
                                        <td className="px-6 py-4 text-sm">
                                            <TenantStatusBadge tenant={tenant} />
                                        </td>
                                        <td className="px-6 py-4 text-sm">
                                            <div className="flex flex-col gap-1">
                                                <span className="text-gray-900">
                                                    {tenant.writer_count}
                                                </span>
                                                {can.viewTenantUsers && (
                                                    <Link
                                                        href={route('admin.tenants.writers.index', tenant.id)}
                                                        className="text-indigo-600 underline hover:text-indigo-800"
                                                    >
                                                        View users
                                                    </Link>
                                                )}
                                                {can.manageTenantUsers && !tenant.is_suspended && (
                                                    <Link
                                                        href={route('admin.tenants.writers.create', tenant.id)}
                                                        className="text-indigo-600 underline hover:text-indigo-800"
                                                    >
                                                        Add writer
                                                    </Link>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-900">
                                            {tenant.post_count}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-500">
                                            {tenant.created_at}
                                        </td>
                                        <td className="px-6 py-4 text-sm">
                                            <div className="flex flex-col items-start gap-2">
                                                <Link
                                                    href={route('admin.tenants.show', tenant.id)}
                                                    className="text-indigo-600 underline hover:text-indigo-800"
                                                >
                                                    View details
                                                </Link>
                                                {can.manageTenants && (
                                                    <>
                                                        <Link
                                                            href={route('admin.tenants.edit', tenant.id)}
                                                            className="text-indigo-600 underline hover:text-indigo-800"
                                                        >
                                                            Edit
                                                        </Link>
                                                        <TenantStatusButton tenant={tenant} />
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {pagination.total > 0 && (
                        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm text-gray-600">
                                Showing {pagination.from}–{pagination.to} of {pagination.total}
                            </p>
                            {pagination.last_page > 1 && (
                                <nav className="flex flex-wrap gap-1" aria-label="Pagination">
                                    {pagination.links.map((link, index) =>
                                        link.url ? (
                                            <Link
                                                key={index}
                                                href={link.url}
                                                preserveScroll
                                                className={`rounded-md border px-3 py-1 text-sm ${
                                                    link.active
                                                        ? 'border-gray-800 bg-gray-800 text-white'
                                                        : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                                                }`}
                                            >
                                                {paginationLabel(link.label)}
                                            </Link>
                                        ) : (
                                            <span
                                                key={index}
                                                className="rounded-md border border-gray-200 px-3 py-1 text-sm text-gray-400"
                                            >
                                                {paginationLabel(link.label)}
                                            </span>
                                        ),
                                    )}
                                </nav>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
