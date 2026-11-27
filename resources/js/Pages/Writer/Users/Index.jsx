import DangerButton from '@/Components/DangerButton';
import PrimaryButton from '@/Components/PrimaryButton';
import WriterLayout from '@/Layouts/WriterLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';

export default function Index({ users }) {
    const success = usePage().props.flash?.success;
    const userError = usePage().props.errors?.user;
    const canManageUsers = usePage().props.auth?.can?.manageUsers === true;

    const destroy = (user) => {
        if (confirm(`Delete ${user.name}?`)) {
            router.delete(route('writer.users.destroy', user.id));
        }
    };

    return (
        <WriterLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Users
                    </h2>
                    {canManageUsers && (
                        <Link href={route('writer.users.create')}>
                            <PrimaryButton>Add user</PrimaryButton>
                        </Link>
                    )}
                </div>
            }
        >
            <Head title="Users" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    {success && (
                        <div className="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-700">
                            {success}
                        </div>
                    )}
                    {userError && (
                        <div className="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-700">
                            {userError}
                        </div>
                    )}

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    {['Name', 'Email', 'Role', 'Added', 'Actions'].map(
                                        (heading) => (
                                            <th
                                                key={heading}
                                                className={`px-6 py-3 text-xs font-medium uppercase tracking-wider text-gray-500 ${
                                                    heading === 'Actions'
                                                        ? 'text-right'
                                                        : 'text-left'
                                                }`}
                                            >
                                                {heading}
                                            </th>
                                        ),
                                    )}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {users.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-6 py-4 text-sm text-gray-500"
                                        >
                                            No users yet.
                                        </td>
                                    </tr>
                                )}
                                {users.map((user) => (
                                    <tr key={user.id}>
                                        <td className="px-6 py-4 text-sm text-gray-900">
                                            {user.name}
                                            {user.is_self ? ' (you)' : ''}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-900">
                                            {user.email}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-700">
                                            {user.role_label}
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-500">
                                            {user.created_at}
                                        </td>
                                        <td className="space-x-3 px-6 py-4 text-right text-sm">
                                            {user.role === 'writer' && !user.is_self && (
                                                <button
                                                    type="button"
                                                    className="text-amber-700 underline"
                                                    onClick={() =>
                                                        router.post(
                                                            route(
                                                                'writer.users.impersonate',
                                                                user.id,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    Impersonate
                                                </button>
                                            )}
                                            <Link
                                                href={route(
                                                    'writer.users.edit',
                                                    user.id,
                                                )}
                                                className="text-gray-600 underline"
                                            >
                                                Edit
                                            </Link>
                                            {user.can_delete && (
                                                <DangerButton
                                                    type="button"
                                                    onClick={() => destroy(user)}
                                                >
                                                    Delete
                                                </DangerButton>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </WriterLayout>
    );
}
