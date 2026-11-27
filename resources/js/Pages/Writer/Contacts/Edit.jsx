import WriterLayout from '@/Layouts/WriterLayout';
import { Head } from '@inertiajs/react';
import Form from './Form';

export default function Edit({ contact, tags }) {
    return (
        <WriterLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Edit {contact.name}
                </h2>
            }
        >
            <Head title={`Edit ${contact.name}`} />

            <div className="py-12">
                <div className="mx-auto max-w-3xl bg-white p-6 shadow-sm sm:rounded-lg sm:p-8">
                    <Form mode="edit" contact={contact} tags={tags} />
                </div>
            </div>
        </WriterLayout>
    );
}
