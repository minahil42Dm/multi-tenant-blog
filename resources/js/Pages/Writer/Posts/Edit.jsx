import WriterLayout from '@/Layouts/WriterLayout';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Edit({ post }) {
    return (
        <WriterLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Edit post
                </h2>
            }
        >
            <Head title="Edit post" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <Form post={post} />
                    </div>
                </div>
            </div>
        </WriterLayout>
    );
}
