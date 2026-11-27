import WriterLayout from '@/Layouts/WriterLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

export default function ImportResult({ import: result }) {
    const success = usePage().props.flash?.success;
    const summary = result.summary ?? { created: 0, skipped: 0, failed: 0, errors: [] };
    const pending = result.status === 'pending';

    useEffect(() => {
        if (!pending) {
            return undefined;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['import'], preserveScroll: true });
        }, 2000);

        return () => window.clearInterval(timer);
    }, [pending]);

    return (
        <WriterLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Import summary
                </h2>
            }
        >
            <Head title="Import summary" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl space-y-4 sm:px-6 lg:px-8">
                    {success && (
                        <div className="rounded-md bg-green-50 p-4 text-sm text-green-700">
                            {success}
                        </div>
                    )}

                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <p className="text-sm text-gray-600">
                            {result.filename} is {result.status}.
                        </p>
                        {pending && (
                            <p className="mt-2 text-sm text-amber-700">
                                Waiting for the queue worker. This page checks again every few seconds.
                            </p>
                        )}
                        <dl className="mt-4 grid grid-cols-3 gap-4 text-center">
                            {[
                                ['Imported', summary.created],
                                ['Skipped', summary.skipped],
                                ['Failed', summary.failed],
                            ].map(([label, value]) => (
                                <div key={label} className="rounded-md bg-gray-50 p-4">
                                    <dt className="text-xs uppercase tracking-wider text-gray-500">
                                        {label}
                                    </dt>
                                    <dd className="mt-1 text-2xl font-semibold text-gray-900">
                                        {value ?? 0}
                                    </dd>
                                </div>
                            ))}
                        </dl>

                        {summary.errors?.length > 0 && (
                            <ul className="mt-6 space-y-2 text-sm text-red-700">
                                {summary.errors.map((error, index) => (
                                    <li key={index}>
                                        {error.row > 0 ? `Row ${error.row}: ` : ''}
                                        {error.message}
                                    </li>
                                ))}
                            </ul>
                        )}

                        <Link
                            href={route('writer.contacts.index')}
                            className="mt-6 inline-block text-sm text-gray-600 underline"
                        >
                            Back to contacts
                        </Link>
                    </div>
                </div>
            </div>
        </WriterLayout>
    );
}
