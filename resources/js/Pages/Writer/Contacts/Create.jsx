import WriterLayout from '@/Layouts/WriterLayout';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Create({ tags }) {
    return (
        <WriterLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Add contact
                </h2>
            }
        >
            <Head title="Add contact" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl bg-white p-6 shadow-sm sm:rounded-lg sm:p-8">
                    <Form mode="create" tags={tags} />
                </div>
            </div>
        </WriterLayout>
    );
}
