import DangerButton from '@/Components/DangerButton';
import WriterLayout from '@/Layouts/WriterLayout';
import Form from '@/Pages/Writer/Users/Form';
import { Head, router } from '@inertiajs/react';

export default function Edit({ user, roles }) {
    const destroy = () => {
        if (confirm(`Delete ${user.name}?`)) {
            router.delete(route('writer.users.destroy', user.id));
        }
    };

    return (
        <WriterLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Edit {user.name}
                    </h2>
                    {user.can_delete && (
                        <DangerButton type="button" onClick={destroy}>
                            Delete
                        </DangerButton>
                    )}
                </div>
            }
        >
            <Head title={`Edit ${user.name}`} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                    {user.post_count > 0 && (
                        <p className="text-sm text-gray-600">
                            This user has {user.post_count}{' '}
                            {user.post_count === 1 ? 'post' : 'posts'}. Delete
                            those posts before deleting the user.
                        </p>
                    )}
                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <Form mode="edit" user={user} roles={roles} />
                    </div>
                </div>
            </div>
        </WriterLayout>
    );
}
