import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import WriterLayout from '@/Layouts/WriterLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Show({ contact, notes, shareUsers = [] }) {
    const success = usePage().props.flash?.success;
    const noteForm = useForm({ body: '' });
    const editForm = useForm({ body: '' });
    const shareForm = useForm({
        users: shareUsers.filter((user) => user.shared).map((user) => user.id),
    });
    const [editing, setEditing] = useState(null);

    const addNote = (event) => {
        event.preventDefault();
        noteForm.post(route('writer.contacts.notes.store', contact.id), {
            preserveScroll: true,
            onSuccess: () => noteForm.reset('body'),
        });
    };

    const saveNote = (event) => {
        event.preventDefault();
        editForm.put(route('writer.contacts.notes.update', [contact.id, editing]), {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    };

    const destroyContact = () => {
        if (confirm(`Delete ${contact.name}?`)) {
            router.delete(route('writer.contacts.destroy', contact.id));
        }
    };

    const toggleShare = (id) => {
        const current = shareForm.data.users;
        shareForm.setData(
            'users',
            current.includes(id) ? current.filter((userId) => userId !== id) : [...current, id],
        );
    };

    const saveShares = (event) => {
        event.preventDefault();
        shareForm.put(route('writer.contacts.shares.update', contact.id), {
            preserveScroll: true,
        });
    };

    const destroyNote = (note) => {
        if (confirm('Delete this note?')) {
            router.delete(route('writer.contacts.notes.destroy', [contact.id, note.id]), {
                preserveScroll: true,
            });
        }
    };

    const details = [
        ['Email', contact.email],
        ['Phone', contact.phone],
        ['Company', contact.company],
        ['Job title', contact.job_title],
        ['Website', contact.website],
        ['Location', contact.location],
    ];

    return (
        <WriterLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        {contact.name}
                    </h2>
                    <div className="flex gap-2">
                        {contact.can_update && (
                            <Link href={route('writer.contacts.edit', contact.id)}>
                                <PrimaryButton>Edit</PrimaryButton>
                            </Link>
                        )}
                        {contact.can_delete && (
                            <DangerButton type="button" onClick={destroyContact}>
                                Delete
                            </DangerButton>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={contact.name} />

            <div className="py-12">
                <div className="mx-auto grid max-w-7xl gap-6 lg:grid-cols-3 sm:px-6 lg:px-8">
                    <div className="space-y-6 lg:col-span-1">
                        {success && (
                            <div className="rounded-md bg-green-50 p-4 text-sm text-green-700">
                                {success}
                            </div>
                        )}
                        <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                            <dl className="space-y-3 text-sm">
                                <div>
                                    <dt className="text-xs uppercase tracking-wider text-gray-500">Owner</dt>
                                    <dd className="text-gray-900">
                                        {contact.owner}
                                        {contact.shared ? ' · Shared' : ' · Private'}
                                    </dd>
                                </div>
                                {details.map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-xs uppercase tracking-wider text-gray-500">{label}</dt>
                                        <dd className="text-gray-900">{value || '—'}</dd>
                                    </div>
                                ))}
                            </dl>
                            <div className="mt-4 flex flex-wrap gap-2">
                                {contact.tags.map((tag) => (
                                    <span key={tag.id} className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                                        {tag.name}
                                    </span>
                                ))}
                            </div>
                            <Link href={route('writer.contacts.index')} className="mt-6 inline-block text-sm text-gray-600 underline">
                                Back to contacts
                            </Link>
                        </div>

                        {contact.can_share && (
                            <form onSubmit={saveShares} className="bg-white p-6 shadow-sm sm:rounded-lg">
                                <h3 className="text-sm font-semibold text-gray-800">Sharing</h3>
                                <p className="mt-1 text-sm text-gray-600">
                                    This contact stays private until you share it. Shared users can view it and add notes. Only the owner and a tenant admin can edit, delete, or change sharing.
                                </p>
                                {shareUsers.length === 0 && (
                                    <p className="mt-3 text-sm text-gray-500">There are no other users to share with.</p>
                                )}
                                <ul className="mt-3 space-y-2">
                                    {shareUsers.map((user) => (
                                        <li key={user.id}>
                                            <label className="flex items-center gap-2 text-sm text-gray-800">
                                                <input
                                                    type="checkbox"
                                                    checked={shareForm.data.users.includes(user.id)}
                                                    onChange={() => toggleShare(user.id)}
                                                />
                                                {user.name}
                                                <span className="text-gray-500">{user.email}</span>
                                            </label>
                                        </li>
                                    ))}
                                </ul>
                                {shareForm.errors.users && (
                                    <p className="mt-2 text-sm text-red-600">{shareForm.errors.users}</p>
                                )}
                                <PrimaryButton className="mt-4" disabled={shareForm.processing}>
                                    Save sharing
                                </PrimaryButton>
                            </form>
                        )}
                    </div>

                    <div className="bg-white p-6 shadow-sm sm:rounded-lg lg:col-span-2">
                        <h3 className="text-sm font-semibold text-gray-800">Notes</h3>
                        <ol className="mt-4 space-y-4">
                            {notes.length === 0 && (
                                <li className="text-sm text-gray-500">No notes yet.</li>
                            )}
                            {notes.map((note) => (
                                <li key={note.id} className="rounded-md border border-gray-200 p-4">
                                    <p className="text-xs text-gray-500">
                                        {note.author} · {note.created_at}
                                    </p>
                                    {editing === note.id ? (
                                        <form onSubmit={saveNote} className="mt-2 space-y-2">
                                            <textarea
                                                value={editForm.data.body}
                                                onChange={(event) => editForm.setData('body', event.target.value)}
                                                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                rows={3}
                                            />
                                            <InputError message={editForm.errors.body} />
                                            <div className="flex gap-2">
                                                <PrimaryButton disabled={editForm.processing}>Save note</PrimaryButton>
                                                <button type="button" className="text-sm text-gray-600 underline" onClick={() => setEditing(null)}>
                                                    Cancel
                                                </button>
                                            </div>
                                        </form>
                                    ) : (
                                        <p className="mt-2 whitespace-pre-wrap text-sm text-gray-800">{note.body}</p>
                                    )}
                                    {note.can_edit && editing !== note.id && (
                                        <div className="mt-3 flex gap-3 text-sm">
                                            <button
                                                type="button"
                                                className="text-gray-600 underline"
                                                onClick={() => {
                                                    setEditing(note.id);
                                                    editForm.setData('body', note.body);
                                                }}
                                            >
                                                Edit
                                            </button>
                                            <button type="button" className="text-red-700 underline" onClick={() => destroyNote(note)}>
                                                Delete
                                            </button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ol>

                        <form onSubmit={addNote} className="mt-6 space-y-2">
                            <label htmlFor="body" className="text-sm font-medium text-gray-700">
                                Add a note
                            </label>
                            <textarea
                                id="body"
                                value={noteForm.data.body}
                                onChange={(event) => noteForm.setData('body', event.target.value)}
                                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                rows={3}
                            />
                            <InputError message={noteForm.errors.body} />
                            <PrimaryButton disabled={noteForm.processing}>Add note</PrimaryButton>
                        </form>
                    </div>
                </div>
            </div>
        </WriterLayout>
    );
}
