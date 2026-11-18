import WriterLayout from '@/Layouts/WriterLayout';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Dashboard({ tenantName }) {
    const user = usePage().props.auth.user;

    return (
        <WriterLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    {tenantName} dashboard
                </h2>
            }
        >
            <Head title="Writer dashboard" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            You're logged in as {user.name}.
                            <Link
                                href={route('writer.posts.index')}
                                className="ms-2 text-sm text-gray-600 underline"
                            >
                                Manage posts
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </WriterLayout>
    );
}
