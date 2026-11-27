import WriterLayout from '@/Layouts/WriterLayout';
import Form from '@/Pages/Writer/Users/Form';
import { Head } from '@inertiajs/react';

export default function Create({ roles }) {
    return (
        <WriterLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Add user
                </h2>
            }
        >
            <Head title="Add user" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <Form mode="create" roles={roles} />
                    </div>
                </div>
            </div>
        </WriterLayout>
    );
}
