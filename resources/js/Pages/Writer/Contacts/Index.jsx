import DangerButton from '@/Components/DangerButton';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import WriterLayout from '@/Layouts/WriterLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const paginationLabel = (label) =>
    label.replace('&laquo;', '«').replace('&raquo;', '»').replace(/<[^>]*>/g, '');

export default function Index({ contacts, tags, filters, pagination }) {
    const success = usePage().props.flash?.success;
    const canManage = usePage().props.auth?.can?.manageContacts === true;
    const [search, setSearch] = useState(filters.search ?? '');
    const [tag, setTag] = useState(filters.tag ?? '');
    const [renaming, setRenaming] = useState(null);
    const tagForm = useForm({ name: '' });
    const renameForm = useForm({ name: '' });

    const apply = (event) => {
        event.preventDefault();
        router.get(
            route('writer.contacts.index'),
            {
                search,
                tag,
                sort: filters.sort,
                direction: filters.direction,
            },
            { preserveState: true, replace: true },
        );
    };

    const sortBy = (column) => {
        const direction =
            filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';

        router.get(
            route('writer.contacts.index'),
            { search: filters.search, tag: filters.tag, sort: column, direction },
            { preserveState: true, replace: true },
        );
    };

    const destroyContact = (contact) => {
        if (confirm(`Delete ${contact.name}?`)) {
            router.delete(route('writer.contacts.destroy', contact.id));
        }
    };

    const destroyTag = (item) => {
        if (confirm(`Delete the ${item.name} tag?`)) {
            router.delete(route('writer.contact-tags.destroy', item.id));
        }
    };

    const createTag = (event) => {
        event.preventDefault();
        tagForm.post(route('writer.contact-tags.store'), {
            preserveScroll: true,
            onSuccess: () => tagForm.reset('name'),
        });
    };

    const startRename = (item) => {
        setRenaming(item.id);
        renameForm.setData('name', item.name);
        renameForm.clearErrors();
    };

    const renameTag = (event) => {
        event.preventDefault();
        renameForm.put(route('writer.contact-tags.update', renaming), {
            preserveScroll: true,
            onSuccess: () => setRenaming(null),
        });
    };

    return (
        <WriterLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Contacts
                    </h2>
                    {canManage && (
                        <div className="flex gap-2">
                            <Link href={route('writer.contacts.import')}>
                                <PrimaryButton>Import CSV</PrimaryButton>
                            </Link>
                            <Link href={route('writer.contacts.create')}>
                                <PrimaryButton>Add contact</PrimaryButton>
                            </Link>
                        </div>
                    )}
                </div>
            }
        >
            <Head title="Contacts" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    {success && (
                        <div className="rounded-md bg-green-50 p-4 text-sm text-green-700">
                            {success}
                        </div>
                    )}

                    <form onSubmit={apply} className="flex flex-wrap items-end gap-3">
                        <div>
                            <label htmlFor="search" className="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                Search
                            </label>
                            <TextInput
                                id="search"
                                value={search}
                                className="mt-1"
                                placeholder="Name, email, phone, or company"
                                onChange={(event) => setSearch(event.target.value)}
                            />
                        </div>
                        <div>
                            <label htmlFor="tag" className="block text-xs font-medium uppercase tracking-wider text-gray-500">
                                Tag
                            </label>
                            <select
                                id="tag"
                                value={tag}
                                onChange={(event) => setTag(event.target.value)}
                                className="mt-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="">All tags</option>
                                {tags.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <PrimaryButton>Filter</PrimaryButton>
                    </form>

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    {[
                                        ['name', 'Name'],
                                        ['email', 'Email'],
                                        ['company', 'Company'],
                                        ['created_at', 'Added'],
                                    ].map(([column, label]) => (
                                        <th key={column} className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            <button type="button" onClick={() => sortBy(column)} className="hover:text-gray-800">
                                                {label}
                                                {filters.sort === column ? (filters.direction === 'asc' ? ' ↑' : ' ↓') : ''}
                                            </button>
                                        </th>
                                    ))}
                                    <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {contacts.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-6 py-4 text-sm text-gray-500">
                                            {filters.search || filters.tag
                                                ? 'No contacts match these filters.'
                                                : 'No contacts yet.'}
                                        </td>
                                    </tr>
                                )}
                                {contacts.map((contact) => (
                                    <tr key={contact.id}>
                                        <td className="px-6 py-4 text-sm text-gray-900">
                                            <Link href={route('writer.contacts.show', contact.id)} className="underline">
                                                {contact.name}
                                            </Link>
                                            <div className="mt-1 flex flex-wrap gap-1">
                                                {contact.tags.map((item) => (
                                                    <span key={item.id} className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                                                        {item.name}
                                                    </span>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-700">{contact.email || '—'}</td>
                                        <td className="px-6 py-4 text-sm text-gray-700">{contact.company || '—'}</td>
                                        <td className="px-6 py-4 text-sm text-gray-500">{contact.created_at}</td>
                                        <td className="space-x-3 px-6 py-4 text-right text-sm">
                                            {contact.can_update && (
                                                <Link href={route('writer.contacts.edit', contact.id)} className="text-gray-600 underline">
                                                    Edit
                                                </Link>
                                            )}
                                            {contact.can_delete && (
                                                <DangerButton type="button" onClick={() => destroyContact(contact)}>
                                                    Delete
                                                </DangerButton>
                                            )}
                                            {!contact.can_update && (
                                                <span className="text-gray-500">{contact.shared ? 'Shared' : 'Private'}</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {pagination.total > 0 && (
                        <div className="flex flex-wrap items-center justify-between gap-3">
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
                                                        : 'border-gray-300 bg-white text-gray-700'
                                                }`}
                                            >
                                                {paginationLabel(link.label)}
                                            </Link>
                                        ) : (
                                            <span key={index} className="rounded-md border border-gray-200 px-3 py-1 text-sm text-gray-400">
                                                {paginationLabel(link.label)}
                                            </span>
                                        ),
                                    )}
                                </nav>
                            )}
                        </div>
                    )}

                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 className="text-sm font-semibold text-gray-800">Tags</h3>
                        <form onSubmit={createTag} className="mt-3 flex flex-wrap items-start gap-3">
                            <div>
                                <TextInput
                                    value={tagForm.data.name}
                                    placeholder="New tag"
                                    onChange={(event) => tagForm.setData('name', event.target.value)}
                                />
                                {tagForm.errors.name && (
                                    <p className="mt-2 text-sm text-red-600">{tagForm.errors.name}</p>
                                )}
                            </div>
                            <PrimaryButton disabled={tagForm.processing}>Add tag</PrimaryButton>
                        </form>
                        <ul className="mt-4 space-y-2">
                            {tags.length === 0 && (
                                <li className="text-sm text-gray-500">No tags yet.</li>
                            )}
                            {tags.map((item) => (
                                <li key={item.id} className="flex flex-wrap items-center gap-2 text-sm">
                                    {renaming === item.id ? (
                                        <form onSubmit={renameTag} className="flex flex-wrap items-center gap-2">
                                            <TextInput
                                                value={renameForm.data.name}
                                                aria-label={`Rename ${item.name}`}
                                                onChange={(event) => renameForm.setData('name', event.target.value)}
                                            />
                                            <PrimaryButton disabled={renameForm.processing}>Save</PrimaryButton>
                                            <button type="button" className="text-gray-600 underline" onClick={() => setRenaming(null)}>
                                                Cancel
                                            </button>
                                            {renameForm.errors.name && (
                                                <span className="text-red-600">{renameForm.errors.name}</span>
                                            )}
                                        </form>
                                    ) : (
                                        <span className="inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1">
                                            {item.name}
                                            <button type="button" className="text-gray-700 underline" onClick={() => startRename(item)}>
                                                Rename
                                            </button>
                                            <button type="button" className="text-red-700" onClick={() => destroyTag(item)}>
                                                Remove
                                            </button>
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>
        </WriterLayout>
    );
}
