import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import WriterLayout from '@/Layouts/WriterLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Import() {
    const { data, setData, post, processing, errors } = useForm({
        file: null,
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('writer.contacts.import.store'), { forceFormData: true });
    };

    return (
        <WriterLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Import contacts
                </h2>
            }
        >
            <Head title="Import contacts" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl bg-white p-6 shadow-sm sm:rounded-lg sm:p-8">
                    <p className="text-sm text-gray-600">
                        Upload a CSV of up to 1 MB. The header row must include
                        name. Optional columns are email, phone, company,
                        job_title, website, location, and tags. Separate several
                        tags with a semicolon. Duplicate emails are skipped.
                        Invalid rows are reported and are not imported.
                    </p>

                    <form onSubmit={submit} className="mt-6 space-y-4">
                        <input
                            type="file"
                            accept=".csv,.txt,text/csv"
                            onChange={(event) =>
                                setData('file', event.target.files[0] ?? null)
                            }
                            className="block w-full text-sm text-gray-700"
                        />
                        <InputError message={errors.file} />
                        <div className="flex items-center gap-4">
                            <PrimaryButton disabled={processing || !data.file}>
                                {processing ? 'Importing…' : 'Upload CSV'}
                            </PrimaryButton>
                            <Link
                                href={route('writer.contacts.index')}
                                className="text-sm text-gray-600 underline"
                            >
                                Cancel
                            </Link>
                        </div>
                    </form>
                </div>
            </div>
        </WriterLayout>
    );
}
